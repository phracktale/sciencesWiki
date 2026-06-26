"""Service HHEM-2.1-Open (Vectara) — détection d'hallucination par entailment NLI.

Expose un score de COHÉRENCE FACTUELLE (0..1) entre un *premise* (les passages
sources récupérés) et une *hypothesis* (une affirmation générée par le LLM) :
- score proche de 1 → l'affirmation est soutenue par les sources ;
- score bas       → affirmation non soutenue (hallucination probable).

Modèle dédié (~110 M), multilingue EN/FR/DE, qui surpasse les LLM-juges sur la
détection d'hallucination tout en étant bien plus efficace. Tourne en CPU sur Marvin
(comme le service d'embeddings). cf. garde-fou anti-hallucination (RAG Triad).
"""

from __future__ import annotations

import os

from fastapi import FastAPI
from pydantic import BaseModel
from transformers import AutoModelForSequenceClassification

MODEL_NAME = os.getenv("HHEM_MODEL", "vectara/hallucination_evaluation_model")

# trust_remote_code : HHEM-2.1-Open embarque sa propre classe de modèle (cross-encoder).
model = AutoModelForSequenceClassification.from_pretrained(MODEL_NAME, trust_remote_code=True)

app = FastAPI(title="SciencesWiki HHEM", version="0.1")


class Pair(BaseModel):
    premise: str
    hypothesis: str


class PairBatch(BaseModel):
    # Liste de couples [premise, hypothesis].
    pairs: list[tuple[str, str]]


@app.post("/score")
def score(req: Pair) -> dict:
    value = float(model.predict([(req.premise, req.hypothesis)])[0])
    return {"score": value, "model": MODEL_NAME}


@app.post("/score-batch")
def score_batch(req: PairBatch) -> dict:
    if not req.pairs:
        return {"scores": [], "model": MODEL_NAME}
    pairs = [(str(p[0]), str(p[1])) for p in req.pairs]
    scores = [float(x) for x in model.predict(pairs)]
    return {"scores": scores, "model": MODEL_NAME}


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "model": MODEL_NAME}
