"""Configuration du module figTrack (lue depuis l'environnement)."""

from __future__ import annotations

import os


def _sqlalchemy_url(dsn: str) -> str:
    """Convertit un DSN Symfony (postgresql://…?serverVersion=…) en URL SQLAlchemy psycopg."""
    url = dsn.split("?", 1)[0]
    for prefix in ("postgresql://", "postgres://"):
        if url.startswith(prefix):
            return "postgresql+psycopg://" + url[len(prefix):]
    return url


DATABASE_URL = _sqlalchemy_url(
    os.environ.get("DATABASE_URL", "postgresql://postgres:postgres@localhost:5432/postgres")
)

# Clé PUBLIQUE JWT SciencesWiki (volume partagé, lecture seule). Le module VALIDE les jetons,
# il n'en signe jamais (cf. module analyses).
JWT_PUBLIC_KEY_PATH = os.environ.get("JWT_PUBLIC_KEY_PATH", "/app/jwt/public.pem")

# Stockage objet (volume Docker) : octets originaux conservés par empreinte SHA-256.
STORAGE_DIR = os.environ.get("FIGTRK_STORAGE_DIR", "/data")

# Seuil de distance de Hamming (pHash 64 bits) sous lequel deux images sont « quasi-dupliquées ».
NEAR_DUP_HAMMING = int(os.environ.get("FIGTRK_NEAR_DUP_HAMMING", "10"))

# Taille maximale d'un fichier accepté (octets).
MAX_UPLOAD_BYTES = int(os.environ.get("FIGTRK_MAX_UPLOAD_BYTES", str(25 * 1024 * 1024)))
