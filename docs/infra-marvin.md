# Infrastructure — nœud « Marvin » (192.168.1.171)

Marvin est le **nœud IA + données** du homelab SciencesWiki. Il héberge la **base
PostgreSQL/pgvector**, les **LLM** (Ollama, GPU AMD), le **service d'embeddings**,
**GROBID** (extraction PDF), une instance **Open WebUI** perso, et désormais le
**snapshot OpenAlex** complet. L'application web/API tourne sur **Thor**
(192.168.1.36), qui se connecte à Marvin par le réseau privé.

> ⚠️ Marvin n'est **jamais exposé sur Internet** : tous ses services écoutent sur
> le LAN. Seul Thor (via Heimdall) est public.

---

## 1. Disques et points de montage

| Disque | Modèle | Taille | Partition → montage | Contenu |
|---|---|---|---|---|
| `nvme2n1` | Kingston OM8PCP3512F | 477 Go | `p1`→`/boot/efi`, `p2`→`/home` (360 Go), `p3`→swap (16 Go), `p4`→**`/`** (98 Go) | Système. **Petit → garder léger** (cf. §4). |
| `nvme0n1` | Samsung 9100 PRO 2 To | 1,8 To | `p1` ext4 → **`/data`** | **Base Postgres** (`/data/scienceswiki-pgdata`) + **modèles Ollama** (`/data/models/ollama`, ~180 Go). |
| `nvme1n1` | Predator GM7 4 To | 3,7 To | `p1` ext4 → **`/data2`** | **Snapshot OpenAlex** (`/data2/openalex-snapshot`) + **Docker** (`/data2/docker`) + **containerd** (`/data2/containerd`). |

- `/data2` ajouté en 06/2026 (3ᵉ disque). Monté via **UUID** dans `/etc/fstab`
  avec l'option **`nofail`** (un disque manquant ne bloque pas le boot).
- `/data` et `/data2` sont des **ext4** sur NVMe ; la base est isolée sur `/data`,
  les images conteneurs sur `/data2` (pas de contention d'I/O avec la base).

---

## 2. Liens symboliques (déports hors `/`)

La partition `/` ne fait que 98 Go : les gros volumes sont **déportés** sur les
disques de données, par symlink ou data-root.

| Chemin sur `/` | Pointe vers | Pourquoi |
|---|---|---|
| `/usr/share/ollama/.ollama` | **symlink →** `/data/models/ollama` | Modèles Ollama (~180 Go) hors `/`. |
| `/var/lib/containerd` | **symlink →** `/data2/containerd` | Couches d'images conteneurs (~47 Go) hors `/`. |
| Docker data-root | `/data2/docker` (via `/etc/docker/daemon.json` `data-root`) | État Docker (images/volumes) hors `/`. |

> Note : **déplacer le data-root Docker ne déplace PAS les images** si Docker
> utilise le *containerd-snapshotter* — celles-ci vivent dans `/var/lib/containerd`,
> d'où le symlink ci-dessus. (Vérifier `docker info | grep "Root Dir"` →
> `/data2/docker`, et que `/var/lib/containerd` est bien un lien.)

---

## 3. Services

### Conteneurs Docker — compose `scienceswiki-ml` (`infra/marvin/docker-compose.yml`)
Repo cloné sur Marvin : `/home/phracktale/scienceswiki` ; lancer depuis `infra/marvin`.

| Service | Image | Port (LAN) | Données |
|---|---|---|---|
| `database` | `pgvector/pgvector:pg16` | `192.168.1.171:5432` | bind `/data/scienceswiki-pgdata` |
| `grobid` | `grobid/grobid:0.8.1` | `192.168.1.171:8070` | — (modèles dans l'image) |
| `embeddings` | `scienceswiki-ml:latest` (build `ml/`) | `8001` | modèle MiniLM 384-dim (multilingue) |

### Conteneur autonome
| `open-webui` | `ghcr.io/open-webui/open-webui:main` | `3000` | instance **perso** (≠ celle de prod sur Thor). Volume Docker. |

### Service natif (systemd)
| **Ollama** | service `ollama`, **port 11434** (`OLLAMA_HOST=0.0.0.0:11434`) | GPU **AMD** (ROCm 7.2.1 sous `/opt`, `OLLAMA_VULKAN=1`, `OLLAMA_IGPU_ENABLE=1`). Modèles dans `/data/models/ollama`. |

Démarrage : `docker compose up -d` (dans `infra/marvin`) + `docker start open-webui`
+ Ollama démarre seul via systemd. ⚠️ Si les conteneurs ont été **arrêtés
manuellement** (`docker stop`), `restart: unless-stopped` ne les relance PAS au
boot → les relancer à la main.

---

## 4. Gestion de l'espace (`/` est petit : 98 Go)

Tout ce qui grossit doit rester **hors de `/`** :
- modèles Ollama → `/data` (symlink) ;
- images/containerd + data Docker → `/data2` (symlink + data-root) ;
- base Postgres → `/data` (bind-mount) ;
- snapshot OpenAlex → `/data2`.

Nettoyages utiles :
```bash
docker builder prune -af                 # cache de build Docker (récupérable)
sudo apt-get clean                        # cache paquets
sudo journalctl --vacuum-size=200M        # logs systemd
ollama rm <modèle-inutile>                # libère /data (ex. mistral-medium-3.5 ≈ 80 Go)
```
Repère ce qui remplit `/` : `sudo du -xh / --max-depth=2 | sort -rh | head`.

---

## 5. Export NFS Marvin → Thor (snapshot OpenAlex)

Pour que l'API (sur Thor) puisse **lire le snapshot** stocké sur Marvin :

- **Marvin** (`/etc/exports`) : `/data2 192.168.1.36(ro,sync,no_subtree_check)`
  (lecture seule, vers la seule IP de Thor) → `sudo exportfs -ra`.
- **Thor** (`/etc/fstab`) : `192.168.1.171:/data2 /mnt/marvin-data2 nfs ro,soft,_netdev 0 0`
  → monté sur **`/mnt/marvin-data2`**.
- Le conteneur `api` (Thor) bind-monte `/mnt/marvin-data2/openalex-snapshot:/snapshot:ro`
  pour la commande `app:openalex:ingest-snapshot` (ingestion locale, hors API OpenAlex).

---

## 6. Lien avec Thor (ce que l'API/worker consomment)

`.env.prod` (Thor) pointe vers Marvin par le réseau privé :

| Variable | Valeur | Service Marvin |
|---|---|---|
| `DB_HOST` / `DATABASE_URL` | `192.168.1.171:5432` | PostgreSQL/pgvector |
| `ML_EMBED_URL` | `http://192.168.1.171:8001` | embeddings (MiniLM 384) |
| `LLM_BASE_URL` | `http://192.168.1.171:11434/v1` | Ollama |
| `GROBID_URL` | `http://192.168.1.171:8070` | GROBID |

→ **Arrêter Marvin rend le site indisponible** (l'API ne joint plus la base).
Arrêt propre : `docker stop -t 30 …` (Postgres : shutdown clean) puis extinction
(Ollama est sans état). Au retour : remonter les conteneurs (cf. §3).

---

## 7. Snapshot OpenAlex (mise à jour mensuelle)

- Téléchargement : `aws s3 sync "s3://openalex/data/works" "openalex-snapshot/data/works" --no-sign-request`
  (depuis `/data2` ; se limiter à `data/works` suffit pour l'ingestion ; ~330 Go).
- Ingestion sélective (sous-ensemble de la taxonomie, hors API) :
  `app:openalex:ingest-snapshot --dir=/snapshot …` (cf. la commande, §5).
- OpenAlex publie un **nouveau snapshot chaque mois** → re-sync + ré-exécution
  (idempotent par dédup DOI).
