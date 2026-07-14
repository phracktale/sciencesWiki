# Module « figTrack » (Python 3.12 / FastAPI) — standalone, base SW partagée.
# API de calcul forensique d'images (CV classique, sans GPU ni entraînement pour cette v1).
FROM python:3.12-slim AS prod

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PYTHONDONTWRITEBYTECODE=1

# libglib2.0-0 requis par opencv-python-headless au runtime.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libglib2.0-0 curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY entrypoint.sh .
RUN chmod +x entrypoint.sh

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:80/health || exit 1

CMD ["./entrypoint.sh"]
