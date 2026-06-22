"""Service d'embeddings auto-hébergé pour SciencesWiki (cf. docs/rag-server.md).

Expose `POST /embed` consommé par l'API Symfony (EMBEDDING_DRIVER=http).
Modèle open source multilingue (sentence-transformers), dimension 384 — alignée
sur la colonne pgvector `vector(384)` et sur EmbeddingClient::DIMENSIONS.
"""

from __future__ import annotations

import os

from fastapi import FastAPI
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer

MODEL_NAME = os.getenv(
    "EMBEDDING_MODEL",
    "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",
)
EXPECTED_DIM = int(os.getenv("EMBEDDING_DIMENSIONS", "384"))

model = SentenceTransformer(MODEL_NAME)
_dim = model.get_sentence_embedding_dimension()
if _dim != EXPECTED_DIM:
    raise RuntimeError(
        f"Le modèle '{MODEL_NAME}' produit des vecteurs de dimension {_dim}, "
        f"attendu {EXPECTED_DIM} (alignez la colonne pgvector et EmbeddingClient::DIMENSIONS)."
    )

app = FastAPI(title="SciencesWiki Embeddings", version="0.1")


class EmbedRequest(BaseModel):
    text: str


class EmbedBatchRequest(BaseModel):
    texts: list[str]


@app.post("/embed")
def embed(request: EmbedRequest) -> dict:
    vector = model.encode(request.text, normalize_embeddings=True)
    return {
        "embedding": vector.tolist(),
        "dimensions": len(vector),
        "model": MODEL_NAME,
    }


@app.post("/embed-batch")
def embed_batch(request: EmbedBatchRequest) -> dict:
    """Encode plusieurs textes en un seul appel (vectorisé) : bien plus rapide
    que des appels unitaires. Renvoie les vecteurs dans le même ordre."""
    if not request.texts:
        return {"embeddings": [], "dimensions": EXPECTED_DIM, "model": MODEL_NAME}
    vectors = model.encode(
        request.texts,
        normalize_embeddings=True,
        batch_size=int(os.getenv("EMBEDDING_BATCH_SIZE", "32")),
    )
    return {
        "embeddings": [v.tolist() for v in vectors],
        "dimensions": len(vectors[0]),
        "model": MODEL_NAME,
    }


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "model": MODEL_NAME, "dimensions": EXPECTED_DIM}


def _meminfo() -> dict:
    """MemTotal/MemAvailable (kB) depuis /proc/meminfo de l'hôte (Marvin)."""
    total = avail = 0
    try:
        with open("/proc/meminfo", "r", encoding="ascii") as fh:
            for line in fh:
                if line.startswith("MemTotal:"):
                    total = int(line.split()[1])
                elif line.startswith("MemAvailable:"):
                    avail = int(line.split()[1])
    except OSError:
        pass
    return {"total": total, "avail": avail}


@app.get("/stats")
def stats() -> dict:
    """Charge CPU (loadavg) + mémoire de Marvin, pour l'indicateur du back-office."""
    try:
        load1, load5, _ = os.getloadavg()
    except OSError:
        load1 = load5 = 0.0
    cpus = os.cpu_count() or 1
    mem = _meminfo()
    return {
        "load1": round(load1, 2),
        "load5": round(load5, 2),
        "cpus": cpus,
        "loadPct": int(min(100, load1 / cpus * 100)),
        "memTotalKb": mem["total"],
        "memAvailKb": mem["avail"],
        "memPct": int((mem["total"] - mem["avail"]) / mem["total"] * 100) if mem["total"] else None,
    }
