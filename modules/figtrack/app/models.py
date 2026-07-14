"""Modèle de données figTrack (tables figtrk_* dans la base SW partagée)."""

from __future__ import annotations

import uuid
from datetime import datetime, timezone

from sqlalchemy import JSON, DateTime, Float, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from .db import Base


def _uid() -> str:
    return uuid.uuid4().hex


def _now() -> datetime:
    return datetime.now(timezone.utc)


class Document(Base):
    """Document source (PDF) dont on extrait les figures. Regroupe des assets (figures)."""

    __tablename__ = "figtrk_document"

    id: Mapped[str] = mapped_column(String(32), primary_key=True, default=_uid)
    sha256: Mapped[str] = mapped_column(String(64), index=True)
    filename: Mapped[str] = mapped_column(String(255))
    title: Mapped[str | None] = mapped_column(String(255), nullable=True)
    doi: Mapped[str | None] = mapped_column(String(255), nullable=True)
    pages: Mapped[int] = mapped_column(Integer, default=0)
    figure_count: Mapped[int] = mapped_column(Integer, default=0)
    triage_max: Mapped[str] = mapped_column(String(4), default="T0")
    requested_by: Mapped[str | None] = mapped_column(String(180), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_now)


class Asset(Base):
    """Image originale conservée (octet + empreinte), avec ses empreintes perceptuelles.

    Peut être une image isolée (document_id null) ou une FIGURE extraite d'un PDF (document_id).
    """

    __tablename__ = "figtrk_asset"

    id: Mapped[str] = mapped_column(String(32), primary_key=True, default=_uid)
    sha256: Mapped[str] = mapped_column(String(64), index=True)
    filename: Mapped[str] = mapped_column(String(255))
    mime: Mapped[str] = mapped_column(String(64))
    size: Mapped[int] = mapped_column(Integer, default=0)
    width: Mapped[int] = mapped_column(Integer, default=0)
    height: Mapped[int] = mapped_column(Integer, default=0)
    phash: Mapped[str | None] = mapped_column(String(64), nullable=True, index=True)
    dhash: Mapped[str | None] = mapped_column(String(64), nullable=True)
    ahash: Mapped[str | None] = mapped_column(String(64), nullable=True)
    # Rattachement à un document PDF (figures extraites) ; null pour une image isolée.
    document_id: Mapped[str | None] = mapped_column(String(32), nullable=True, index=True)
    page: Mapped[int | None] = mapped_column(Integer, nullable=True)
    figure_index: Mapped[int | None] = mapped_column(Integer, nullable=True)
    requested_by: Mapped[str | None] = mapped_column(String(180), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_now)


class Analysis(Base):
    """Exécution d'analyse sur un asset : porte le lot de findings et une synthèse neutre."""

    __tablename__ = "figtrk_analysis"

    id: Mapped[str] = mapped_column(String(32), primary_key=True, default=_uid)
    asset_id: Mapped[str] = mapped_column(String(32), index=True)
    title: Mapped[str | None] = mapped_column(String(255), nullable=True)
    doi: Mapped[str | None] = mapped_column(String(255), nullable=True)
    status: Mapped[str] = mapped_column(String(24), default="completed")
    triage_max: Mapped[str] = mapped_column(String(4), default="T0")
    summary: Mapped[str | None] = mapped_column(Text, nullable=True)
    requested_by: Mapped[str | None] = mapped_column(String(180), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_now)


class Finding(Base):
    """Indice technique localisé produit par UN détecteur (jamais une conclusion de fraude)."""

    __tablename__ = "figtrk_finding"

    id: Mapped[str] = mapped_column(String(32), primary_key=True, default=_uid)
    analysis_id: Mapped[str] = mapped_column(String(32), index=True)
    detector: Mapped[str] = mapped_column(String(48))
    detector_version: Mapped[str] = mapped_column(String(16))
    anomaly_type: Mapped[str] = mapped_column(String(48))
    evidence_level: Mapped[str] = mapped_column(String(4), default="E1")
    triage_level: Mapped[str] = mapped_column(String(4), default="T1")
    raw_score: Mapped[float | None] = mapped_column(Float, nullable=True)
    calibrated_score: Mapped[float | None] = mapped_column(Float, nullable=True)
    description: Mapped[str] = mapped_column(Text)
    source_region: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    target_region: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    estimated_transform: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    related_asset_id: Mapped[str | None] = mapped_column(String(32), nullable=True)
    limitations: Mapped[list | None] = mapped_column(JSON, nullable=True)
    # Décision humaine (human-in-the-loop) : confirmed | rejected | acceptable | indeterminate.
    human_status: Mapped[str | None] = mapped_column(String(24), nullable=True)
    rationale: Mapped[str | None] = mapped_column(Text, nullable=True)
    reviewed_by: Mapped[str | None] = mapped_column(String(180), nullable=True)
    reviewed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=_now)
