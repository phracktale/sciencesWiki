# SciencesWiki — service d'embeddings (`ml/`)

Service d'inférence **auto-hébergé** et **open source** qui produit les embeddings
consommés par l'API Symfony et le futur serveur RAG (cf. `../docs/rag-server.md`).

- Modèle par défaut : `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`
  (multilingue, **dimension 384**).
- La dimension **doit** rester alignée avec la colonne pgvector `vector(384)` et
  `EmbeddingClient::DIMENSIONS` côté API.

## Lancer

```bash
pip install -r requirements.txt
uvicorn app:app --host 0.0.0.0 --port 8001
# ou
docker build -t scienceswiki-ml . && docker run -p 8001:8001 scienceswiki-ml
```

## API

```
POST /embed   { "text": "..." }  ->  { "embedding": [...384...], "dimensions": 384, "model": "..." }
GET  /health                     ->  { "status": "ok", "model": "...", "dimensions": 384 }
```

## Brancher l'API Symfony

Dans `apps/api/.env.local` :

```
EMBEDDING_DRIVER=http
ML_EMBED_URL=http://127.0.0.1:8001/embed
```

> Pour le développement/les tests sans modèle lourd, l'API dispose d'un embedder
> déterministe local : `EMBEDDING_DRIVER=hashing` (signal lexical, même dimension).
> Il sert à faire tourner et vérifier le pipeline (pgvector, kNN, placement) ; la
> qualité sémantique réelle exige ce service.
