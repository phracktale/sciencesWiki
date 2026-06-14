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


@app.post("/embed")
def embed(request: EmbedRequest) -> dict:
    vector = model.encode(request.text, normalize_embeddings=True)
    return {
        "embedding": vector.tolist(),
        "dimensions": len(vector),
        "model": MODEL_NAME,
    }


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "model": MODEL_NAME, "dimensions": EXPECTED_DIM}
