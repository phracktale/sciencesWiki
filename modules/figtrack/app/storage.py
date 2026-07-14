"""Stockage objet des images originales (adressage par empreinte SHA-256, non destructif)."""

from __future__ import annotations

import hashlib
import os

from .config import STORAGE_DIR


def save_bytes(data: bytes) -> str:
    digest = hashlib.sha256(data).hexdigest()
    os.makedirs(STORAGE_DIR, exist_ok=True)
    path = path_for(digest)
    if not os.path.exists(path):
        # Écriture atomique : temporaire puis renommage.
        tmp = path + ".tmp"
        with open(tmp, "wb") as fh:
            fh.write(data)
        os.replace(tmp, path)
    return digest


def path_for(sha256: str) -> str:
    return os.path.join(STORAGE_DIR, sha256)
