# Déploiement — checklist consolidée

> Topologie : **Thor** (192.168.1.36) = app (API Symfony + web Twig + Open WebUI) ·
> **Marvin** (192.168.1.171) = services IA/données (Postgres/pgvector, Ollama, embeddings,
> GROBID, HHEM, snapshot OpenAlex) · **Heimdall** (192.168.1.195) = reverse-proxy nginx.
> Détail machine par machine : [infra-marvin.md](infra-marvin.md).

---

## 1. Déploiement standard de l'application — 🖥️ sur Thor

```bash
cd ~/scienceswiki
git pull --rebase origin main
# ⚠️ si « untracked … embed-drain.sh » : rm -f embed-drain.sh fulltext-drain.sh && git pull --rebase origin main
cd infra
docker compose --env-file .env.prod up -d --build api web   # migrations jouées au boot (RUN_INIT)
docker compose --env-file .env.prod restart openwebui        # recharge le thème CRT (custom.css)
```

- Les **migrations Doctrine** s'exécutent automatiquement au démarrage de l'`api` (`RUN_INIT`).
- Le **hard-refresh navigateur** (`Ctrl+Shift+R`) est nécessaire après un changement de `custom.css`.

## 2. Workers d'enrichissement (Messenger) — 🖥️ sur Thor

| Worker | File | Rôle |
|---|---|---|
| `worker` (×N) | `harvester` | moisson API + import (dédup + embed inline) |
| `fulltext-worker` (×N) | `fulltext` | PDF OA → GROBID → `publication_chunk` |
| `analysis-worker` | `analysis` | controverses & pistes **+ (re)génération d'article à la demande** |

```bash
# Recréer AVEC le code à jour (pas « start » seul, sinon ancien code) :
docker compose --env-file .env.prod up -d --build worker fulltext-worker analysis-worker
# Mettre en pause (ex. gros téléchargement) :
docker compose --env-file .env.prod stop worker fulltext-worker analysis-worker
```

> La file `plagiarism` (empreintes + scan) n'a pas encore de worker dédié : les commandes
> `app:plagiarism:*` suffisent au Lot 1 (cf. §5).

## 3. Crons d'enrichissement — 🖥️ sur Thor (`crontab -e`)

Drains : `embed-drain.sh` (`*/10`), `fulltext-drain.sh` (`*/15`), `app:harvest:auto` (`*/20`),
`app:stats:refresh` (`*/30`), `app:wiki:generate` (`0 1-6`). Pause **chirurgicale** (garde les
autres projets) :
```bash
crontab -l > ~/cron.bak
crontab -l | sed -E '/scienceswiki/ s/^([^#])/#\1/' | crontab -   # commente UNIQUEMENT les lignes scienceswiki
crontab ~/cron.bak                                                # pour réactiver
```

## 4. Services IA — 🖥️ sur Marvin (`infra/marvin`)

```bash
cd ~/scienceswiki/infra/marvin
docker compose up -d --build               # database (pgvector), grobid, embeddings (8001)
docker compose up -d --build hhem          # garde-fou HHEM (8002) — 1er build = télécharge le modèle
docker start open-webui                     # instance perso
# Ollama démarre seul (systemd, port 11434).
```
Santé : `curl -s localhost:8001/health` · `curl -s localhost:8002/health`.

## 5. Drapeaux & variables (`.env.prod` sur Thor)

| Variable | Effet |
|---|---|
| `HHEM_URL` | `http://192.168.1.171:8002` = active le garde-fou HHEM ; **vide = désactivé** (repli LLM) |
| `RAG_VERIFY` (réglage BO) | active la vérification de fidélité (cran 1/2 + HHEM) |
| `DB_HOST`, `ML_EMBED_URL`, `LLM_BASE_URL`, `GROBID_URL` | pointent vers Marvin (192.168.1.171) |

Après ajout de `HHEM_URL` : `docker compose --env-file .env.prod up -d api`.

## 6. Antiplagiat (Lot 1) — 🖥️ sur Thor

```bash
docker compose --env-file .env.prod exec -T api php bin/console app:plagiarism:fingerprint --limit=500
docker compose --env-file .env.prod exec -T api php bin/console app:plagiarism:scan --limit=500
# → résultats dans /admin → Doublons & plagiat
```
> Ne donne des résultats que sur les publications **passées en plein texte** (GROBID).

## 7. Ingestion du snapshot OpenAlex (quand le download est fini)

NFS Marvin→Thor monté sur `/mnt/marvin-data2`. Ajouter le volume `:/openalexSnapshot` à l'`api`,
puis :
```bash
docker compose --env-file .env.prod exec -T api php bin/console app:openalex:ingest-snapshot \
    --dir=/openalexSnapshot --since=2015 --min-citations=5 --langs=en,fr --files=1 --max=500   # test
```
Idempotent (dédup DOI/openalex indexée) et reprenable (`--skip-files`).

## 8. Vérifications post-déploiement

```bash
docker compose --env-file .env.prod ps                 # tout « healthy »
curl -fsS https://scienceswiki.eu/ -o /dev/null && echo "web OK"
docker compose --env-file .env.prod exec -T api php bin/console doctrine:migrations:list | tail -5
```
