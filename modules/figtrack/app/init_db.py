"""Création idempotente des tables figtrk_* (appelée au démarrage si RUN_INIT=1)."""

from __future__ import annotations

from . import models  # noqa: F401  (enregistre les modèles sur Base)
from .db import Base, engine

if __name__ == "__main__":
    Base.metadata.create_all(bind=engine)
    print("[figtrack] tables figtrk_* prêtes.")
