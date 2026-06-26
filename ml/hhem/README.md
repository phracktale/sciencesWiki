# HHEM — garde-fou anti-hallucination (NLI)

Service auto-hébergé exposant **HHEM-2.1-Open** (Vectara) : un détecteur d'hallucination
dédié (~110 M paramètres, multilingue **EN/FR/DE**) qui score la **cohérence factuelle**
(0..1) entre un *premise* (les passages sources récupérés) et une *hypothesis* (une
affirmation générée par le LLM). Il **surpasse les LLM-juges** (et GPT-4) sur la
détection d'hallucination tout en étant bien plus léger — il tourne en **CPU sur Marvin**.

Voir aussi le « RAG Triad » (faithfulness / attribution / context) côté anti-hallucination.

## Endpoints
- `POST /score` — `{ "premise": "...", "hypothesis": "..." }` → `{ "score": 0.0..1.0 }`
- `POST /score-batch` — `{ "pairs": [["premise","hyp"], ...] }` → `{ "scores": [...] }`
- `GET /health`

Score proche de **1** = affirmation soutenue par les sources ; **bas** = hallucination probable.

## Déploiement (Marvin)
Service `hhem` du compose `infra/marvin/docker-compose.yml`, exposé sur `:8002`
(réseau privé homelab uniquement). Le 1er build télécharge le modèle (`trust_remote_code`).

```bash
# Sur Marvin, depuis infra/marvin :
docker compose up -d --build hhem
curl -s localhost:8002/health
```

## Branchement (API sur Thor)
L'API consomme le service via `HHEM_URL` (`.env.prod`), ex. `HHEM_URL=http://192.168.1.171:8002`.
Quand `HHEM_URL` est vide, le garde-fou est **désactivé** et `FaithfulnessChecker`
retombe sur la vérification LLM. Une fois branché, chaque rédaction passe par HHEM :
les phrases non soutenues (score < 0,5) reçoivent un marqueur `[réf. nécessaire]`
(append-only : aucune réécriture, donc aucune corruption possible).
