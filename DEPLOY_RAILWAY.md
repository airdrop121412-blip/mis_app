# MIS Backend — Railway Deployment

Laravel 12 API (`/api/v1/*`, 210 routes, Sanctum). This folder (`backend/`) is the
deploy root: the `Dockerfile` runs from here.

## 1. GitHub (backend only)

```bash
cd backend
git init
git add Dockerfile docker/start.sh railway.json .dockerignore .env.railway.example composer.json composer.lock artisan public/index.php bootstrap/app.php routes/ config/ database/ app/ resources/ .gitignore .gitattributes README.md
git status            # review the list; .env / vendor / logs must NOT appear
git commit -m "Prepare Laravel backend for Railway deployment"
git remote add origin https://github.com/airdrop121412-blip/mis_app.git
git branch -M main
git push -u origin main
```

Never commit: `.env`, `vendor/`, `storage/logs/*`, `*.log`, `*.accdb`, `*.zip`,
local `check_*.php` / `demo_*.php` / `*_rollback.php` debug scripts.

## 2. Railway service

1. New Project → **Deploy from GitHub repo** → select `mis_app`, set **Root Directory = `backend`** (or make this repo root the backend folder).
2. Builder: Dockerfile (via `railway.json`). Health check: `GET /api/v1/health`.
3. Add a **MySQL** plugin/database to the project.
4. Variables → Raw Editor, set (no secrets in git):
   `APP_NAME`, `APP_ENV=production`, `APP_KEY` (= `php artisan key:generate --show` output),
   `APP_DEBUG=false`, `APP_URL=https://<your-service>.up.railway.app`,
   `DB_CONNECTION=mysql`, `DB_HOST=${{MySQL.MYSQLHOST}}`, `DB_PORT=${{MySQL.MYSQLPORT}}`,
   `DB_DATABASE=${{MySQL.MYSQLDATABASE}}`, `DB_USERNAME=${{MySQL.MYSQLUSER}}`,
   `DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}`,
   `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`,
   `SANCTUM_STATEFUL_DOMAINS=<frontend-domain>`, `FRONTEND_URL=https://<frontend-domain>`.
5. Deploy. `docker/start.sh` runs `php artisan migrate --force` automatically (48 migrations).
   For a fresh DB also run once: `php artisan db:seed --force` **only if** `DatabaseSeeder`
   is safe for production; otherwise seed master data from the reviewed SQL.

## 3. Verify

- `GET /up` → 200 (Laravel).
- `GET /api/v1/health` → `{success:true, data:{status:"ok", database:true}}`.
- `POST /api/v1/auth/login` with a seeded user → token.
- Authenticated `GET /api/v1/auth/me`, `GET /api/v1/ep`, `GET /api/v1/dashboard/stats`.

Existing contracts are unchanged: routes stay under `/api/v1`, Sanctum Bearer + SPA cookie both supported.
