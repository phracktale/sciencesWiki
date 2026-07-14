"""API figTrack (FastAPI) : upload + analyse forensique d'une image, findings, revue humaine.

Outil d'INVESTIGATION ASSISTÉE, human-in-the-loop : produit des indices techniques localisés et
neutres, jamais une conclusion de fraude (cf. SPECS §1, §4).
"""

from __future__ import annotations

from datetime import datetime, timezone

from fastapi import Depends, FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from sqlalchemy import select
from sqlalchemy.orm import Session

from . import detectors, storage
from .auth import require_analyst
from .config import MAX_UPLOAD_BYTES, NEAR_DUP_HAMMING
from .db import get_db
from .models import Analysis, Asset, Finding

app = FastAPI(title="figTrack", version=detectors.DETECTOR_VERSION)

_TRIAGE_ORDER = ["T0", "T1", "T2", "T3"]


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "module": "figtrack", "version": detectors.DETECTOR_VERSION}


@app.get("/")
def root(_: object = Depends(require_analyst)) -> dict:
    return {
        "module": "figtrack",
        "version": detectors.DETECTOR_VERSION,
        "detectors": ["perceptual_hash", "copy_move_keypoint", "contrast_clipping"],
        "note": "Indices techniques neutres, human-in-the-loop. Aucune conclusion de fraude.",
    }


@app.post("/analyses")
async def create_analysis(request: Request, db: Session = Depends(get_db)) -> JSONResponse:
    """Analyse une image transmise en CORPS BRUT (application/octet-stream ou image/*).

    Métadonnées via query params : filename, title, doi. Le corps brut se proxifie proprement
    (contrairement au multipart, dont PHP ne réexpose pas le flux).
    """
    user = require_analyst(request)

    data = await request.body()
    if not data:
        raise HTTPException(status_code=400, detail="Fichier vide.")
    if len(data) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="Fichier trop volumineux.")

    params = request.query_params
    filename = (params.get("filename") or "image")[:255]
    title = params.get("title") or None
    doi = params.get("doi") or None
    mime = (request.headers.get("content-type") or "application/octet-stream")[:64]

    sha = storage.save_bytes(data)
    path = storage.path_for(sha)
    try:
        pil = detectors.load_pil(path)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail="Image illisible ou format non supporté.") from exc

    width, height = pil.size
    hashes = detectors.perceptual_hashes(pil)

    asset = Asset(
        sha256=sha,
        filename=filename,
        mime=mime,
        size=len(data),
        width=width,
        height=height,
        phash=hashes["phash"],
        dhash=hashes["dhash"],
        ahash=hashes["ahash"],
        requested_by=user.username,
    )
    db.add(asset)
    db.flush()

    # Corpus de comparaison : autres images déjà analysées (fichiers distincts).
    candidates = [
        {"id": a.id, "phash": a.phash, "filename": a.filename}
        for a in db.execute(
            select(Asset).where(Asset.id != asset.id, Asset.sha256 != sha)
        ).scalars()
    ]

    raw_findings: list[dict] = []
    raw_findings += detectors.near_duplicate_findings(hashes["phash"], candidates, NEAR_DUP_HAMMING)
    raw_findings += detectors.copy_move_findings(path)
    raw_findings += detectors.contrast_findings(path)

    analysis = Analysis(
        asset_id=asset.id,
        title=(title or None),
        doi=(doi or None),
        status="completed",
        requested_by=user.username,
    )
    db.add(analysis)
    db.flush()

    triage_max = "T0"
    for f in raw_findings:
        db.add(
            Finding(
                analysis_id=analysis.id,
                detector=f["detector"],
                detector_version=detectors.DETECTOR_VERSION,
                anomaly_type=f["anomaly_type"],
                evidence_level=f.get("evidence_level", "E1"),
                triage_level=f.get("triage_level", "T1"),
                raw_score=f.get("raw_score"),
                calibrated_score=f.get("calibrated_score"),
                description=f["description"],
                source_region=f.get("source_region"),
                target_region=f.get("target_region"),
                estimated_transform=f.get("estimated_transform"),
                related_asset_id=f.get("related_asset_id"),
                limitations=f.get("limitations"),
            )
        )
        if _TRIAGE_ORDER.index(f.get("triage_level", "T1")) > _TRIAGE_ORDER.index(triage_max):
            triage_max = f.get("triage_level", "T1")

    analysis.triage_max = triage_max
    analysis.summary = _summary(raw_findings, triage_max)
    db.commit()

    return JSONResponse(status_code=201, content=_serialize_analysis(db, analysis, asset))


@app.get("/analyses/{analysis_id}")
def read_analysis(analysis_id: str, request: Request, db: Session = Depends(get_db)) -> dict:
    require_analyst(request)
    analysis = db.get(Analysis, analysis_id)
    if analysis is None:
        raise HTTPException(status_code=404, detail="Analyse introuvable.")
    asset = db.get(Asset, analysis.asset_id)
    return _serialize_analysis(db, analysis, asset)


@app.get("/me/analyses")
def my_analyses(request: Request, db: Session = Depends(get_db)) -> dict:
    user = require_analyst(request)
    rows = db.execute(
        select(Analysis).where(Analysis.requested_by == user.username).order_by(Analysis.created_at.desc()).limit(200)
    ).scalars()
    items = []
    for a in rows:
        asset = db.get(Asset, a.asset_id)
        items.append(
            {
                "id": a.id,
                "title": a.title or (asset.filename if asset else None),
                "doi": a.doi,
                "triage_max": a.triage_max,
                "status": a.status,
                "created_at": a.created_at.isoformat(),
            }
        )
    return {"items": items}


@app.get("/findings/{finding_id}")
def read_finding(finding_id: str, request: Request, db: Session = Depends(get_db)) -> dict:
    require_analyst(request)
    finding = db.get(Finding, finding_id)
    if finding is None:
        raise HTTPException(status_code=404, detail="Finding introuvable.")
    return _serialize_finding(finding)


@app.post("/findings/{finding_id}/review")
async def review_finding(finding_id: str, request: Request, db: Session = Depends(get_db)) -> dict:
    user = require_analyst(request)
    finding = db.get(Finding, finding_id)
    if finding is None:
        raise HTTPException(status_code=404, detail="Finding introuvable.")

    body = await request.json()
    status = str(body.get("status", "")).strip()
    if status not in {"confirmed", "rejected", "acceptable", "indeterminate"}:
        raise HTTPException(status_code=400, detail="Statut de revue invalide.")

    finding.human_status = status
    finding.rationale = (str(body.get("rationale", "")).strip() or None)
    finding.reviewed_by = user.username
    finding.reviewed_at = datetime.now(timezone.utc)
    db.commit()
    return _serialize_finding(finding)


# ---------------------------------------------------------------------------
def _summary(findings: list[dict], triage_max: str) -> str:
    if not findings:
        return (
            "Aucun indice technique détecté par les détecteurs disponibles. "
            "L'absence de détection ne prouve pas l'intégrité de l'image."
        )
    t2 = sum(1 for f in findings if f.get("triage_level") in ("T2", "T3"))
    t1 = sum(1 for f in findings if f.get("triage_level") == "T1")
    parts = [f"{len(findings)} indice(s) technique(s)"]
    if t2:
        parts.append(f"{t2} à examiner en priorité")
    if t1:
        parts.append(f"{t1} indice(s) faible(s)")
    return (
        ", ".join(parts)
        + ". Ces indices ne constituent pas une conclusion de fraude et requièrent une validation humaine."
    )


def _serialize_finding(f: Finding) -> dict:
    return {
        "id": f.id,
        "analysis_id": f.analysis_id,
        "detector": f.detector,
        "detector_version": f.detector_version,
        "anomaly_type": f.anomaly_type,
        "evidence_level": f.evidence_level,
        "triage_level": f.triage_level,
        "raw_score": f.raw_score,
        "calibrated_score": f.calibrated_score,
        "description": f.description,
        "source_region": f.source_region,
        "target_region": f.target_region,
        "estimated_transform": f.estimated_transform,
        "related_asset_id": f.related_asset_id,
        "limitations": f.limitations,
        "human_status": f.human_status,
        "rationale": f.rationale,
        "reviewed_by": f.reviewed_by,
        "reviewed_at": f.reviewed_at.isoformat() if f.reviewed_at else None,
    }


def _serialize_analysis(db: Session, analysis: Analysis, asset: Asset | None) -> dict:
    findings = db.execute(
        select(Finding).where(Finding.analysis_id == analysis.id).order_by(Finding.triage_level.desc())
    ).scalars()
    return {
        "id": analysis.id,
        "status": analysis.status,
        "title": analysis.title or (asset.filename if asset else None),
        "doi": analysis.doi,
        "triage_max": analysis.triage_max,
        "summary": analysis.summary,
        "created_at": analysis.created_at.isoformat(),
        "asset": None
        if asset is None
        else {
            "id": asset.id,
            "filename": asset.filename,
            "sha256": asset.sha256,
            "width": asset.width,
            "height": asset.height,
            "mime": asset.mime,
        },
        "findings": [_serialize_finding(f) for f in findings],
    }
