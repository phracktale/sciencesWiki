"""Segmentation des figures multipanneaux (X-Y cut sur les gouttières blanches).

Approche CV classique (SPECS §10) : on découpe récursivement une figure le long des bandes
quasi-blanches (gouttières entre panneaux). Pas de modèle de détection d'objets en v1.2.
"""

from __future__ import annotations

import numpy as np

_WHITE = 245  # niveau au-dessus duquel une ligne est considérée « blanche »


def _gutters(is_white: np.ndarray, min_width: int) -> list[tuple[int, int]]:
    """Bandes contiguës « blanches » d'au moins min_width lignes/colonnes."""
    out: list[tuple[int, int]] = []
    start: int | None = None
    for i, w in enumerate(is_white):
        if w and start is None:
            start = i
        elif not w and start is not None:
            if i - start >= min_width:
                out.append((start, i))
            start = None
    if start is not None and len(is_white) - start >= min_width:
        out.append((start, len(is_white)))
    return out


def _trim(gray: np.ndarray, x: int, y: int) -> tuple[int, int, int, int] | None:
    """Rogne les marges blanches d'un panneau ; None si le panneau est vide/uniforme."""
    content = gray < _WHITE
    rows = np.where(content.any(axis=1))[0]
    cols = np.where(content.any(axis=0))[0]
    if rows.size == 0 or cols.size == 0:
        return None
    y0, y1 = int(rows[0]), int(rows[-1]) + 1
    x0, x1 = int(cols[0]), int(cols[-1]) + 1
    return (x + x0, y + y0, x1 - x0, y1 - y0)


def _cut(gray: np.ndarray, ox: int, oy: int, boxes: list, min_panel: int, depth: int) -> None:
    h, w = gray.shape
    if depth > 4 or (h < min_panel * 2 and w < min_panel * 2):
        box = _trim(gray, ox, oy)
        if box is not None and box[2] >= min_panel and box[3] >= min_panel:
            boxes.append(box)
        return

    col_white = gray.mean(axis=0) > _WHITE
    row_white = gray.mean(axis=1) > _WHITE
    min_w = max(6, int(w * 0.02))
    min_h = max(6, int(h * 0.02))
    # Gouttières INTÉRIEURES uniquement (on ignore les marges de bord).
    col_g = [g for g in _gutters(col_white, min_w) if g[0] > min_panel and g[1] < w - min_panel]
    row_g = [g for g in _gutters(row_white, min_h) if g[0] > min_panel and g[1] < h - min_panel]
    best_col = max(col_g, key=lambda g: g[1] - g[0], default=None)
    best_row = max(row_g, key=lambda g: g[1] - g[0], default=None)

    col_w = (best_col[1] - best_col[0]) if best_col else 0
    row_w = (best_row[1] - best_row[0]) if best_row else 0

    if col_w == 0 and row_w == 0:
        box = _trim(gray, ox, oy)
        if box is not None and box[2] >= min_panel and box[3] >= min_panel:
            boxes.append(box)
        return

    if col_w >= row_w:
        a, b = best_col
        _cut(gray[:, :a], ox, oy, boxes, min_panel, depth + 1)
        _cut(gray[:, b:], ox + b, oy, boxes, min_panel, depth + 1)
    else:
        a, b = best_row
        _cut(gray[:a, :], ox, oy, boxes, min_panel, depth + 1)
        _cut(gray[b:, :], ox, oy + b, boxes, min_panel, depth + 1)


def segment_panels(gray: np.ndarray, min_panel: int = 80, max_panels: int = 16) -> list[tuple[int, int, int, int]]:
    """Découpe une figure en panneaux (boîtes x,y,w,h). Liste vide/1 élément si mono-panneau."""
    boxes: list[tuple[int, int, int, int]] = []
    _cut(gray, 0, 0, boxes, min_panel, 0)
    return boxes[:max_panels]
