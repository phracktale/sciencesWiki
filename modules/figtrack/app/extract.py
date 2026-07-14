"""Extraction des figures (images raster embarquées) d'un PDF via PyMuPDF.

v1.1 : extraction DIRECTE des objets image du PDF (SPECS §9.1, étape 1). Ne fait pas encore la
reconstruction vectorielle, la détection de zones par rendu de page, ni l'OCR des scannés.
Les très petites images (logos, icônes, puces) sont filtrées par taille.
"""

from __future__ import annotations

import fitz  # PyMuPDF


def extract_figures(
    data: bytes, min_dim: int = 128, max_figures: int = 80
) -> tuple[list[dict], int]:
    """Retourne (figures, nombre_de_pages).

    Chaque figure : {bytes, ext, page, width, height}. Déduplique les objets image réutilisés
    sur plusieurs pages (même xref) → une seule figure, la 1re page où elle apparaît.
    """
    figures: list[dict] = []
    doc = fitz.open(stream=data, filetype="pdf")
    pages = doc.page_count
    seen: set[int] = set()
    try:
        for pno in range(pages):
            if len(figures) >= max_figures:
                break
            page = doc[pno]
            for img in page.get_images(full=True):
                xref = int(img[0])
                if xref in seen:
                    continue
                seen.add(xref)
                try:
                    base = doc.extract_image(xref)
                except Exception:  # noqa: BLE001
                    continue
                width = int(base.get("width", 0))
                height = int(base.get("height", 0))
                if width < min_dim or height < min_dim:
                    continue  # logo / icône / filet
                figures.append(
                    {
                        "bytes": base["image"],
                        "ext": base.get("ext", "png"),
                        "page": pno + 1,
                        "width": width,
                        "height": height,
                    }
                )
                if len(figures) >= max_figures:
                    break
    finally:
        doc.close()
    return figures, pages
