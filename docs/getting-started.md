[Back to README](../README.md) · [Architecture →](architecture.md)

# Getting Started

## Prerequisites

| Tool | Version |
|------|---------|
| PHP | 8.3+ |
| Composer | 2.x |
| Node.js | 18+ |
| PostgreSQL | 14+ (or Supabase project) |
| Docker | Optional, for local dev |

## Installation

```bash
git clone <repo>
cd ai-budget-back

composer install && npm install
cp .env.example .env
php artisan key:generate
```

Fill in the required env vars (see [Configuration](configuration.md)):
- `DB_*` — PostgreSQL connection
- `JWT_SECRET` — signs our access tokens (required)
- `GOOGLE_CLIENT_IDS`, `APPLE_CLIENT_IDS` — social sign-in (optional until enabled)
- `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE` — Storage only
- `OPENAI_API_KEY`
- `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`

```bash
php artisan migrate
php artisan app:seed-initial   # categories, plans, statuses, visibility questions
npm run build
```

## Running Locally

All services at once:

```bash
composer run dev
```

Or individually:

```bash
php artisan serve --host=0.0.0.0 --port=8080   # HTTP server
php artisan queue:listen --tries=1 --timeout=0  # Queue worker
php artisan pail --timeout=0                    # Log viewer
npm run dev                                     # Vite (admin assets)
```

## Running with Docker

```bash
cp .env.example .env.docker
# Edit .env.docker: set APP_URL=http://localhost:8080, DB credentials

docker compose up --build
```

App available at `http://localhost:8080`.

## Running Tests

Tests run inside Docker (uses `DB_HOST=db` from `.env.testing`):

```bash
docker compose exec app php artisan test
docker compose exec app php artisan test --filter TestName   # single test
```

## Admin Panel

Access at `/admin`. Set `ADMIN_PASSWORD` in `.env`, then seed the admin user:

```bash
php artisan db:seed --class=AdminUserSeeder
```

Login with email `oldu2002@gmail.com` and your `ADMIN_PASSWORD`.

## See Also

- [Configuration](configuration.md) — all environment variables
- [Deployment](deployment.md) — Railway and Docker production setup
- [Architecture](architecture.md) — project structure and patterns
