# Nextcloud installatie

## Overzicht

Nextcloud draait als Docker stack op deze machine, bereikbaar via:

- **https://nextcloud.guu.st**
- **https://files.guu.st**

Beide domeinen wijzen naar dezelfde Nextcloud instantie. De primaire URL is `nextcloud.guu.st` (ingesteld als `OVERWRITEHOST`).

---

## Componenten

| Container | Image | Functie |
|---|---|---|
| `nextcloud` | `nextcloud:latest` | Nextcloud applicatie (Apache + PHP) |
| `nextcloud_db` | `mariadb:11` | Database |
| `nextcloud_redis` | `redis:7-alpine` | Caching + file locking |
| `nextcloud_cron` | `nextcloud:latest` | Achtergrondtaken (elke 5 minuten) |

### Caching
- **APCu** — lokale in-memory cache (ingebouwd in het Nextcloud image)
- **Redis** — gedistribueerde cache én file locking (via `REDIS_HOST` env var automatisch geconfigureerd)

---

## Bestandsstructuur

```
/home/bas/nextcloud/
├── docker-compose.yml                # Stack definitie
├── .env                              # Wachtwoorden en domeinnamen
└── config/
    └── apcu.config.php               # APCu cache + systeeminstellingen
```

### Data opslag
Gebruikersbestanden worden opgeslagen op:
```
/mnt/Pruimenboom/Nextcloud/
```
Dit is een bind mount naar `/var/www/html/data` in de container.

De Nextcloud applicatiebestanden (PHP, config, apps) zitten in een Docker volume: `nextcloud_nextcloud_html`.
De database zit in Docker volume: `nextcloud_nextcloud_db`.

---

## Nginx

De reverse proxy configuratie staat in:
```
/home/bas/nginx-proxy/conf.d/nextcloud.guu.st.conf
```

Beide domeinen (`nextcloud.guu.st` en `files.guu.st`) staan in één server block. Nginx proxiet naar `http://10.0.0.3:8457`.

Relevante instellingen:
- `client_max_body_size 10G` — grote bestanden toestaan
- `proxy_buffering off` — nodig voor uploads/downloads
- CalDAV/CardDAV redirects naar `/remote.php/dav`
- Security headers (HSTS, X-Frame-Options, etc.)

---

## SSL certificaat

Het certificaat dekt alle `guu.st` subdomeinen en wordt beheerd door Certbot. `nextcloud.guu.st` en `files.guu.st` zijn toegevoegd met:

```bash
cd /home/bas/nginx-proxy && docker compose run --rm --entrypoint certbot certbot \
  certonly --webroot -w /var/www/certbot --expand --force-renewal \
  -d guu.st -d bas.guu.st -d copyparty.guu.st -d home.guu.st \
  -d notes.guu.st -d notesnook-auth.guu.st -d notesnook-mono.guu.st \
  -d notesnook-s3.guu.st -d notesnook-sse.guu.st -d notesnook.guu.st \
  -d owncloud.guu.st -d todo.guu.st -d video.guu.st \
  -d nextcloud.guu.st -d files.guu.st
```

---

## Netwerk & toegang

- Extern: via `nextcloud.guu.st` / `files.guu.st`
- Lokaal: voeg in **Pihole → Local DNS** toe:
  - `nextcloud.guu.st` → `10.0.0.3`
  - `files.guu.st` → `10.0.0.3`

Het lokale netwerk `10.0.0.0/8` staat op de brute-force whitelist, zodat de macOS/desktop client niet geblokkeerd wordt.

---

## Beheer

**Stack starten/stoppen:**
```bash
cd /home/bas/nextcloud
docker compose up -d
docker compose down
```

**Nextcloud CLI (occ):**
```bash
docker exec -u www-data nextcloud php occ <commando>
```

**Achtergrondtaken (cron) instellen** (eenmalig na installatie):
```bash
docker exec -u www-data nextcloud php occ background:cron
```

**Onderhoudsvenster** wordt automatisch ingesteld via `config/apcu.config.php` (01:00–05:00 UTC = 02:00–06:00 CET).

**Brute-force reset voor een IP:**
```bash
docker exec -u www-data nextcloud php occ security:bruteforce:reset <ip>
```

**Data directory permissies herstellen** (nodig na herinstallatie):
```bash
docker run --rm -v /mnt/Pruimenboom/Nextcloud:/data alpine chown -R 33:33 /data
```

**Opnieuw installeren** (wist alle data!):
```bash
docker compose down
docker volume rm nextcloud_nextcloud_html nextcloud_nextcloud_db
docker run --rm -v /mnt/Pruimenboom/Nextcloud:/data alpine chown -R 33:33 /data
docker compose up -d
```

---

## Inloggegevens

Zie `/home/bas/nextcloud/.env` voor wachtwoorden.
