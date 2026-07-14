"""Création/évolution idempotente du schéma figtrk_* (au démarrage si RUN_INIT=1)."""

from __future__ import annotations

from sqlalchemy import text

from . import models  # noqa: F401  (enregistre les modèles sur Base)
from .db import Base, engine

# Colonnes ajoutées après la v1 sur des tables déjà créées (create_all n'altère pas l'existant).
_ALTERS = [
    "ALTER TABLE figtrk_asset ADD COLUMN IF NOT EXISTS document_id VARCHAR(32)",
    "ALTER TABLE figtrk_asset ADD COLUMN IF NOT EXISTS page INTEGER",
    "ALTER TABLE figtrk_asset ADD COLUMN IF NOT EXISTS figure_index INTEGER",
    "CREATE INDEX IF NOT EXISTS ix_figtrk_asset_document_id ON figtrk_asset (document_id)",
]


def main() -> None:
    Base.metadata.create_all(bind=engine)
    with engine.begin() as conn:
        for sql in _ALTERS:
            conn.execute(text(sql))
    print("[figtrack] schéma figtrk_* prêt.")


if __name__ == "__main__":
    main()
