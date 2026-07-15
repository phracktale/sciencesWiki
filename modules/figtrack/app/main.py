"""API figTrack (FastAPI) : analyse forensique d'une image ou des figures d'un PDF.

Outil d'INVESTIGATION ASSISTÉE, human-in-the-loop : produit des indices techniques localisés et
neutres, jamais une conclusion de fraude (cf. SPECS §1, §4).
"""

from __future__ import annotations

import os
from datetime import datetime, timezone

from fastapi import Depends, FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse, Response
from sqlalchemy import select
from sqlalchemy.orm import Session

from . import detectors, extract, report, storage
from .auth import require_analyst
from .config import MAX_UPLOAD_BYTES, NEAR_DUP_HAMMING
from .db import get_db
from .models import Analysis, Asset, Document, Finding

app = FastAPI(title="figTrack", version=detectors.DETECTOR_VERSION)

_TRIAGE_ORDER = ["T0", "T1", "T2", "T3"]


def _ti(t: str) -> int:
    return _TRIAGE_ORDER.index(t) if t in _TRIAGE_ORDER else 0


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "module": "figtrack", "version": detectors.DETECTOR_VERSION}


@app.get("/")
def root(_: object = Depends(require_analyst)) -> dict:
    return {
        "module": "figtrack",
        "version": detectors.DETECTOR_VERSION,
        "detectors": [
            "perceptual_hash",
            "panel_duplication",
            "copy_move_keypoint",
            "noise_inconsistency",
            "contrast_clipping",
        ],
        "inputs": ["image", "pdf"],
        "note": "Indices techniques neutres, human-in-the-loop. Aucune conclusion de fraude.",
    }


# ---------------------------------------------------------------------------
# Analyse d'une image isolée
# ---------------------------------------------------------------------------
@app.post("/analyses")
async def create_analysis(request: Request, db: Session = Depends(get_db)) -> JSONResponse:
    """Analyse une IMAGE transmise en corps brut (query : filename, title, doi)."""
    user = require_analyst(request)
    data = await _read_body(request)
    params = request.query_params

    sha = storage.save_bytes(data)
    path = storage.path_for(sha)
    pil = _load_or_422(path)
    width, height = pil.size
    hashes = detectors.perceptual_hashes(pil)

    asset = Asset(
        sha256=sha,
        filename=(params.get("filename") or "image")[:255],
        mime=(request.headers.get("content-type") or "application/octet-stream")[:64],
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

    analysis, _ = _analyze_asset(db, asset, path, hashes["phash"], params.get("title") or None, params.get("doi") or None, user.username)
    db.commit()
    return JSONResponse(status_code=201, content=_serialize_analysis(db, analysis, asset))


# ---------------------------------------------------------------------------
# Analyse d'un DOCUMENT (PDF) : extraction des figures + analyse de chacune
# ---------------------------------------------------------------------------
@app.post("/documents")
async def create_document(request: Request, db: Session = Depends(get_db)) -> JSONResponse:
    """Extrait les figures d'un PDF (corps brut) et analyse chacune, y compris la RÉUTILISATION
    entre figures d'un même document (signal clé)."""
    user = require_analyst(request)
    data = await _read_body(request)
    params = request.query_params

    sha = storage.save_bytes(data)
    try:
        figures, pages = extract.extract_figures(data)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail="PDF illisible ou protégé.") from exc

    doc = Document(
        sha256=sha,
        filename=(params.get("filename") or "document.pdf")[:255],
        title=params.get("title") or None,
        doi=params.get("doi") or None,
        pages=pages,
        figure_count=0,
        requested_by=user.username,
    )
    db.add(doc)
    db.flush()

    # 1) On stocke TOUTES les figures d'abord, pour qu'une figure soit comparée à ses sœurs.
    prepared: list[tuple[Asset, str, str]] = []
    for idx, fig in enumerate(figures, start=1):
        try:
            fsha = storage.save_bytes(fig["bytes"])
            fpath = storage.path_for(fsha)
            pil = detectors.load_pil(fpath)
        except Exception:  # noqa: BLE001
            continue
        w, h = pil.size
        hashes = detectors.perceptual_hashes(pil)
        asset = Asset(
            sha256=fsha,
            filename=f"{doc.filename}#fig{idx}",
            mime=f"image/{fig.get('ext', 'png')}"[:64],
            size=len(fig["bytes"]),
            width=w,
            height=h,
            phash=hashes["phash"],
            dhash=hashes["dhash"],
            ahash=hashes["ahash"],
            document_id=doc.id,
            page=fig.get("page"),
            figure_index=idx,
            requested_by=user.username,
        )
        db.add(asset)
        prepared.append((asset, fpath, hashes["phash"]))
    db.flush()

    # 2) Analyse de chaque figure (les sœurs sont désormais des candidats de comparaison).
    triage_max = "T0"
    for asset, fpath, phash in prepared:
        analysis, tri = _analyze_asset(db, asset, fpath, phash, asset.filename, doc.doi, user.username)
        if _ti(tri) > _ti(triage_max):
            triage_max = tri

    doc.triage_max = triage_max
    doc.figure_count = len(prepared)
    db.commit()
    return JSONResponse(status_code=201, content=_serialize_document(db, doc))


@app.get("/documents/{document_id}")
def read_document(document_id: str, request: Request, db: Session = Depends(get_db)) -> dict:
    require_analyst(request)
    doc = db.get(Document, document_id)
    if doc is None:
        raise HTTPException(status_code=404, detail="Document introuvable.")
    return _serialize_document(db, doc)


@app.get("/me/documents")
def my_documents(request: Request, db: Session = Depends(get_db)) -> dict:
    user = require_analyst(request)
    rows = db.execute(
        select(Document).where(Document.requested_by == user.username).order_by(Document.created_at.desc()).limit(200)
    ).scalars()
    return {
        "items": [
            {
                "id": d.id,
                "title": d.title or d.filename,
                "doi": d.doi,
                "pages": d.pages,
                "figure_count": d.figure_count,
                "triage_max": d.triage_max,
                "created_at": d.created_at.isoformat(),
            }
            for d in rows
        ]
    }


# ---------------------------------------------------------------------------
# Image d'un asset (vignette) — servie via le proxy web authentifié
# ---------------------------------------------------------------------------
@app.get("/assets/{asset_id}/image")
def asset_image(asset_id: str, request: Request, db: Session = Depends(get_db)) -> Response:
    require_analyst(request)
    asset = db.get(Asset, asset_id)
    if asset is None:
        raise HTTPException(status_code=404, detail="Asset introuvable.")
    path = storage.path_for(asset.sha256)
    if not os.path.exists(path):
        raise HTTPException(status_code=404, detail="Fichier absent.")
    with open(path, "rb") as fh:
        return Response(content=fh.read(), media_type=asset.mime or "application/octet-stream")


# ---------------------------------------------------------------------------
# Recherche de réutilisation dans le corpus (kNN perceptuel)
# ---------------------------------------------------------------------------
@app.get("/assets/{asset_id}/similar")
def similar_assets(asset_id: str, request: Request, db: Session = Depends(get_db)) -> dict:
    require_analyst(request)
    asset = db.get(Asset, asset_id)
    if asset is None:
        raise HTTPException(status_code=404, detail="Asset introuvable.")
    k = _clamp_int(request.query_params.get("k"), default=10, lo=1, hi=50)
    ref = {"phash": asset.phash, "dhash": asset.dhash}
    return {"items": _rank_corpus(db, ref, k, exclude_sha=asset.sha256, exclude_id=asset.id)}


@app.post("/corpus/search")
async def corpus_search(request: Request, db: Session = Depends(get_db)) -> dict:
    """Recherche les figures du corpus les plus proches d'une IMAGE transmise en corps brut."""
    require_analyst(request)
    data = await _read_body(request)
    sha = storage.save_bytes(data)
    pil = _load_or_422(storage.path_for(sha))
    hashes = detectors.perceptual_hashes(pil)
    k = _clamp_int(request.query_params.get("k"), default=10, lo=1, hi=50)
    return {"items": _rank_corpus(db, hashes, k, exclude_sha=sha, exclude_id=None)}


def _rank_corpus(db: Session, ref: dict, k: int, exclude_sha: str, exclude_id: str | None) -> list[dict]:
    ranked: list[tuple[int, Asset]] = []
    for a in db.execute(select(Asset).where(Asset.sha256 != exclude_sha)).scalars():
        if exclude_id is not None and a.id == exclude_id:
            continue
        dist = detectors.best_distance(ref, {"phash": a.phash, "dhash": a.dhash})
        if dist is not None:
            ranked.append((dist, a))
    ranked.sort(key=lambda t: t[0])
    return [
        {
            "asset_id": a.id,
            "document_id": a.document_id,
            "filename": a.filename,
            "page": a.page,
            "figure_index": a.figure_index,
            "distance": dist,
            "close": dist <= NEAR_DUP_HAMMING,
        }
        for dist, a in ranked[:k]
    ]


# ---------------------------------------------------------------------------
# Rapport PDF « dossier de preuve »
# ---------------------------------------------------------------------------
@app.get("/documents/{document_id}/report.pdf")
def document_report(document_id: str, request: Request, db: Session = Depends(get_db)) -> Response:
    require_analyst(request)
    doc = db.get(Document, document_id)
    if doc is None:
        raise HTTPException(status_code=404, detail="Document introuvable.")
    data = _serialize_document(db, doc)
    header = {
        "kind": "document (PDF)",
        "title": data["title"],
        "filename": data["filename"],
        "triage_max": data["triage_max"],
        "figure_count": data["figure_count"],
        "created_at": data["created_at"],
    }
    pdf = report.build_report(header, data["figures"], doc.sha256)
    return _pdf_response(pdf, f"figtrack-{doc.id}.pdf")


@app.get("/analyses/{analysis_id}/report.pdf")
def analysis_report(analysis_id: str, request: Request, db: Session = Depends(get_db)) -> Response:
    require_analyst(request)
    analysis = db.get(Analysis, analysis_id)
    if analysis is None:
        raise HTTPException(status_code=404, detail="Analyse introuvable.")
    asset = db.get(Asset, analysis.asset_id)
    data = _serialize_analysis(db, analysis, asset)
    header = {
        "kind": "image",
        "title": data["title"],
        "filename": (asset.filename if asset else None),
        "triage_max": data["triage_max"],
        "figure_count": 1,
        "created_at": data["created_at"],
    }
    figure = {
        "sha256": asset.sha256 if asset else None,
        "filename": asset.filename if asset else None,
        "page": None,
        "figure_index": 1,
        "triage_max": data["triage_max"],
        "summary": data["summary"],
        "findings": data["findings"],
    }
    pdf = report.build_report(header, [figure], asset.sha256 if asset else "")
    return _pdf_response(pdf, f"figtrack-{analysis.id}.pdf")


def _pdf_response(pdf_bytes: bytes, filename: str) -> Response:
    return Response(
        content=pdf_bytes,
        media_type="application/pdf",
        headers={"Content-Disposition": f'inline; filename="{filename}"'},
    )


def _clamp_int(value: str | None, default: int, lo: int, hi: int) -> int:
    try:
        return max(lo, min(hi, int(value)))
    except (TypeError, ValueError):
        return default


# ---------------------------------------------------------------------------
# Consultation / revue
# ---------------------------------------------------------------------------
@app.get("/analyses/{analysis_id}")
def read_analysis(analysis_id: str, request: Request, db: Session = Depends(get_db)) -> dict:
    require_analyst(request)
    analysis = db.get(Analysis, analysis_id)
    if analysis is None:
        raise HTTPException(status_code=404, detail="Analyse introuvable.")
    return _serialize_analysis(db, analysis, db.get(Asset, analysis.asset_id))


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
    finding.rationale = str(body.get("rationale", "")).strip() or None
    finding.reviewed_by = user.username
    finding.reviewed_at = datetime.now(timezone.utc)
    db.commit()
    return _serialize_finding(finding)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
async def _read_body(request: Request) -> bytes:
    data = await request.body()
    if not data:
        raise HTTPException(status_code=400, detail="Fichier vide.")
    if len(data) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="Fichier trop volumineux.")
    return data


def _load_or_422(path: str):
    try:
        return detectors.load_pil(path)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail="Image illisible ou format non supporté.") from exc


def _analyze_asset(
    db: Session, asset: Asset, path: str, phash: str, title: str | None, doi: str | None, username: str
) -> tuple[Analysis, str]:
    """Exécute les détecteurs sur un asset, persiste l'analyse et ses findings. Renvoie (analysis, triage)."""
    candidates = [
        {"id": a.id, "phash": a.phash, "filename": a.filename}
        for a in db.execute(select(Asset).where(Asset.id != asset.id, Asset.sha256 != asset.sha256)).scalars()
    ]

    raw: list[dict] = []
    raw += detectors.near_duplicate_findings(phash, candidates, NEAR_DUP_HAMMING)
    raw += detectors.panel_duplication_findings(path)
    raw += detectors.copy_move_findings(path)
    raw += detectors.splice_findings(path)
    raw += detectors.contrast_findings(path)

    analysis = Analysis(asset_id=asset.id, title=title, doi=doi, status="completed", requested_by=username)
    db.add(analysis)
    db.flush()

    triage_max = "T0"
    for f in raw:
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
        if _ti(f.get("triage_level", "T1")) > _ti(triage_max):
            triage_max = f.get("triage_level", "T1")

    analysis.triage_max = triage_max
    analysis.summary = _summary(raw, triage_max)
    return analysis, triage_max


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


def _serialize_document(db: Session, doc: Document) -> dict:
    assets = db.execute(
        select(Asset).where(Asset.document_id == doc.id).order_by(Asset.figure_index)
    ).scalars().all()
    figures = []
    for a in assets:
        analysis = db.execute(select(Analysis).where(Analysis.asset_id == a.id)).scalars().first()
        findings = []
        triage = "T0"
        summary = None
        if analysis is not None:
            findings = [
                _serialize_finding(f)
                for f in db.execute(
                    select(Finding).where(Finding.analysis_id == analysis.id).order_by(Finding.triage_level.desc())
                ).scalars()
            ]
            triage = analysis.triage_max
            summary = analysis.summary
        figures.append(
            {
                "asset_id": a.id,
                "sha256": a.sha256,
                "page": a.page,
                "figure_index": a.figure_index,
                "filename": a.filename,
                "width": a.width,
                "height": a.height,
                "analysis_id": analysis.id if analysis else None,
                "triage_max": triage,
                "summary": summary,
                "findings": findings,
            }
        )
    return {
        "id": doc.id,
        "filename": doc.filename,
        "title": doc.title,
        "doi": doc.doi,
        "pages": doc.pages,
        "figure_count": doc.figure_count,
        "triage_max": doc.triage_max,
        "created_at": doc.created_at.isoformat(),
        "figures": figures,
    }
