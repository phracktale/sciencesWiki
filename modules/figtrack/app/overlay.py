"""Rendu d'un calque de visualisation des zones dupliquées.

Chaque finding localisé (copier-déplacer, duplication de panneau) a une région SOURCE et une
région CIBLE : on les entoure d'un rectangle de MÊME couleur. Chaque duplication distincte reçoit
une COULEUR DIFFÉRENTE (même palette que le front → légende cohérente).
"""

from __future__ import annotations

import io

from PIL import Image, ImageDraw, ImageFont

# Palette partagée avec le front (RGB). Une entrée = une duplication distincte.
PALETTE = [
    (220, 38, 38),
    (37, 99, 235),
    (22, 163, 74),
    (217, 119, 6),
    (147, 51, 234),
    (8, 145, 178),
    (219, 39, 119),
    (202, 138, 4),
]


def _rect(region: dict | None):
    if not region:
        return None
    try:
        x, y = int(region["x"]), int(region["y"])
        return x, y, x + int(region["width"]), y + int(region["height"])
    except (KeyError, TypeError, ValueError):
        return None


def render_overlay(image_path: str, findings: list[dict]) -> bytes:
    """Retourne un PNG de l'image avec les zones dupliquées encadrées (1 couleur/duplication)."""
    im = Image.open(image_path).convert("RGB")
    draw = ImageDraw.Draw(im)
    w, h = im.size
    line = max(2, round(min(w, h) / 200))
    try:
        font = ImageFont.load_default()
    except Exception:  # noqa: BLE001
        font = None

    idx = 0
    for f in findings:
        src = _rect(f.get("source_region"))
        tgt = _rect(f.get("target_region"))
        if src is None or tgt is None:
            continue  # findings sans localisation (ex. quasi-doublon global) : pas de rectangle
        color = PALETTE[idx % len(PALETTE)]
        label = str(idx + 1)
        for box in (src, tgt):
            draw.rectangle(box, outline=color, width=line)
            # Pastille numérotée au coin haut-gauche pour relier à la légende.
            tx, ty = box[0] + 1, max(0, box[1] - 12)
            if font is not None:
                draw.rectangle([tx, ty, tx + 11, ty + 11], fill=color)
                draw.text((tx + 3, ty), label, fill=(255, 255, 255), font=font)
        idx += 1

    buf = io.BytesIO()
    im.save(buf, "PNG")
    return buf.getvalue()
