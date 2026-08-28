# PiDoors

Raspberry Pi access-control system. This tree is a fork of `sybethiesant/pidoors`; installs and in-app updates use `GITHUB_REPO` (`charlesleavitt13/pidoors`). Current version is in `VERSION` (0.4.4).

The live product is the **React SPA + PHP REST API**. Legacy Bootstrap PHP pages under `pidoorserv/*.php` still exist on disk; do not treat them as the source of truth, and do not let them drift from the API/SPA contract.

Full user-facing docs: `README.md` and `pidoors/INSTALLATION_GUIDE.md`. This file is for agents working in the repo.

## Layout

| Path | Role |
|------|------|
| `pidoors-ui/` | React 19 + Vite + Tailwind SPA (`npm run build` → `dist/`) |
| `pidoorserv/` | PHP API. Live backend is **`api.php`** (nginx `PATH_INFO` router). |
| `pidoors/` | Python door/gate controller (`pidoors.py` + `readers/`, `formats/`) |
| `nginx/pidoors.conf` | SPA at `/`, `/api/*` → `/var/www/pidoors/api.php` |
| `install.sh` / `uninstall.sh` | Server, door, or combined install |
| `server-update.sh` | Server self-update from GitHub release tarball |
| `pidoors/pidoors-update.sh` | Controller self-update |
| `build-release.sh` | Build SPA, pack `release/vX.Y.Z.tar.gz` (+ `.sha256`) |
| `database_migration.sql` | Access schema + extensions; **safe to re-run** |
| `docker/` | Dev/test compose with mock GPIO; not the live Pi |

MariaDB databases: `users` (accounts, audit) and `access` (cards, doors, logs, schedules, groups). Config templates: `pidoorserv/includes/config.php.example`, `pidoors/conf/config.json.example`. Real configs are gitignored.

## This machine: git tree vs live

This workspace is a **combined server + controller** on the same Pi. Editing the git checkout does **not** change the running system.

| Role | Live path | systemd / service |
|------|-----------|-------------------|
| PHP API | `/var/www/pidoors` | php-fpm + nginx |
| React SPA | `/var/www/pidoors-ui` | nginx root |
| Controller | `/opt/pidoors` | `pidoors.service` (`WorkingDirectory=/run/pidoors`) |

When the user asks to deploy:

- API: copy `pidoorserv/*` **and** `VERSION` (and `database_migration.sql` when schema changed) to `/var/www/pidoors`. Never copy a single PHP file in isolation.
- SPA: `cd pidoors-ui && npm run build`, then copy `dist/*` to `/var/www/pidoors-ui`. Tell the user to **hard-refresh**; `index.html` is no-cache, hashed JS/CSS is immutable.
- Controller: copy Python into `/opt/pidoors` and `sudo systemctl restart pidoors`. Do not restart just to “refresh GPIO” if hold-open may be active unless the change requires it.

Partial deploys have already broken login: `api.php` `require_once` of missing `includes/github.php` became a PHP fatal, which the SPA reports as **Failed to fetch CSRF token**. `api.php` now optional-loads `github.php`; still deploy the whole `pidoorserv/` tree.

## Access-control facts (easy to get wrong)

- **Door grant is `cards.doors`**: comma-separated door names, or `*`. Controller cache/online lookup uses `FIND_IN_SET` on that column (spaces stripped). `access_groups.doors` is JSON used by the UI; `group_id` is **not** consulted at the reader. Import copies group door names onto the card when `doors` is empty (`csv_copy_group_doors`). A card with empty `doors` is denied everywhere.
- **`card_id` is required** and is also the **keypad PIN**. There is no first-scan enrollment that fills an empty `card_id`. The controller unknown-card path inserts a **new inactive** row. Do not invent `card_id` server-side. Unique key is `(card_id, facility)`. Empty `facility` often fails to match a scan.
- **`pin_code` is a dead column.** Keypad (Wiegand, `keypad_enabled`, 4-digit + `#`) authorizes online against `cards.card_id`. PIN needs a live DB. CSV `pin_code` is ignored; export omits it.
- CSV import (`POST /api/cards/import`): required `card_id`, `user_id`. Export includes `doors` and `active` so round-trip works. SPA import UI is at `/cards/import`.
- SPA is same-origin `/api/...`. Mutating calls send `X-CSRF-Token`. Sessions: `credentials: 'same-origin'`.

## Gates, hold-open, GPIO

Gate mode, double-gate (inbound/outbound lanes), Soft Open Cycle, scheduled hold-open, and status LED live in `pidoors/pidoors.py` plus door JSON in the DB/UI.

- Soft Open Cycle: opener times itself after a trigger pulse. Normal open reports opening → closing → closed over `soft_cycle_seconds`.
- After **hold-open release**, if that open relay has `soft_cycle`, report **closing** then **closed** after **half** of Open Cycle Duration. If soft cycle is off, leave status **open**.
- Hourly cache sync must **not** re-init unchanged gate GPIO. `setup_gate_io()` is a no-op when `cfg == gate_config`; rewriting pins drops hold relays and desyncs the UI. After a real setup/restart, persisted hold must **re-assert** open relays and resume the hold thread.
- Dashboard door cards for double gates should match the Doors page layout (per-lane controls). UI reads `gate_state` / `gate_inbound_state` / `gate_outbound_state` from the controller (poll ~3–5s).

OSDP / PN532 / MFRC522 modules exist under `pidoors/readers/` but are **not integrated** as production reader paths. Wiegand is.

## TLS, IP, and exposure

Two TLS worlds:

1. **Dashboard nginx** (`/etc/ssl/certs/pidoors.crt`) — may be replaced with Let's Encrypt for a public hostname.
2. **Internal PiDoors CA** (`/etc/mysql/ssl/ca.pem`, controller `conf/ca.pem`, push listener certs) — MariaDB TLS and door push. **Do not** replace this with LE. Controllers fail closed if `ca.pem` is missing (no cleartext DB fallback). `/ca.pem` is served over HTTP for bootstrap.

On a **combined** Pi, MariaDB is `127.0.0.1`; changing the LAN IP does not stop GPIO or local DB. It **does** stale: nginx cert SAN, push-listener cert SAN, `config.php` `url`, remote-controller `sqladdr`, and MariaDB grants baked at install. Dashboard gate commands go over HTTPS push — if the cert SAN is wrong, buttons fail even though cards still work locally.

**Do not expose the UI/API/MariaDB/push listener to the public internet** as built: password-only admin can unlock doors, dump backups, and run host updates. Installer UFW may leave 3306 open; UFW is often inactive on Raspberry Pi OS. Enrollment token is **not** the DB password; it is required for `POST /api/certs/sign`.

## Database and ops

- App DB user is `pidoors` with **DML only** (no `LOCK TABLES` / `DROP` / `TRUNCATE`). Wipe access logs with `DELETE FROM access.logs;` not `TRUNCATE`.
- Backups: `mysqldump` must use `--single-transaction --skip-lock-tables`; do not merge stderr into the SQL file. Prefer that over a PDO fallback.
- `api.php` auto-runs `database_migration.sql` when `VERSION` ≠ `settings.server_version`.
- Logs table grows without bound; dashboard `/api/*` polling is chatty. SD-card endurance notes: `pidoors/INSTALLATION_GUIDE.md` (controller skips no-op cache writes; nginx access_log off for API). Combined MariaDB-on-SD is the high-wear case.

## Release and updates

- Bump `VERSION`, run `./build-release.sh` (optionally `--publish`). Updaters **refuse** a tarball without a matching `.sha256`.
- In-app updates download from `charlesleavitt13/pidoors` via `pidoorserv/includes/github.php`.
- Do not put DB passwords on process argv (`server-update.sh` reads config or `PIDOORS_DB_PASS`).

## Conventions

- Prefer changing `api.php` + SPA (`pidoors-ui/src`) together. Keep CSV/API/UI/docs on **one** card contract.
- PHP: PDO prepared statements, `require_csrf()` on mutating API, `require_admin_auth()` for admin routes. bcrypt cost 12; API login rejects legacy MD5.
- UI: Tailwind, React Query, `pidoors-ui/src/api/*.ts`. Admin-only pages wrap `RequireAdmin`.
- Python: keep GPIO/hold-open behavior power-loss and cache-sync safe. `ProtectSystem=strict` — the service cannot write `__pycache__` under `/opt/pidoors`.
- Secrets stay out of git (`config.php`, `config.json`, `*.remote`). Do not commit live passwords or enrollment tokens.
- Tests under `tests/` are gitignored; there is no in-repo CI suite to run by default.

## Current branch

`feature/user-credentials` (uncommitted work has included CSRF/`github.php` deploy safety and backup dump flags). Confirm `git status` before assuming that is still true.
