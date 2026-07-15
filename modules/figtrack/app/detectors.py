"""Détecteurs forensiques SANS entraînement (CV classique, CPU) — v1 figTrack.

Chaque détecteur produit des INDICES techniques localisés et neutres, jamais une conclusion de
fraude (cf. SPECS §4.1, §29.1). Vocabulaire descriptif imposé : « similarité inhabituelle »,
« région potentiellement dupliquée », etc.
"""

from __future__ import annotations

import cv2
import imagehash
import numpy as np
from PIL import Image

from . import segment

DETECTOR_VERSION = "0.2.0"


# ---------------------------------------------------------------------------
# Empreintes perceptuelles
# ---------------------------------------------------------------------------
def load_pil(path: str) -> Image.Image:
    return Image.open(path).convert("RGB")


def perceptual_hashes(img: Image.Image) -> dict[str, str]:
    return {
        "phash": str(imagehash.phash(img)),
        "dhash": str(imagehash.dhash(img)),
        "ahash": str(imagehash.average_hash(img)),
    }


def hamming(h1: str | None, h2: str | None) -> int | None:
    if not h1 or not h2:
        return None
    try:
        return imagehash.hex_to_hash(h1) - imagehash.hex_to_hash(h2)
    except Exception:  # noqa: BLE001
        return None


def near_duplicate_findings(phash: str, candidates: list[dict], threshold: int) -> list[dict]:
    """Compare l'empreinte à un corpus d'assets existants (id, phash, filename)."""
    out: list[dict] = []
    for cand in candidates:
        dist = hamming(phash, cand.get("phash"))
        if dist is None or dist > threshold:
            continue
        exact = dist == 0
        out.append(
            {
                "detector": "perceptual_hash",
                "anomaly_type": "exact_duplicate" if exact else "near_duplicate",
                "evidence_level": "E3" if exact else "E2",
                "triage_level": "T2",
                "raw_score": 1.0 - dist / 64.0,
                "calibrated_score": 1.0 - dist / 64.0,
                "related_asset_id": cand.get("id"),
                "description": (
                    "Image perceptuellement identique à une autre image déjà analysée "
                    f"(« {cand.get('filename', '?')} »)."
                    if exact
                    else "Similarité perceptuelle inhabituelle avec une autre image déjà analysée "
                    f"(« {cand.get('filename', '?')} », distance de Hamming {dist}/64)."
                ),
                "limitations": [
                    "Une réutilisation peut être légitime (contrôle déclaré, même figure citée).",
                    "Le hachage perceptuel ne localise pas la région concernée.",
                ],
            }
        )
    return out


# ---------------------------------------------------------------------------
# Copier-déplacer interne (points d'intérêt ORB + RANSAC)
# ---------------------------------------------------------------------------
def _bbox(points: np.ndarray) -> dict:
    xs, ys = points[:, 0], points[:, 1]
    x, y = float(xs.min()), float(ys.min())
    return {
        "x": int(x),
        "y": int(y),
        "width": int(xs.max() - x),
        "height": int(ys.max() - y),
    }


def copy_move_findings(path: str) -> list[dict]:
    img = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if img is None:
        return []
    # Réduit les très grandes images (perf + stabilité des appariements).
    h, w = img.shape[:2]
    scale = 1.0
    if max(h, w) > 1600:
        scale = 1600.0 / max(h, w)
        img = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)

    orb = cv2.ORB_create(nfeatures=4000)
    kp, des = orb.detectAndCompute(img, None)
    if des is None or len(kp) < 20:
        return []

    matcher = cv2.BFMatcher(cv2.NORM_HAMMING)
    # k=3 pour ignorer l'auto-appariement (index identique) et garder le plus proche voisin réel.
    knn = matcher.knnMatch(des, des, k=3)

    src_pts: list[tuple[float, float]] = []
    dst_pts: list[tuple[float, float]] = []
    for group in knn:
        for m in group:
            if m.queryIdx == m.trainIdx:
                continue
            p1 = kp[m.queryIdx].pt
            p2 = kp[m.trainIdx].pt
            spatial = ((p1[0] - p2[0]) ** 2 + (p1[1] - p2[1]) ** 2) ** 0.5
            # Descripteurs très proches MAIS éloignés spatialement → copie possible.
            if m.distance <= 32 and spatial >= 40:
                src_pts.append(p1)
                dst_pts.append(p2)
            break  # un seul (plus proche non-self) par point

    if len(src_pts) < 12:
        return []

    src = np.float32(src_pts)
    dst = np.float32(dst_pts)
    matrix, inliers = cv2.estimateAffinePartial2D(
        src, dst, method=cv2.RANSAC, ransacReprojThreshold=5.0
    )
    if matrix is None or inliers is None:
        return []
    mask = inliers.ravel() == 1
    n_inliers = int(mask.sum())
    if n_inliers < 12:
        return []

    src_in = src[mask] / scale
    dst_in = dst[mask] / scale
    rotation = float(np.degrees(np.arctan2(matrix[1, 0], matrix[0, 0])))
    scale_est = float(np.hypot(matrix[0, 0], matrix[1, 0]))
    strong = n_inliers >= 25

    return [
        {
            "detector": "copy_move_keypoint",
            "anomaly_type": "internal_duplication",
            "evidence_level": "E3" if strong else "E2",
            "triage_level": "T2",
            "raw_score": min(1.0, n_inliers / 60.0),
            "calibrated_score": min(1.0, n_inliers / 60.0),
            "source_region": _bbox(src_in),
            "target_region": _bbox(dst_in),
            "estimated_transform": {
                "type": "affine_partial",
                "rotation_deg": round(rotation, 1),
                "scale": round(scale_est, 3),
            },
            "description": (
                "Deux régions de la même image présentent une correspondance géométrique "
                f"cohérente ({n_inliers} points appariés, rotation estimée {rotation:.0f}°) : "
                "région potentiellement dupliquée (copier-déplacer) à examiner."
            ),
            "limitations": [
                "Les textures biologiques répétitives (cellules, bandes) peuvent produire des appariements naturels.",
                "Résultat à confirmer visuellement (régions source/cible côte à côte).",
            ],
        }
    ]


# ---------------------------------------------------------------------------
# Duplication de panneaux (segmentation + comparaison par paire, transforms discrètes)
# ---------------------------------------------------------------------------
_PANEL_TRANSFORMS = {
    "none": lambda a: a,
    "miroir horizontal": np.fliplr,
    "miroir vertical": np.flipud,
    "rotation 180°": lambda a: np.rot90(a, 2),
}


def panel_duplication_findings(path: str, threshold: int = 8, max_findings: int = 8) -> list[dict]:
    gray = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if gray is None:
        return []
    panels = segment.segment_panels(gray)
    if len(panels) < 2:
        return []

    infos: list[dict | None] = []
    for (x, y, w, h) in panels:
        crop = gray[y : y + h, x : x + w]
        # Ignore les panneaux quasi-uniformes (fond, aplat) : hachage non discriminant.
        if crop.size == 0 or float(crop.std()) < 8.0:
            infos.append(None)
            continue
        infos.append(
            {
                "box": {"x": int(x), "y": int(y), "width": int(w), "height": int(h)},
                "base": imagehash.phash(Image.fromarray(crop)),
                "variants": {name: imagehash.phash(Image.fromarray(fn(crop))) for name, fn in _PANEL_TRANSFORMS.items()},
            }
        )

    findings: list[dict] = []
    n = len(panels)
    for i in range(n):
        if infos[i] is None:
            continue
        for j in range(i + 1, n):
            if infos[j] is None:
                continue
            best_name, best_d = "none", 999
            for name, ph in infos[i]["variants"].items():
                d = ph - infos[j]["base"]
                if d < best_d:
                    best_d, best_name = d, name
            if best_d > threshold:
                continue
            transform = "" if best_name == "none" else f" après {best_name}"
            findings.append(
                {
                    "detector": "panel_duplication",
                    "anomaly_type": "panel_duplication",
                    "evidence_level": "E3" if best_d <= 4 else "E2",
                    "triage_level": "T2",
                    "raw_score": 1.0 - best_d / 64.0,
                    "calibrated_score": 1.0 - best_d / 64.0,
                    "source_region": infos[i]["box"],
                    "target_region": infos[j]["box"],
                    "estimated_transform": None if best_name == "none" else {"type": best_name},
                    "description": (
                        f"Deux panneaux de la figure présentent une forte similarité{transform} "
                        f"(distance de Hamming {best_d}/64) : réutilisation possible d'un panneau, à examiner."
                    ),
                    "limitations": [
                        "Des panneaux de contrôle légitimement identiques peuvent être signalés.",
                        "Segmentation automatique : vérifier le découpage des panneaux.",
                    ],
                }
            )
            if len(findings) >= max_findings:
                return findings
    return findings


# ---------------------------------------------------------------------------
# Splicing / incohérence de bruit (indice FAIBLE — SPECS §16.3)
# ---------------------------------------------------------------------------
def splice_findings(path: str) -> list[dict]:
    gray = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if gray is None:
        return []
    h, w = gray.shape
    gy = gx = 6
    ch, cw = h // gy, w // gx
    if ch < 8 or cw < 8:
        return []

    # Résidu de bruit = image - médiane locale ; on mesure sa dispersion par cellule.
    resid = cv2.absdiff(gray, cv2.medianBlur(gray, 3)).astype(np.float32)
    stds = np.array(
        [float(resid[r * ch : (r + 1) * ch, c * cw : (c + 1) * cw].std()) for r in range(gy) for c in range(gx)]
    )
    med = float(np.median(stds))
    mx = float(stds.max())
    if med <= 0.5 or mx / max(med, 1e-6) < 3.5:
        return []

    return [
        {
            "detector": "noise_inconsistency",
            "anomaly_type": "local_noise_inconsistency",
            "evidence_level": "E1",
            "triage_level": "T1",
            "raw_score": round(min(1.0, (mx / max(med, 1e-6)) / 8.0), 3),
            "calibrated_score": round(min(1.0, (mx / max(med, 1e-6)) / 8.0), 3),
            "description": (
                "Le niveau de bruit local varie fortement entre régions de l'image "
                f"(rapport {mx / max(med, 1e-6):.1f}×) : incohérence compatible avec un raccord, "
                "un collage ou un nettoyage local. Indice FAIBLE."
            ),
            "limitations": [
                "La compression PDF, un fond uni ou une zone saturée expliquent souvent cet écart.",
                "Indice faible : ne constitue jamais seul une alerte prioritaire (SPECS §16.3).",
            ],
        }
    ]


# ---------------------------------------------------------------------------
# Contraste / clipping (indice faible E1)
# ---------------------------------------------------------------------------
def contrast_findings(path: str) -> list[dict]:
    img = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if img is None:
        return []
    total = int(img.size)
    if total == 0:
        return []
    black = float((img <= 2).sum()) / total
    white = float((img >= 253).sum()) / total
    if black < 0.55 and white < 0.55:
        return []
    return [
        {
            "detector": "contrast_clipping",
            "anomaly_type": "contrast_clipping",
            "evidence_level": "E1",
            "triage_level": "T1",
            "raw_score": round(max(black, white), 3),
            "calibrated_score": round(max(black, white), 3),
            "description": (
                f"Proportion élevée de pixels saturés (noirs {black * 100:.0f} %, "
                f"blancs {white * 100:.0f} %) : incohérence locale de traitement possible, "
                "susceptible de masquer des données. Indice faible, à contextualiser."
            ),
            "limitations": [
                "Un fond uniforme ou une modalité binaire peut expliquer ce taux sans anomalie.",
                "Indice faible : ne constitue jamais seul une alerte prioritaire.",
            ],
        }
    ]
