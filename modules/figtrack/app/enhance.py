"""Rehaussement d'image pour l'explorateur (forensique) : canal, niveaux, contraste, gamma…

Objectif : faire APPARAÎTRE une zone dupliquée en ajustant l'image, ET lancer la détection sur
l'image rehaussée. Purement descriptif — ne « restaure » aucune donnée (SPECS §11/§18).
"""

from __future__ import annotations

import io

import cv2
import numpy as np
from PIL import Image


# Préréglages de rehaussement essayés lors de l'analyse AUTOMATIQUE (et proposés dans
# l'explorateur). « Image brute » (None) = pas de rehaussement (comportement historique).
# Un filtre qui fait APPARAÎTRE une duplication invisible à l'œil est ainsi exploité d'office.
PRESETS: dict[str, dict | None] = {
    "Image brute": None,
    "Contraste fort / gamma bas": {"contrast": 3.0, "gamma": 0.35},
    "Égalisation locale (CLAHE)": {"equalize": True, "contrast": 1.3},
    "Saturation rehaussée": {"channel": "sat", "contrast": 1.8},
}


def _channel(bgr: np.ndarray, ch: str) -> np.ndarray:
    if ch == "r":
        return bgr[:, :, 2]
    if ch == "g":
        return bgr[:, :, 1]
    if ch == "b":
        return bgr[:, :, 0]
    if ch == "sat":
        return cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)[:, :, 1]
    if ch == "hue":
        return cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)[:, :, 0]
    return cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)  # luminance


def process(path: str, p: dict) -> np.ndarray:
    """Applique les réglages et renvoie une image NIVEAUX DE GRIS uint8 (image de travail)."""
    bgr = cv2.imread(path, cv2.IMREAD_COLOR)
    if bgr is None:
        gray = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
        if gray is None:
            raise ValueError("image illisible")
        return gray

    g = _channel(bgr, str(p.get("channel", "lum"))).astype(np.float32)

    # Niveaux : point noir / point blanc.
    bp = float(p.get("black", 0.0))
    wp = float(p.get("white", 255.0))
    if wp > bp:
        g = (g - bp) * (255.0 / (wp - bp))

    # Contraste (autour de 128) + luminosité.
    g = (g - 128.0) * float(p.get("contrast", 1.0)) + 128.0 + float(p.get("brightness", 0.0))
    g = np.clip(g, 0, 255)

    # Gamma.
    gamma = float(p.get("gamma", 1.0))
    if abs(gamma - 1.0) > 1e-3:
        g = 255.0 * np.power(g / 255.0, 1.0 / max(0.05, gamma))

    g = np.clip(g, 0, 255).astype(np.uint8)

    if p.get("equalize"):
        g = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8)).apply(g)
    if p.get("invert"):
        g = 255 - g
    return g


def to_png(gray: np.ndarray) -> bytes:
    buf = io.BytesIO()
    Image.fromarray(gray).save(buf, "PNG")
    return buf.getvalue()
