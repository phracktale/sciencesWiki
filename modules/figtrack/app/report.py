"""Génération du rapport « dossier de preuve » figTrack (PDF, fpdf2).

Rapport NEUTRE et reproductible (SPECS §31) : métadonnées, empreintes (manifeste), figures avec
vignette, findings hiérarchisés avec détecteur/version/limites, décisions humaines. Formulation
descriptive, jamais accusatoire.
"""

from __future__ import annotations

import hashlib
import os

from fpdf import FPDF
from PIL import Image

from . import storage
from .detectors import DETECTOR_VERSION

_TRIAGE_LABEL = {
    "T0": "T0 - aucun signal prioritaire",
    "T1": "T1 - revue facultative",
    "T2": "T2 - revue recommandee",
    "T3": "T3 - revue prioritaire",
}

# Remplacements pour rester compatible avec les polices « core » latin-1 de fpdf2.
_REPLACE = str.maketrans({"—": "-", "–": "-", "›": ">", "×": "x", "’": "'", "…": "...", "œ": "oe"})


def _s(text: str | None) -> str:
    if not text:
        return ""
    return str(text).translate(_REPLACE).encode("latin-1", "replace").decode("latin-1")


class _Report(FPDF):
    def header(self) -> None:
        self.set_font("Helvetica", "B", 9)
        self.set_text_color(120)
        self.cell(0, 6, "figTrack - rapport d'integrite (indices techniques, validation humaine requise)", align="R")
        self.ln(8)
        self.set_text_color(0)

    def footer(self) -> None:
        self.set_y(-12)
        self.set_font("Helvetica", "I", 8)
        self.set_text_color(120)
        self.cell(0, 6, f"Page {self.page_no()}/{{nb}}", align="C")
        self.set_text_color(0)


def build_report(header: dict, figures: list[dict], source_sha256: str) -> bytes:
    pdf = _Report(orientation="P", unit="mm", format="A4")
    pdf.set_auto_page_break(True, margin=15)
    pdf.alias_nb_pages()
    pdf.add_page()

    pdf.set_font("Helvetica", "B", 16)
    pdf.cell(0, 10, _s(header.get("title") or header.get("filename") or "Analyse figTrack"), ln=1)
    pdf.set_font("Helvetica", "", 10)
    pdf.set_text_color(90)
    meta = [
        f"Fichier : {header.get('filename', '?')}",
        f"Type : {header.get('kind', 'image')}",
        f"Priorite maximale : {_TRIAGE_LABEL.get(header.get('triage_max', 'T0'), header.get('triage_max', 'T0'))}",
        f"Figures analysees : {header.get('figure_count', len(figures))}",
        f"Date : {header.get('created_at', '')}",
        f"Version pipeline : figTrack detecteurs v{DETECTOR_VERSION}",
    ]
    for line in meta:
        pdf.cell(0, 5, _s(line), ln=1)
    pdf.set_text_color(0)
    pdf.ln(2)

    pdf.set_font("Helvetica", "I", 9)
    pdf.multi_cell(
        0,
        4.5,
        _s(
            "Avertissement : ce rapport presente des indices techniques localises et reproductibles. "
            "Il ne conclut PAS a une fraude, une falsification ou une intention. Chaque indice doit etre "
            "examine par un analyste au regard du contexte (legende, methode, donnees sources, impact)."
        ),
    )
    pdf.ln(3)

    for fig in figures:
        _render_figure(pdf, fig)

    # Manifeste de preuve (empreintes).
    pdf.add_page()
    pdf.set_font("Helvetica", "B", 12)
    pdf.cell(0, 8, "Manifeste de preuve (SHA-256)", ln=1)
    pdf.set_font("Courier", "", 8)
    pdf.cell(0, 5, _s(f"source : {source_sha256}"), ln=1)
    for fig in figures:
        if fig.get("sha256"):
            pdf.cell(0, 5, _s(f"fig{fig.get('figure_index', '')} : {fig['sha256']}"), ln=1)

    out = pdf.output()
    return bytes(out)


def _render_figure(pdf: _Report, fig: dict) -> None:
    if pdf.get_y() > 230:
        pdf.add_page()
    pdf.set_draw_color(210)
    pdf.set_font("Helvetica", "B", 11)
    label = fig.get("filename") or f"Figure {fig.get('figure_index', '')}"
    page = f" (p.{fig['page']})" if fig.get("page") else ""
    pdf.cell(0, 7, _s(f"{label}{page} - {_TRIAGE_LABEL.get(fig.get('triage_max', 'T0'), fig.get('triage_max', 'T0'))}"), ln=1)

    y0 = pdf.get_y()
    # Vignette (via PIL pour éviter les soucis d'extension sur les fichiers stockés par sha256).
    sha = fig.get("sha256")
    if sha:
        path = storage.path_for(sha)
        if os.path.exists(path):
            try:
                with Image.open(path) as im:
                    im = im.convert("RGB")
                    pdf.image(im, x=pdf.l_margin, y=y0, w=38)
            except Exception:  # noqa: BLE001
                pass
    pdf.set_xy(pdf.l_margin + 42, y0)

    if fig.get("summary"):
        pdf.set_font("Helvetica", "", 9)
        pdf.multi_cell(0, 4.5, _s(fig["summary"]))
    findings = fig.get("findings") or []
    if not findings:
        pdf.set_x(pdf.l_margin + 42)
        pdf.set_font("Helvetica", "I", 9)
        pdf.set_text_color(90)
        pdf.multi_cell(0, 4.5, _s("Aucun indice technique sur cette figure."))
        pdf.set_text_color(0)
    for f in findings:
        pdf.set_x(pdf.l_margin + 42)
        pdf.set_font("Helvetica", "B", 9)
        pdf.multi_cell(
            0,
            4.5,
            _s(f"[{f.get('triage_level', '')}/{f.get('evidence_level', '')}] {f.get('anomaly_type', '')} - {f.get('detector', '')} v{f.get('detector_version', '')}"),
        )
        pdf.set_x(pdf.l_margin + 42)
        pdf.set_font("Helvetica", "", 9)
        pdf.multi_cell(0, 4.5, _s(f.get("description", "")))
        if f.get("human_status"):
            pdf.set_x(pdf.l_margin + 42)
            pdf.set_font("Helvetica", "I", 9)
            pdf.multi_cell(0, 4.5, _s(f"Decision humaine : {f['human_status']}" + (f" - {f['rationale']}" if f.get("rationale") else "")))
    # Assure que le curseur passe sous la vignette.
    if pdf.get_y() < y0 + 40:
        pdf.set_y(y0 + 40)
    pdf.ln(3)
