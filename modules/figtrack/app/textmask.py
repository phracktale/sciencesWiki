"""Masquage du TEXTE et des traits (SPECS §11) avant les comparaisons visuelles.

Le texte des libellés/axes/barres d'échelle est répétitif et très saillant : sans masquage, les
détecteurs le confondent avec une duplication d'image (faux positif classique). On repère le
texte comme des pixels SOMBRES et PEU SATURÉS (noir sur fond clair), ce qui NE masque PAS le
marquage biologique coloré (bleu/vert saturé).
"""

from __future__ import annotations

import cv2
import numpy as np


def text_mask(image: np.ndarray) -> np.ndarray:
    """Masque booléen (True = texte / trait noir). Accepte une image couleur (BGR) ou niveaux de gris."""
    if image.ndim == 3:
        hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
        dark = (hsv[:, :, 2] < 90) & (hsv[:, :, 1] < 60)  # sombre ET peu saturé
    else:
        dark = image < 80
    m = dark.astype(np.uint8) * 255
    # Fusionne les glyphes en lignes/bandes de texte, épaissit légèrement les traits.
    m = cv2.dilate(m, cv2.getStructuringElement(cv2.MORPH_RECT, (15, 5)), iterations=2)
    return m > 0


def text_fraction(mask: np.ndarray, box: tuple[int, int, int, int]) -> float:
    """Proportion de pixels « texte » dans une boîte (x, y, w, h)."""
    x, y, w, h = box
    sub = mask[y : y + h, x : x + w]
    return float(sub.mean()) if sub.size else 0.0
