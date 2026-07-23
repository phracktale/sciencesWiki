"""Service d'embeddings auto-hébergé pour SciencesWiki (cf. docs/rag-server.md).

Expose `POST /embed` consommé par l'API Symfony (EMBEDDING_DRIVER=http).
Modèle open source multilingue (sentence-transformers), dimension 384 — alignée
sur la colonne pgvector `vector(384)` et sur EmbeddingClient::DIMENSIONS.
"""

from __future__ import annotations

import glob
import os
import subprocess

import torch
from fastapi import FastAPI
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer

MODEL_NAME = os.getenv(
    "EMBEDDING_MODEL",
    "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",
)
EXPECTED_DIM = int(os.getenv("EMBEDDING_DIMENSIONS", "384"))

# GPU si disponible (RTX 3090 sur Marvin), sinon CPU. sentence-transformers le ferait
# seul, mais on l'explicite et on le journalise pour vérifier l'accélération au démarrage.
DEVICE = "cuda" if torch.cuda.is_available() else "cpu"
print(f"[embeddings] device={DEVICE} ({torch.cuda.get_device_name(0) if DEVICE == 'cuda' else 'cpu'})", flush=True)

model = SentenceTransformer(MODEL_NAME, device=DEVICE)
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


def _hwmon_temps() -> dict:
    """Températures de l'hôte lues dans /sys/class/hwmon (sans dépendance `sensors`).

    Renvoie {chip: {label: °C}} — ex. {"k10temp": {"Tctl": 84.2}, "nvme": {...}}.
    Le /sys de l'hôte est visible (lecture seule) dans le conteneur.
    """
    out: dict[str, dict[str, float]] = {}
    for chip in glob.glob("/sys/class/hwmon/hwmon*"):
        try:
            with open(f"{chip}/name", encoding="ascii") as fh:
                name = fh.read().strip()
        except OSError:
            continue
        for tin in glob.glob(f"{chip}/temp*_input"):
            try:
                with open(tin, encoding="ascii") as fh:
                    val = int(fh.read().strip()) / 1000.0
            except (OSError, ValueError):
                continue
            try:
                with open(tin.replace("_input", "_label"), encoding="ascii") as fh:
                    label = fh.read().strip()
            except OSError:
                label = tin.rsplit("/", 1)[-1].replace("_input", "")
            out.setdefault(name, {})[label] = round(val, 1)
    return out


def _cpu_temp(temps: dict) -> float | None:
    """Température CPU la plus pertinente (APU AMD = k10temp Tctl/Tdie)."""
    k10 = temps.get("k10temp") or {}
    for key in ("Tctl", "Tdie", "Tccd1"):
        if key in k10:
            return k10[key]
    return next(iter(k10.values()), None)


def _gpu_stats() -> dict | None:
    """Stats de la RTX 3090 via nvidia-smi (None si absent : pilote/GPU non exposé)."""
    fields = "name,temperature.gpu,utilization.gpu,memory.used,memory.total,power.draw,power.limit"
    try:
        r = subprocess.run(
            ["nvidia-smi", f"--query-gpu={fields}", "--format=csv,noheader,nounits"],
            capture_output=True, text=True, timeout=3,
        )
    except (OSError, subprocess.SubprocessError):
        return None
    if r.returncode != 0 or not r.stdout.strip():
        return None
    p = [c.strip() for c in r.stdout.strip().splitlines()[0].split(",")]
    if len(p) < 7:
        return None

    def _f(i: int) -> float | None:
        try:
            return float(p[i])
        except (ValueError, IndexError):
            return None

    return {
        "name": p[0],
        "tempC": _f(1),
        "utilPct": _f(2),
        "memUsedMiB": _f(3),
        "memTotalMiB": _f(4),
        "powerW": _f(5),
        "powerLimitW": _f(6),
    }


@app.get("/stats")
def stats() -> dict:
    """Charge CPU + mémoire + températures + GPU de Marvin, pour le monitoring back-office."""
    try:
        load1, load5, _ = os.getloadavg()
    except OSError:
        load1 = load5 = 0.0
    cpus = os.cpu_count() or 1
    mem = _meminfo()
    temps = _hwmon_temps()
    return {
        "load1": round(load1, 2),
        "load5": round(load5, 2),
        "cpus": cpus,
        "loadPct": int(min(100, load1 / cpus * 100)),
        "memTotalKb": mem["total"],
        "memAvailKb": mem["avail"],
        "memPct": int((mem["total"] - mem["avail"]) / mem["total"] * 100) if mem["total"] else None,
        "cpuTempC": _cpu_temp(temps),
        "temps": temps,
        "gpu": _gpu_stats(),
    }
