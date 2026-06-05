[← Getting Started](getting-started.md) · [Back to README](../README.md) · [Configuration →](configuration.md)

# Architecture

## Overview

Laravel 13 REST API with a **Layered Architecture** — HTTP, Service, and Data layers strictly separated. Controllers are thin HTTP adapters; all business logic lives in Services.

```
Mobile App (our JWT)
    ↓ Authorization: Bearer <access_token>
API Routes (/api/*)
    ↓
AuthenticateAppUser middleware → verifies our JWT locally, loads AppUser by sub
    ↓
Controllers → Services → Eloquent → PostgreSQL (Supabase)

Admin Routes (/admin)
    ↓
Laravel Auth middleware (session)
    ↓
Admin Controllers → Blade Views
```

## Two Auth Systems

The project has two completely separate authentication paths. Never mix them.

| | Mobile API | Admin Panel |
|---|---|---|
| Model | `AppUser` (`app_users`) | `User` (`users`) |
| Auth | Our own JWT (email/password + Google/Apple) | Laravel session |
| Middleware | `auth.app` | `auth` |
| Guard | `app` | `web` |

The `AuthenticateAppUser` middleware verifies our access JWT locally (signature + exp — no network call), loads the `AppUser` by the `sub` claim (rejecting missing/soft-deleted users), and lazily provisions a free plan on first request.

## Folder Structure

```
app/
├── Http/
│   ├── Controllers/          # HTTP layer — validate, call service, return response
│   │   └── Admin/            # Admin panel (Blade)
│   ├── Middleware/
│   │   ├── AuthenticateAppUser.php          # Our JWT validation + load AppUser
│   │   ├── EnsureFeatureAccess.php          # Plan quota enforcement
│   │   └── EnsureApiKey.php                 # Optional X-API-Key guard
│   └── Requests/             # Form Requests for input validation
├── Services/                 # Business logic — all domain rules live here
│   ├── Scan/                 # Receipt scanning (OpenAI)
│   ├── Voice/                # Voice expense parsing
│   ├── FeatureAccessService.php
│   ├── EnsureDefaultSubscriptionService.php
│   └── DiscordNotifier.php
├── Models/                   # Eloquent — data, relationships, casts only
├── Enums/                    # PlanType, TransactionType, TransactionCreatedBy
└── AccessGuard/              # FeatureAccessResult DTO

config/
├── feature_access.php        # Plan-to-feature mapping (single source of truth)
└── services.php              # External service credentials

routes/
├── api.php                   # Mobile API routes
└── web.php                   # Admin + Stripe redirect routes
```

## Dependency Rules

```
Controllers → Services → Models → Database
```

- ✅ Controllers inject Services via constructor
- ✅ Services query Models / call other Services
- ❌ Controllers must NOT contain business logic or direct Eloquent queries
- ❌ Services must NOT return HTTP responses or use `request()` / `auth()`
- ❌ Models must NOT call Services

## Transaction Lifecycle

| Type | How created | `confirmed_at` |
|------|-------------|----------------|
| Manual | `POST /api/transactions` | Set immediately |
| AI Scan | `POST /api/transactions/scan` | `null` (draft) |
| Recurring | Artisan scheduler | Set immediately |

Drafts (`confirmed_at IS NULL`) are invisible in all list and summary endpoints. Client confirms a draft via `PUT /api/transactions/{id}/update`.

## Billing & Feature Gates

- **Stripe webhooks are the only source of truth** — subscription state is never polled
- `FeatureAccessService` evaluates plan type + optional quota
- All plan-to-feature mappings live in `config/feature_access.php`
- Routes are gated via `feature.access:<feature_name>` middleware

**Cancel-at-period-end behavior:** when a user cancels immediately, Stripe fires `customer.subscription.deleted` with a future `current_period_end`. The app marks the subscription `canceled` with `cancel_at_period_end = true` and preserves access until the period ends. The middleware lazily provisions the free plan on the user's first request after expiry.

**Features:**

| Feature | Plans |
|---------|-------|
| `ai_scan` | Free (quota), Basic, Plus, Premium |
| `voice_expense` | Premium |
| `recurring_expenses` | Plus, Premium |
| `csv_export` | Basic, Plus, Premium |
| `analytics_dashboard` | Plus, Premium |

## Subscription Plans

| Type | Label | Paid |
|------|-------|------|
| `free` | Free | No |
| `basic` | Basic | Yes |
| `plus` | Plus | Yes |
| `premium` | Premium | Yes |

See [plans.md](plans.md) for full pricing and limits.

## See Also

- [Configuration](configuration.md) — environment variables and feature flags
- [Getting Started](getting-started.md) — local setup
- [Deployment](deployment.md) — production deployment
