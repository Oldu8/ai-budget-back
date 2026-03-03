# AI Budget: Backend Technical Specification (v1.3)

## Changelog

| Version | Changes |
| ------- | ------- |
| v1.3 | Auth proxy: register/login/refresh/logout через бэкенд. Добавлен AuthController, клиентская инструкция, описание env-переменных. |
| v1.2 | Двухсущностная авторизация (AppUser + User), backend-spec обновлён под Laravel. |
| v1.1 | Исправления: Authenticatable, config(), миграции, AppUserSettings. |

## 1. Project Overview

Backend API for AI Budget — mobile app for personal budget management.
Users can manually log income/expenses or use AI (photo of receipt) to auto-extract transaction data.

### Core Responsibilities

- Authenticate mobile app users via Supabase Auth (JWT validation through service role)
- Authenticate admin users via standard Laravel Auth (session-based)
- Process receipt images using OpenAI GPT-4o-mini (JSON mode)
- Manage hierarchical expense/income categories
- Handle subscription billing and AI-scan usage limits via Stripe
- CRUD for transactions (manual + AI-created)

## 2. Tech Stack

| Layer      | Technology                                 |
| ---------- | ------------------------------------------ |
| Framework  | Laravel 12 (PHP 8.2+)                      |
| Database   | PostgreSQL (managed via Supabase)           |
| ORM        | Eloquent ORM                               |
| Migrations | Laravel Migrations (`php artisan migrate`) |
| Auth       | Supabase Auth (mobile) + Laravel Auth (admin) |
| AI         | OpenAI SDK (gpt-4o-mini, JSON mode)        |
| Payments   | Stripe SDK (Subscriptions & Webhooks)      |
| Storage    | Supabase Storage (receipt images)          |
| Host       | Railway.app                                |

## 3. Authentication Architecture

### 3.1. Two-Entity Model

The system has two separate user entities with different auth mechanisms:

**AppUser (`app_users`)** — Mobile application users.
- Authenticated via Supabase Auth (JWT tokens)
- Custom middleware `supabase.auth` validates token against Supabase `/auth/v1/user`
- Auto-created on first request (firstOrCreate by `supabase_user_id`)
- Used for: transactions, subscriptions, payments, AI features
- Model: `App\Models\AppUser` (extends Authenticatable)

**User (`users`)** — Admin panel users.
- Authenticated via standard Laravel Auth (session + password)
- Guard: `web` (default Laravel guard)
- Used for: admin panel access, managing categories/plans/statuses
- Model: `App\Models\User` (extends Authenticatable)

### 3.2. Auth Guards (config/auth.php)

| Guard | Driver  | Provider   | Purpose          |
| ----- | ------- | ---------- | ---------------- |
| `web` | session | `users`    | Admin panel      |
| `app` | session | `app_users`| Mobile (backup)  |

Mobile API routes use the `supabase.auth` middleware (not Laravel guards).

### 3.3. AppUser Auth Flow (Backend Proxy)

All auth goes through the backend. The client never talks to Supabase directly.
Backend proxies requests to Supabase Auth API and returns tokens to the client.

**Registration:**

```
Mobile App                     Backend API                     Supabase
    |                              |                               |
    |-- POST /api/auth/register -->|                               |
    |   { email, password }        |                               |
    |                              |-- POST /auth/v1/signup -----> |
    |                              |   apiKey: SUPABASE_ANON_KEY   |
    |                              |<-- { user, access_token } --- |
    |                              |                               |
    |                              |   AppUser::firstOrCreate()    |
    |                              |   (new record in app_users)   |
    |                              |                               |
    |<-- 201 --------------------- |                               |
    |   { access_token,            |                               |
    |     refresh_token,           |                               |
    |     user }                   |                               |
```

1. Client sends `POST /api/auth/register` with `{ email, password }`
2. Backend validates input, sends signup request to Supabase Auth API
3. Supabase creates the auth user, returns JWT tokens
4. Backend creates `AppUser` record via `firstOrCreate(supabase_user_id)`
5. Backend returns `access_token`, `refresh_token`, and user profile to client

**Login:**

```
Mobile App                     Backend API                     Supabase
    |                              |                               |
    |-- POST /api/auth/login ----> |                               |
    |   { email, password }        |                               |
    |                              |-- POST /auth/v1/token ------> |
    |                              |   ?grant_type=password        |
    |                              |<-- { user, access_token } --- |
    |                              |                               |
    |                              |   AppUser::firstOrCreate()    |
    |                              |   (existing record found)     |
    |                              |                               |
    |<-- 200 --------------------- |                               |
    |   { access_token,            |                               |
    |     refresh_token,           |                               |
    |     user + settings }        |                               |
```

1. Client sends `POST /api/auth/login` with `{ email, password }`
2. Backend sends credentials to Supabase `/auth/v1/token?grant_type=password`
3. Supabase validates credentials, returns JWT tokens
4. Backend finds/creates `AppUser`, loads settings
5. Backend returns tokens + user profile to client

**Token refresh:**

```
Mobile App                     Backend API                     Supabase
    |                              |                               |
    |-- POST /api/auth/refresh --> |                               |
    |   { refresh_token }          |-- POST /auth/v1/token ------> |
    |                              |   ?grant_type=refresh_token   |
    |                              |<-- { new tokens } ----------- |
    |<-- { access_token,           |                               |
    |      refresh_token } ------- |                               |
```

**Logout:**

```
Mobile App                     Backend API                     Supabase
    |                              |                               |
    |-- POST /api/auth/logout ---> |                               |
    |   Authorization: Bearer <t>  |-- POST /auth/v1/logout -----> |
    |<-- { message: "Logged out" } |                               |
```

**Key points:**
- Client stores `access_token` and `refresh_token` locally (e.g. SecureStorage)
- `access_token` is sent as `Authorization: Bearer <token>` on all protected routes
- When `access_token` expires → client calls `/api/auth/refresh` with `refresh_token`
- Auth routes (`/api/auth/*`) are public — no middleware required
- Protected routes (`/api/me/*`) use `supabase.auth` middleware that validates the token
- `supabase_user_id` is the bridge between Supabase Auth and `app_users` table
- Sensitive fields (`supabase_user_id`, `payment_customer_id`) are hidden from JSON responses

### 3.4. API Routes

**Public (no auth required):**

| Method | Route                | Action              | Body                     |
| ------ | -------------------- | ------------------- | ------------------------ |
| `POST` | `/api/auth/register` | Register new user   | `{ email, password }`    |
| `POST` | `/api/auth/login`    | Login               | `{ email, password }`    |
| `POST` | `/api/auth/refresh`  | Refresh tokens      | `{ refresh_token }`      |
| `POST` | `/api/auth/logout`   | Logout              | Header: `Bearer <token>` |

**Protected (requires `Authorization: Bearer <token>`):**

| Method   | Route              | Action                        |
| -------- | ------------------ | ----------------------------- |
| `GET`    | `/api/me`          | Get profile + settings        |
| `PUT`    | `/api/me`          | Update profile                |
| `DELETE` | `/api/me`          | Soft-delete account           |
| `GET`    | `/api/me/settings` | Get user settings             |
| `POST`   | `/api/me/settings` | Create settings (onboarding)   |
| `PUT`    | `/api/me/settings` | Update user settings          |

### 3.5. Client Implementation

**Token storage:**
- Сохранять `access_token` и `refresh_token` в SecureStorage / Keychain (не в обычном storage)
- При успешном register/login — записать оба токена

**Protected requests:**
- Добавлять заголовок `Authorization: Bearer <access_token>` ко всем запросам к `/api/me*` и другим защищённым эндпоинтам
- При 401 — попробовать `/api/auth/refresh` с `refresh_token`, затем повторить исходный запрос
- Если refresh вернул 401 — очистить токены и перенаправить на экран логина

**Example flow:**
```
1. POST /api/auth/register { email, password } → 201 { access_token, refresh_token, user }
2. Сохранить токены, экран онбординга
3. POST /api/me/settings { currency, language, timezone } → 201 (создание настроек)
4. GET /api/me + Authorization: Bearer <access_token> → профиль
5. При истечении токена: POST /api/auth/refresh { refresh_token } → новые токены
6. POST /api/auth/logout + Authorization: Bearer <token> → выход
```

**Settings:**
- `POST /api/me/settings` — для онбординга после регистрации. Создаёт настройки один раз. При повторном вызове → 409.
- `PUT /api/me/settings` — для изменения уже существующих настроек.

## 4. Database Schema

### 4.1. Users & Settings

**app_users** — mobile user profile linked to Supabase Auth and Stripe.
- `supabase_user_id` (uuid, unique) — link to Supabase Auth
- `email` (unique)
- `first_name`, `last_name` (nullable)
- `payment_customer_id` (nullable) — Stripe customer ID
- Soft deletes enabled

**users** — admin panel users (standard Laravel).
- `name`, `email`, `password`
- Standard Laravel Authenticatable

**app_user_settings** — per-user preferences (1:1 with app_users).
- `currency`, `language`, `timezone` (IANA format)
- Timezone is used to calculate correct transaction dates relative to user location

### 4.2. Categories

**categories** — static hierarchical table of predefined categories.
- `category_id` (self-FK, nullable): null = Level 1 (e.g. Housing), set = Level 2 (e.g. Rent)
- `label` — display name for the client
- `visibility_tag` (nullable) — metadata (e.g. kids, car, pets) for bulk-toggling

### 4.3. Transactions

**transactions** — financial records (manual or AI-created).
- `amount` — integer (in cents/smallest currency units, no floating point)
- `type` — enum: `income`, `expense`
- `created_by` — enum: `user`, `ai`
- `category_id` — FK to categories (restrictOnDelete — cannot delete category with transactions)
- `raw_ai_data` (JSON, nullable) — full OpenAI response for AI-created transactions
- `confirmed_at` (nullable) — timestamp when user confirmed AI suggestion
- `img_path` (nullable) — path to receipt image in Supabase Storage
- Soft deletes enabled
- Indexed: (app_user_id, date), (app_user_id, category_id, date)

### 4.4. Subscriptions & Billing (Stripe)

**plans** — catalog of subscription tiers (no timestamps).
- `type` — enum: `free`, `basic`, `standard`, `premium`
- `payment_price_id` (nullable) — Stripe Price ID (null for free plan)
- `price` — integer (cents)
- `check_limit` — max AI scans per billing cycle

**user_subscriptions** — real-time subscription state per user.
- `payment_subscription_id` — Stripe Subscription ID
- `status_id` — FK to statuses
- `used_checks_count` — incremented after each successful AI scan
- `current_period_end` — billing cycle end
- `cancel_at_period_end` — boolean
- Trial fields: `trial_start`, `trial_end`

**payments** — individual payment records linked to Stripe invoices.
- `payment_invoice_id` — Stripe Invoice ID
- `status_id` — FK to statuses
- `amount` — integer (cents)
- `currency`

**statuses** — normalized dictionary for subscription/payment states (no timestamps).
- Values: `active`, `canceled`, `pending`, `trialing`, `past_due`

## 5. Key Business Rules

### 5.1. AI Receipt Processing Pipeline

1. Receive image path and context from client
2. Fetch user's active categories
3. Construct prompt with categories list, send to OpenAI (JSON mode)
4. OpenAI returns: amount, date, vendor, category_id
5. Create Transaction with `created_by = 'ai'`, `confirmed_at = null`
6. User reviews and confirms → set `confirmed_at`
7. Increment `used_checks_count` in UserSubscription

### 5.2. Quota Enforcement

Before calling OpenAI:
- Verify `user_subscription.used_checks_count < plan.check_limit`
- If limit reached → 403 Forbidden with "Limit Exceeded"

### 5.3. Stripe Webhook Handling

- `checkout.session.completed` → activate new subscription
- `invoice.paid` → reset `used_checks_count` to 0 for new billing cycle
- `customer.subscription.deleted` → update status to canceled, restrict access

## 6. Environment Variables

| Variable | Purpose |
| -------- | ------- |
| `DB_CONNECTION` | `pgsql` — использовать PostgreSQL |
| `DB_URL` | Connection string к Supabase PostgreSQL (Settings → Database → URI) |
| `SUPABASE_URL` | Project URL (Settings → API → Project URL) |
| `SUPABASE_ANON_KEY` | Используется в AuthController для register/login/refresh (Supabase → anon public) |
| `SUPABASE_SERVICE_ROLE` | Используется в middleware и logout (валидация токена, Supabase → service_role secret) |
| `OPENAI_API_KEY` | OpenAI API key для AI receipt scanning |
| `STRIPE_SECRET_KEY` | Stripe secret key (отложено) |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret (отложено) |

**Supabase keys:**
- `SUPABASE_ANON_KEY` — для прокси auth-запросов (signup, token)
- `SUPABASE_SERVICE_ROLE` — для проверки JWT и logout (никогда не отдавать клиенту)

## 7. MVP Scope

Included:
- Auth proxy: register, login, refresh, logout (AuthController)
- Supabase Auth middleware (mobile users)
- Laravel Auth (admin users)
- Manual transaction CRUD
- AI receipt scanning (single photo → transaction suggestion → confirm)
- Category system (static, seeded)
- Subscription plans with AI scan limits
- Stripe integration (checkout, webhooks)

Deferred (post-MVP):
- User category personalization (user_category_settings)
- Google Sheets export
- Chat/message history for AI interactions
- Multi-currency support
