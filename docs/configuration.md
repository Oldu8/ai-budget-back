[← Architecture](architecture.md) · [Back to README](../README.md) · [Deployment →](deployment.md)

# Configuration

All configuration is driven by environment variables. Copy `.env.example` to `.env` and fill in the required values.

## Required Variables

### Application

| Variable | Example | Description |
|----------|---------|-------------|
| `APP_KEY` | `base64:...` | Laravel encryption key — generate with `php artisan key:generate` |
| `APP_ENV` | `production` | `local`, `staging`, or `production` |
| `APP_URL` | `https://your-domain.com` | Full URL, no trailing slash. Used for asset URLs and Stripe redirects |
| `APP_DEBUG` | `false` | Set `false` in production |

### Database

| Variable | Description |
|----------|-------------|
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | PostgreSQL host |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | Database name |
| `DB_USERNAME` | Database user |
| `DB_PASSWORD` | Database password |

### Auth (our own JWT)

| Variable | Description |
|----------|-------------|
| `JWT_SECRET` | Secret for signing access tokens (required). Generate a long random string. |
| `JWT_TTL` | Access token lifetime, seconds (default `900`) |
| `JWT_REFRESH_TTL` | Refresh token lifetime, seconds (default `2592000`) |
| `GOOGLE_CLIENT_IDS` | Comma-separated allowed Google OAuth client ids (the `aud` of the id_token) |
| `APPLE_CLIENT_IDS` | Comma-separated allowed Apple client/bundle ids |

### Supabase (Postgres + Storage only — not auth)

| Variable | Description |
|----------|-------------|
| `SUPABASE_URL` | Your Supabase project URL (e.g. `https://xxxx.supabase.co`) — used for Storage |
| `SUPABASE_SERVICE_ROLE` | Service role key — server-side Storage access (never expose) |
| `SUPABASE_STORAGE_BUCKET` | Storage bucket name for receipt images (default: `receipts`) |

### OpenAI

| Variable | Description |
|----------|-------------|
| `OPENAI_API_KEY` | API key for GPT-4o-mini receipt scanning and Whisper voice transcription |
| `OPENAI_AUDIO_MODEL` | Audio model name (default: `whisper-1`). Override only when OpenAI changes the model name |

### Stripe

| Variable | Description |
|----------|-------------|
| `STRIPE_SECRET_KEY` | Stripe secret key (`sk_live_...` or `sk_test_...`) |
| `STRIPE_PUBLIC_KEY` | Stripe publishable key |
| `STRIPE_WEBHOOK_SECRET` | Webhook signing secret (`whsec_...`) from Stripe dashboard |
| `STRIPE_SUCCESS_URL` | Redirect after successful checkout (defaults to `APP_URL/stripe/checkout/success`) |
| `STRIPE_CANCEL_URL` | Redirect after canceled checkout (defaults to `APP_URL/stripe/checkout/cancel`) |

**Stripe Price IDs** — set one per plan per currency:

| Variable | Description |
|----------|-------------|
| `STRIPE_BASIC_PRICE_USD` | Basic plan USD price ID |
| `STRIPE_BASIC_PRICE_EUR` | Basic plan EUR price ID |
| `STRIPE_PLUS_PRICE_USD` | Plus plan USD price ID |
| `STRIPE_PLUS_PRICE_EUR` | Plus plan EUR price ID |
| `STRIPE_PREMIUM_PRICE_USD` | Premium plan USD price ID |
| `STRIPE_PREMIUM_PRICE_EUR` | Premium plan EUR price ID |

### Admin Panel

| Variable | Description |
|----------|-------------|
| `ADMIN_PASSWORD` | Password for the admin panel user (email set in `AdminUserSeeder`) |

## Optional Variables

### API Key Guard

| Variable | Description |
|----------|-------------|
| `API_SECRET` | When set, all `/api/*` requests must include `X-API-Key: <value>`. Use for server-side callers (cron jobs, backend services). **Do not expose in mobile/web clients.** |

### Discord Notifications

All Discord variables are optional. If unset, notifications are silently skipped.

| Variable | Channel | Description |
|----------|---------|-------------|
| `DISCORD_WEBHOOK_URL` | General | Fallback webhook used when a specific one is unset |
| `DISCORD_ERRORS_WEBHOOK_URL` | `#error-logs` | App errors and exceptions |
| `DISCORD_REGISTRATIONS_WEBHOOK_URL` | `#new-users` | New user registrations |
| `DISCORD_PAYMENTS_WEBHOOK_URL` | `#payment-info` | Subscription started / canceled |
| `DISCORD_REGISTRATION_INCLUDE_EMAIL` | — | `true` to include full email in registration messages (default: `false`) |

To create a webhook: Discord channel → **Settings** → **Integrations** → **Webhooks** → **New Webhook** → **Copy URL**.

## Feature Access Config

Plan-to-feature mappings live in `config/feature_access.php`. This is the single source of truth — no feature flags are hardcoded in services.

```php
// config/feature_access.php
'ai_scan' => [
    'plans' => ['free', 'basic', 'plus', 'premium'],
    'quota_field' => 'check_limit',
],
'recurring_expenses' => [
    'plans' => ['plus', 'premium'],
],
```

To add a new gated feature, add an entry here and use `feature.access:<name>` middleware on the route.

## Logging

| Variable | Default | Description |
|----------|---------|-------------|
| `LOG_CHANNEL` | `stack` | Laravel log channel |
| `LOG_LEVEL` | `debug` | `debug`, `info`, `warning`, `error` |

## See Also

- [Getting Started](getting-started.md) — installation and first run
- [Architecture](architecture.md) — how the app uses these variables
- [Deployment](deployment.md) — production environment setup
