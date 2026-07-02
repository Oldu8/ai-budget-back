# AI Budget: Backend Technical Specification (v1.6)

**Changelog (v1.6):** `POST /api/auth/login` no longer creates `app_users` rows; analytics dashboard docs aligned (Plus **and** Premium, single payload; Basic/Free excluded).

## 1. Project Overview

Backend API for AI Budget — mobile app for personal budget management.
Users can manually log income/expenses, run **AI receipt scan** (single image on Free/Basic; multiple images on Plus/Premium — see `docs/plans.md`), and on **Premium** (product) add expenses via **voice messages**. Pricing matrix: see `docs/plans.md` and section **4.4** below.

### Core Responsibilities

- Authenticate mobile app users with our own Laravel-issued JWT (email/password + Google/Apple)
- Authenticate admin users via standard Laravel Auth (session-based)
- Process receipt images using OpenAI GPT-4o-mini (Structured Outputs)
- Manage hierarchical expense/income categories
- Handle subscription billing and AI-scan usage limits via Stripe
- CRUD for transactions (manual + AI-created)

## 2. Tech Stack

| Layer      | Technology                                    |
| ---------- | --------------------------------------------- |
| Framework  | Laravel 12 (PHP 8.2+)                         |
| Database   | PostgreSQL (managed via Supabase)             |
| ORM        | Eloquent ORM                                  |
| Migrations | Laravel Migrations (`php artisan migrate`)    |
| Auth       | Laravel-issued JWT (mobile) + Laravel Auth (admin) |
| AI         | OpenAI SDK (gpt-4o-mini, Structured Outputs)  |
| Payments   | Stripe SDK (Subscriptions & Webhooks)         |
| Storage    | Supabase Storage (receipt images)             |
| Host       | Railway.app                                   |

## 3. Authentication Architecture

### 3.1. Two-Entity Model

The system has two separate user entities with different auth mechanisms:

**AppUser (`app_users`)** — Mobile application users.

- Authenticated via our **own Laravel-issued JWT** (no Supabase Auth). The `auth.app` middleware verifies the JWT locally (signature + exp) and loads the `AppUser` by `sub`.
- **Lifecycle:** the row is created on `POST /api/auth/register` (email + password hashed in `app_users`) or on first social sign-in (`POST /api/auth/social`). There is no Supabase round-trip and no `firstOrCreate` in the middleware.
- Credentials live in `app_users.password` (bcrypt). Social-only accounts have `password = null`.
- Used for: transactions, subscriptions, payments, AI features
- Model: `App\Models\AppUser` (extends Authenticatable)

**User (`users`)** — Admin panel users.

- Authenticated via standard Laravel Auth (session + password)
- Guard: `web` (default Laravel guard)
- Used for: admin panel access, managing categories/plans/statuses
- Model: `App\Models\User` (extends Authenticatable)

### 3.2. Auth Guards (config/auth.php)

| Guard | Driver  | Provider    | Purpose         |
| ----- | ------- | ----------- | --------------- |
| `web` | session | `users`     | Admin panel     |
| `app` | session | `app_users` | Mobile (backup) |

Mobile API routes use the `auth.app` middleware (not Laravel guards).

### 3.3. AppUser Auth Flow (Laravel-issued JWT)

All authentication lives in Laravel. We issue our **own** tokens for every path:

- **access_token** — short-lived JWT (HS256, `sub = app_user.id`, default ~15 min). Sent as `Authorization: Bearer <token>` on protected routes; verified locally (no DB/network round-trip).
- **refresh_token** — opaque random string stored only as a sha256 hash in `app_user_refresh_tokens`. Rotated on every use and revocable (logout, password change).

Supabase is used only as Postgres + Storage; Supabase Auth is not involved.

**Email / password (register, login):**

```
Mobile App                          Backend API                         Postgres
    |                                   |                                   |
    |-- POST /api/auth/register ------->|                                   |
    |   { email, password }             |-- create app_users (Hash::make) ->|
    |                                   |   ensure free subscription        |
    |<-- 201 { access_token,            |                                   |
    |          refresh_token,           |                                   |
    |          expires_in, user } ------|                                   |
    |                                   |                                   |
    |-- POST /api/auth/login ---------->|                                   |
    |   { email, password }             |-- find by email + Hash::check --->|
    |<-- 200 { access_token, refresh_token, expires_in, user }              |
```

- `register`: validates input, creates the `AppUser` with a hashed password (model cast `password => hashed`), provisions the free plan, sends the registration notification, returns tokens. **422** if the email is already registered.
- `login`: finds the `AppUser` by email and verifies with `Hash::check`. **401** on bad credentials or for a social-only account (password is `null`).

**Social (Google / Apple):**

```
Mobile App            Provider SDK          Backend API                 Provider JWKS
    |  native sign-in -->  |                    |                            |
    |<-- id_token -------- |                    |                            |
    |-- POST /api/auth/social { provider, id_token, name? } -->|             |
    |                                           |-- verify id_token (aud/iss/exp) -->|
    |                                           |   find/create AppUser, link identity
    |<-- 200 { access_token, refresh_token, expires_in, user } |            |
```

- The client obtains an `id_token` from the native Google/Apple SDK and posts it. The backend verifies its signature against the provider's published JWKS and checks `aud` / `iss` / `exp`.
- User resolution: by linked identity (`app_user_identities`) → by **verified** email → otherwise create. Linking to an existing email account happens **only** when the provider reports the email as verified (prevents account takeover via an unverified email).

**Token refresh / logout:**

- `POST /api/auth/refresh { refresh_token }` → validates + rotates: the presented token is revoked and a fresh `access_token` + `refresh_token` are returned. **401** if invalid, expired, or already used.
- `POST /api/auth/logout { refresh_token }` → revokes that refresh token. The access_token remains valid until it expires (stateless).

**Change password:** `PUT /api/me/password` (protected). Verifies `current_password`, sets the new one, and revokes other sessions (the current device's `refresh_token`, if supplied, is kept). **409** for social-only accounts. No email is sent. Password reset by email and "set password" for social users are deferred.

**Key points:**

- Client stores `access_token` and `refresh_token` locally (e.g. SecureStorage).
- When `access_token` expires → client calls `/api/auth/refresh`.
- Auth routes (`/api/auth/*`) are public and throttled (5/min).
- Protected routes use the `auth.app` middleware, which verifies our JWT and loads the `AppUser` by `sub`. A token for a missing/soft-deleted user → **401**.
- Sensitive fields (`password`, `payment_customer_id`) are hidden from JSON responses.

### 3.4. API Routes

**Public (no auth required):**

| Method | Route                | Action            | Body                                  |
| ------ | -------------------- | ----------------- | ------------------------------------- |
| `POST` | `/api/auth/register` | Register new user | `{ email, password }`                 |
| `POST` | `/api/auth/login`    | Login             | `{ email, password }`                 |
| `POST` | `/api/auth/social`   | Social sign-in    | `{ provider, id_token, name? }`       |
| `POST` | `/api/auth/refresh`  | Refresh tokens    | `{ refresh_token }`                   |
| `POST` | `/api/auth/logout`   | Logout            | `{ refresh_token }`                   |

All `/api/auth/*` routes are throttled (5/min) and return `{ access_token, refresh_token, expires_in, user }` (except `logout`). **`POST /api/auth/login`** returns **401** for an unknown email, a wrong password, or a social-only account (no password set).

**Protected (requires `Authorization: Bearer <token>`):**

| Method   | Route              | Action                       |
| -------- | ------------------ | ---------------------------- |
| `GET`    | `/api/me`          | Get profile, settings, subscription, `subscription_status`, and scan_usage (scans remaining/limit, period_end) |
| `PUT`    | `/api/me`          | Update profile               |
| `PUT`    | `/api/me/password` | Change password (`{ current_password, new_password, refresh_token? }`); 409 for social-only accounts |
| `DELETE` | `/api/me`          | Soft-delete account          |
| `GET`    | `/api/me/settings` | Get user settings            |
| `POST`   | `/api/me/settings` | Create settings (onboarding) |
| `PUT`    | `/api/me/settings` | Update user settings         |

| `GET`    | `/api/categories`               | List categories and subcategories (id, slug, label, children); filtered by user's disabled list; optional `?visibility_tag=` for funnel preview     |
| `GET`    | `/api/funnel/visibility-questions` | List funnel questions per visibility tag (visibility_tag, question_key, type, order, translation_key). Frontend uses `t(translation_key + '.title')`, `t(translation_key + '.description')` for i18n.     |
| `GET`    | `/api/visibility-tags`         | List visibility tags with `enabled`, `translation_key` for current user (for settings toggles). Frontend uses `t(translation_key)` for i18n.     |
| `PATCH`  | `/api/me/category-visibility`   | Batch set tags enabled/disabled: body `{ "preferences": [{ "visibility_tag", "enabled" }, ...] }` — disabled = add all categories with tag to app_user_category_settings; enabled = delete those rows. Applied atomically in one request.     |
| `GET`    | `/api/transactions`             | List transactions (paginated, filters: from, to, type)            |
| `GET`    | `/api/transactions/summary`     | Expense summary by category for date range (query: from, to)      |
| `GET`    | `/api/transactions/summary/{category}` | Expense summary by subcategory for one category (query: from, to) |
| `GET`    | `/api/transactions/export`     | CSV export of expenses for date range (query: from, to); Basic/Plus/Premium only |
| `GET`    | `/api/analytics/dashboard`     | **Plus & Premium** (not Basic): calendar-month analytics (query `month=YYYY-MM`). JSON **without** `data` envelope: `month`, `currency`, `period` (`from`, `to`, `previous_from`, `previous_to`), `overview` (totals, `average_per_day`, `biggest_category`, diff vs previous month), `categories` (Level 1 with nested `subcategories`), `trends.categories` / `trends.subcategories` (mom deltas). Same expense basis as summaries: confirmed only, disabled categories excluded. **403** on Free/Basic. |
| `POST`   | `/api/transactions`             | Create transaction (manual, ManualTransactionController)          |
| `GET`    | `/api/transactions/{id}`        | Get transaction — JSON envelope `{ "data": { ... } }` (same shape as scan / receipt update / show) |
| `PUT`    | `/api/transactions/{id}`        | Update transaction                                                  |
| `DELETE` | `/api/transactions/{id}`        | Soft-delete transaction                                             |

| `POST`   | `/api/transactions/scan`               | AI scan: upload image; 201 `{ "data": { ... } }` (transaction with `items[].category`) |
| `POST`   | `/api/transactions/voice`              | **Premium** — voice expense draft: multipart `audio`; 201 same envelope as scan; `raw_ai_data` includes `source`, `transcript`, `parsed`; increments **`used_checks_count`** like receipt scan when draft is created (same plan `check_limit`); audio not persisted |
| `PUT`    | `/api/transactions/{id}/update`        | Save edited AI receipt (`date`, `items[]` …) and **confirm** in one request; server recalculates `total_amount`, sets `confirmed_at` |
| `GET`    | `/api/transactions/{id}/image`         | Receipt preview: `{ "url", "expires_in" }` signed URL (owner, requires `img_path`) |
| `GET`    | `/api/plans`                           | List all plans with currency-aware pricing for frontend paywall |
| `POST`   | `/api/stripe/checkout`                 | Create Stripe Checkout Session; body `{ plan_type }` → `{ checkout_url }` |
| `POST`   | `/api/stripe/portal`                   | Create Stripe Customer Portal Session → `{ portal_url }` |

**No auth (Stripe signature only):**

| Method | Route                  | Action                                         |
| ------ | ---------------------- | ---------------------------------------------- |
| `POST` | `/api/stripe/webhook`  | Stripe webhook endpoint (verified by signature) |

**Scan / receipt update / show responses** use a stable envelope: `data.id`, `data.type`, `data.created_by`, `data.confirmed_at`, `data.date`, `data.total_amount`, `data.img_path`, `data.raw_ai_data`, `data.items[]` with nested `category` `{ id, slug, label }`.

**Scan errors:** not a receipt or incomplete AI → 422 (incomplete: image deleted from storage, no `img_path` in body). AI transport/parse failure after upload → 503, message suggests trying again (image may remain for ops; no automatic retry endpoint).

### 3.5. Client Implementation

**Token storage:**

- Store `access_token` and `refresh_token` in SecureStorage / Keychain (not plain app storage)
- On successful register/login, persist both tokens

**Protected requests:**

- Send header `Authorization: Bearer <access_token>` on all requests to `/api/me*` and other protected endpoints
- On 401, try `POST /api/auth/refresh` with `refresh_token`, then retry the original request
- If refresh returns 401, clear tokens and navigate to login

**Example flow:**

```
1. POST /api/auth/register { email, password } → 201 { access_token, refresh_token, user }
2. Persist tokens; onboarding screen
3. POST /api/me/settings { currency, language, timezone } → 201 (create settings)
4. GET /api/me + Authorization: Bearer <access_token> → profile
5. When access token expires: POST /api/auth/refresh { refresh_token } → new tokens
6. POST /api/auth/logout + Authorization: Bearer <token> → sign out
```

**Settings:**

- `POST /api/me/settings` — onboarding after registration. Creates settings once; duplicate call → 409.
- `PUT /api/me/settings` — update existing settings.
- Success responses (GET/POST/PUT): `{ currency, language, timezone }` only — no `id` or `app_user_id`.

### 3.6. Email Confirmation (Supabase)

Email confirmation is handled entirely by Supabase. The backend does not expose separate confirmation routes.

**Current behaviour:**

- `app_users.email_verified_at` — synced from Supabase `email_confirmed_at` in middleware
- Auth responses include `user.email_verified_at` (null until confirmed)
- **Nothing is blocked** — users may use the app before confirming email

**Planned (after transaction rollout):**

- Transaction create/read will require `email_verified_at !== null`
- Other endpoints (profile, settings) remain unrestricted

**Supabase setup (after deploy):**

1. Supabase Dashboard → Authentication → Providers → Email → **Confirm email** (typically already on)
2. Authentication → URL Configuration:
   - **Site URL** — app URL (e.g. `https://yourapp.com` or deep link `myapp://`)
   - **Redirect URLs** — where to redirect after clicking the email link (e.g. `myapp://auth/confirm`)
3. Email Templates → Confirm signup — adjust copy if needed

**Order of operations:** deploy backend and frontend first, then add real URLs in Supabase.

## 4. Database Schema

### 4.1. Users & Settings

**app_users** — mobile user profile (local auth) and Stripe link.

- `email` (unique)
- `password` (nullable, bcrypt) — `null` for social-only accounts
- `first_name`, `last_name` (nullable)
- `payment_customer_id` (nullable) — Stripe customer ID
- Soft deletes enabled

**app_user_identities** — linked social logins: `app_user_id`, `provider` (`google`|`apple`), `provider_user_id`, unique(`provider`, `provider_user_id`).

**app_user_refresh_tokens** — opaque refresh tokens: `app_user_id`, `token_hash` (sha256), `expires_at`, `revoked_at`. Rotated on use.

**users** — admin panel users (standard Laravel).

- `name`, `email`, `password`
- Standard Laravel Authenticatable

**app_user_settings** — per-user preferences (1:1 with app_users).

- `currency`, `language`, `timezone` (IANA format)
- Timezone is used to calculate correct transaction dates relative to user location

**app_user_category_settings** — per-user disabled categories (visibility).

- `app_user_id` — FK to app_users (cascadeOnDelete)
- `category_id` — FK to categories (cascadeOnDelete)
- `visibility_tag` (nullable) — tag of the category at the time it was disabled; used for bulk "enable by tag" (DELETE WHERE visibility_tag = ?)
- Unique (`app_user_id`, `category_id`)
- **Semantics:** a row means this category/subcategory is **disabled** (hidden) for this user. No row = enabled. By default all categories are enabled. Disabling by tag inserts one row per category that has that tag; enabling by tag deletes all rows with that `visibility_tag` for the user. Future: per-category toggle = single insert/delete by `category_id`.

### 4.2. Categories

**categories** — static hierarchical table of predefined categories.

- `category_id` (self-FK, nullable): null = Level 1 (e.g. Housing), set = Level 2 (e.g. Rent)
- `label` — display name for the client
- `visibility_tag` (nullable) — metadata (e.g. kids, car, pets) for bulk-toggling

**Category hierarchy rules:**
- **Level 1 (parent)** — rollup categories only (e.g. Housing, Food). **Not** user-selectable when creating a transaction. In the UI they show summed amounts for all child subcategories.
- **Level 2 (child)** — concrete subcategories (e.g. Rent, Groceries). These are what the user picks when creating or editing a transaction.
- `transaction_items.category_id` always references a Level 2 category. Level 1 must not be used on the client (parents are not shown as selectable options).

**Seeding categories:** `php artisan categories:seed`. Data: `database/data/categories.php`. Uses `updateOrCreate` by `name` — safe to run multiple times.

### 4.3. Transactions, Transaction Items & Recurring Expenses

**transactions** — single operation / receipt (date, total, optional image).

- `total_amount` — integer (minor units / cents, receipt total)
- `type` — enum: `income`, `expense`
- `created_by` — enum: `user`, `ai`, `recurring`
- `raw_ai_data` (JSON, nullable) — full OpenAI response for AI-derived receipts
- `confirmed_at` (nullable) — when the transaction was confirmed
- `img_path` (nullable) — receipt image path in Supabase Storage
- `recurring_expense_id` (nullable) — FK to `recurring_expenses` (nullOnDelete)
- `recurring_run_at` (nullable, datetime) — which scheduled run this transaction belongs to (deduplication)
- Soft deletes
- Indexes: (app_user_id, date), (app_user_id, date, type), (recurring_expense_id, recurring_run_at)

**transaction_items** — line items (category, amount, label).

- `transaction_id` — FK to transactions (cascadeOnDelete)
- `category_id` — FK to categories (restrictOnDelete)
- `amount` — integer (minor units / cents)
- `label` (nullable) — line description (e.g. bread, bananas)
- Indexes: (transaction_id, category_id), (category_id)

**Shapes:**
- **Manual:** 1 transaction with `total_amount`, 1 transaction_item where `amount` = `total_amount`
- **AI receipt:** 1 transaction with overall total, N transaction_items across categories
- **Recurring:** 1 transaction with `total_amount`, 1 transaction_item (`amount` = `total_amount`) for one category; created by the scheduler, always `type = expense`, `created_by = 'recurring'`, `confirmed_at = now()`

**`confirmed_at` — visibility gate:**
- **Manual:** set immediately (`now()`) on create
- **AI:** `null` on create; set only after the user confirms the scan result
- **Unconfirmed (`confirmed_at IS NULL`):**
  - Excluded from `GET /api/transactions` list
  - Excluded from month/category aggregation (CTE filters)
  - Row exists in DB but is invisible to totals on all devices
- Ensures a scanned-but-not-confirmed receipt does not affect stats or lists

### 4.4. Subscriptions & Billing (Stripe)

**plans** — catalog of subscription tiers (no timestamps).

- `type` — enum: `free`, `basic`, `plus`, `premium`
- `payment_price_id` (nullable) — Stripe Price ID (null for free plan)
- `price` — integer (cents)
- `label` — display name (e.g. Free, Basic, Plus, Premium)
- `description` (nullable) — text listing plan features (for UI/table)
- `check_limit` — max AI scans per billing cycle

**Plans (pricing catalog)**

Plan order is fixed: **Free → Basic → Plus → Premium**. Typical marketing/UI trait order: AI scans (photos per scan — see Other column), manual transactions, history, categories, recurring, analytics, CSV export, plus other bullets in `docs/plans.md`.

**Category visibility** (`PATCH /api/me/category-visibility`, etc.) works on **all** tiers including Free; not repeated in the matrix below.

| Plan    | Price | AI scans | Manual | History   | Categories | Recurring | Analytics              | CSV | Other                         |
| ------- | ----- | -------- | ------ | --------- | ---------- | --------- | ---------------------- | --- | ----------------------------- |
| Free    | $0    | 20/mo    | unlim  | 1 month   | basic only | —         | —                      | —   | 1 photo/scan                  |
| Basic   | $3    | 200/mo   | unlim  | unlimited | custom     | —         | —                      | yes | 1 photo/scan                  |
| Plus    | $5    | 500/mo   | unlim  | unlimited | custom     | yes       | dashboard              | yes | Multi-photo scans             |
| Premium | $10   | 2000/mo  | unlim  | unlimited | custom     | yes       | advanced (no AI insights) | yes | Multi-photo scans; voice expense entry |

Seeding: `php artisan plans:seed`. Data: `database/data/plans.php`. Uses `updateOrCreate` by `type` — safe to run multiple times.

**user_subscriptions** — real-time subscription state per user.

- `payment_subscription_id` — Stripe Subscription ID
- `status_id` — FK to statuses
- `used_checks_count` — incremented after each successful AI scan
- `scans_reset_at` — next rolling quota reset for free plans (nullable; paid rows set on Stripe checkout/renewal for UI)
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
- **Semantics (Stripe-aligned; same row may apply to subscriptions or payments):**
  - **`active`** — subscription is paid and in good standing for the current period; app features that require a plan are available (subject to plan type and quotas).
  - **`trialing`** — user is in a trial period; treated like active for access where `activeSubscription()` includes both `active` and `trialing`.
  - **`past_due`** — latest invoice payment failed; subscription may still be retried by the payment provider. Not treated as active in `activeSubscription()` until status is updated back to `active` (or trialing).
  - **`canceled`** — subscription ended or was canceled; access follows product rules (e.g. until `current_period_end`). Not returned as the current row from `activeSubscription()`.
  - **`pending`** — subscription or payment not yet fully activated (e.g. awaiting first successful payment).

**recurring_expenses** — recurring expense rules.

- `app_user_id` — FK to app_users (cascadeOnDelete)
- `category_id` — FK to categories (restrictOnDelete), always Level 2
- `amount` — integer (minor units), amount per occurrence
- `label` (nullable) — note / description
- `starts_at` (datetime) — rule start time
- `frequency` — string: `weekly` | `monthly` | `yearly`
- `interval` — integer step between runs (default 1)
- `ends_at` (nullable, datetime) — optional end; null means no end
- `is_active` — boolean (default true)
- `last_run_at` (nullable, datetime) — last processed run time
- `next_run_at` (datetime) — next scheduled run
- Soft deletes enabled

**Rules:**
- `category_id` must be Level 2 (`categories.category_id IS NOT NULL`).
- Category must not be disabled for the user in `app_user_category_settings`.
- When disabled (`is_active = false`), the scheduler skips the rule.
- When re-enabled (toggle false → true), `next_run_at` advances to the next future run.

## 5. Key Business Rules

### 5.1. Manual Transactions (ManualTransactionController)

- `POST /api/transactions` — body: `{ total_amount, date, type, category_id }`
- `type`: `income` | `expense`
- `total_amount` — minor units (integer)
- Creates 1 transaction + 1 transaction_item (`amount` = `total_amount`)
- `created_by` = `user`, `confirmed_at` = now()
- List: `GET /api/transactions?from=YYYY-MM-DD&to=YYYY-MM-DD&type=expense&per_page=20`

### 5.2. Categories list and transaction summary

Endpoints for the category tree and expense aggregates for a range. Only **confirmed** transactions (`confirmed_at IS NOT NULL`), type `expense`, for the current user. Period is `from` / `to` (YYYY-MM-DD). Responses do **not** embed category labels — ids only; the client resolves labels from `GET /api/categories`.

**1. Category and subcategory tree**

- **Route:** `GET /api/categories`. Optional query `?visibility_tag=X` — return only categories with that tag (funnel preview; no per-user filter). Without query — rows disabled for the user in `app_user_category_settings` are excluded.
- **Response:** `{ "data": [ { "id", "slug", "label", "children": [ { "id", "slug", "label" } ] } ] }`
- `slug` is `categories.name` in the DB. Level 1 rows have a `children` array (Level 2). Canonical id → slug/label source for charts and summaries.

**2. Level‑1 summary for a period**

- **Route:** `GET /api/transactions/summary?from=YYYY-MM-DD&to=YYYY-MM-DD`
- **Query:** `from`, `to` — required, dates, `to >= from`.
- **Response:**

```json
{
  "from": "2026-03-01",
  "to": "2026-03-31",
  "days": {
    "2026-03-01": { "1": 15000, "5": 5000 },
    "2026-03-02": {},
    "2026-03-03": { "1": 7000 }
  },
  "meta": {
    "totals_by_category": { "1": 22000, "5": 5000 },
    "grand_total": 27000
  }
}
```

- `days` — map *date → { category_id → amount in minor units for that day }*. Every date in the range appears (empty object if no spend). Disabled categories excluded.
- `meta.totals_by_category` — per Level‑1 category total for the range; `meta.grand_total` — overall total.

**3. Subcategories under one Level‑1 category**

- **Route:** `GET /api/transactions/summary/{category}?from=YYYY-MM-DD&to=YYYY-MM-DD`
- **URL:** `{category}` — Level‑1 category id; 404 if not a parent row.
- **Query:** same as above.
- **Response:** Same as §2 plus root `category: { "id", "slug", "label" }`; `days` keys are Level‑2 ids; `meta` has `totals_by_subcategory` and `grand_total` for that branch.

**Files:** `CategoryController` (index), `TransactionSummaryController` (index, show), `SummaryRangeRequest`, `SummaryResponse` (DTO).

### 5.3. Recurring expenses (rules + scheduler)

**Plan access:**

- Recurring expenses are limited to users with active or trialing subscription on **Plus** or **Premium**.
- Checked via `FeatureAccessService::canAccess($user, 'recurring_expenses')` (middleware `feature.access:recurring_expenses` on routes).
  - Uses `activeSubscription()` (status `active` or `trialing`) including plan.
  - Only `plan.type` ∈ {`plus`, `premium`}.
  - Otherwise → 403 `"Recurring expenses are not available on your plan."`

**API: recurring rules**

- Endpoints require `auth.app` and current `AppUser` context only.
- **Routes:**
  - `GET /api/recurring-expenses` — list rules for the user.
  - `POST /api/recurring-expenses` — create.
  - `GET /api/recurring-expenses/{id}` — show one.
  - `PUT /api/recurring-expenses/{id}` — update.
  - `DELETE /api/recurring-expenses/{id}` — soft-delete.
  - `PATCH /api/recurring-expenses/{id}/toggle` — flip `is_active`.

**Validation (`StoreRecurringExpenseRequest` / `UpdateRecurringExpenseRequest`):**

- `category_id`:
  - `required|integer|exists:categories,id` on create,
  - Level 2 only (`categories.category_id IS NOT NULL`),
  - must not be disabled for this user in `app_user_category_settings`.
- `amount`: `required|integer|min:1` (`sometimes` on update).
- `label`: `nullable|string|max:255`.
- `starts_at`: `required|date` (`sometimes` on update).
- `frequency`: `required|in:weekly,monthly,yearly` (`sometimes`).
- `interval`: `integer|min:1` (default 1).
- `ends_at`: `nullable|date|after_or_equal:starts_at`.

**Create (`POST /api/recurring-expenses`):**

- `StoreRecurringExpenseRequest`.
- `app_user_id` from `$request->user()->id`.
- On create:
  - `interval` defaults to 1.
  - `next_run_at` = `max(starts_at, now())` — future start or as soon as possible.
  - `is_active` = true.

**Update (`PUT /api/recurring-expenses/{id}`):**

- Rule must belong to the current user.
- If `starts_at` / `frequency` / `interval` change — recompute `next_run_at` with the same `max(starts_at, now())` rule to avoid backdated bursts.

**Toggle (`PATCH /api/recurring-expenses/{id}/toggle`):**

- Flips `is_active`.
- When enabling (`is_active = true`):
  - Reads `next_run_at` (or `starts_at` if missing; otherwise `now()`).
  - While `next_run_at < now()`, advance one step per `frequency`/`interval`.
  - Persist updated `next_run_at` so the next charge lands in the future.

**Scheduler (`app:run-recurring-expenses`):**

- Artisan `app:run-recurring-expenses` (`RunRecurringExpensesCommand`).
- Scheduled in `App\Console\Kernel` hourly:

  - `$schedule->command('app:run-recurring-expenses')->hourly()->withoutOverlapping()->onOneServer();`

- Candidate rules:

  - `is_active = true`
  - `next_run_at <= now() + 14 hours` (pre-filter for UTC+14 edge cases)
  - `deleted_at IS NULL` (SoftDeletes)

- Processing:

  - `chunkById(100)`, ordered by `next_run_at`.
  - Per rule:
    - Open DB transaction.
    - Load rule `lockForUpdate()`.
    - Re-check (`is_active`, `next_run_at <= userToday`, `next_run_at <= ends_at`).
    - Load `AppUser`; re-run `FeatureAccessService::canAccess(..., 'recurring_expenses')` (skip if plan no longer qualifies).
    - `userToday` from `app_user_settings.timezone`. Compare `next_run_at` (start of day) to `userToday` — skip if the local day has not arrived.
    - If `next_run_at > ends_at` — skip (rule finished).
    - `runAt` = stored `next_run_at` date (start of day).
    - Dedup:
      - Find existing `transactions` with same `recurring_expense_id` and `recurring_run_at = runAt`.
      - If exists — advance rule (`last_run_at`, `next_run_at`) and exit.
    - Else insert:
      - 1 `transaction`:
        - `app_user_id` = rule’s user
        - `total_amount` = `amount`
        - `date` = `runAt` (`Y-m-d`)
        - `type` = `expense`
        - `created_by` = `recurring`
        - `confirmed_at` = `now()`
        - `recurring_expense_id` = rule id
        - `recurring_run_at` = `runAt`
      - 1 `transaction_item`: `category_id`, `amount`, `label` from rule.
    - Update rule:
      - `last_run_at` = `runAt`
      - `next_run_at` = `runAt` advanced one period (`weekly` / `monthly` without overflow / `yearly`).

- **No backfill:** if a rule is far behind, a single run creates **one** transaction and moves `next_run_at` by **one** step; the next cycle handles the following period.

**Lists and summaries:**

- Recurring-generated rows: `type = expense`, `confirmed_at IS NOT NULL`, valid Level‑2 `transaction_items`.
- Therefore they appear in `GET /api/transactions` when in range and in both `TransactionSummaryController` endpoints (filters already require `type = expense` and `confirmed_at IS NOT NULL`).

### 5.4. Monthly aggregation & categories (concept / legacy SQL)

Leaf items roll up the category tree (e.g. shampoo and body wash from different receipts aggregate under the same parent branches). **Authoritative** responses are the summary APIs (§5.2).

**Conceptual result:** `date`, `category_id`, `category_name`, `total_amount`.

```sql
WITH RECURSIVE cat_path AS (
  SELECT c.id AS leaf_id, c.id AS node_id, c.category_id AS parent_id
  FROM categories c
  UNION ALL
  SELECT cp.leaf_id, p.id AS node_id, p.category_id AS parent_id
  FROM cat_path cp
  JOIN categories p ON p.id = cp.parent_id
  WHERE cp.parent_id IS NOT NULL
),
items_in_month AS (
  SELECT t.date AS date, ti.category_id AS leaf_category_id, ti.amount AS amount
  FROM transactions t
  JOIN transaction_items ti ON ti.transaction_id = t.id
  WHERE t.app_user_id = :user_id
    AND t.deleted_at IS NULL
    AND t.confirmed_at IS NOT NULL
    AND t.date BETWEEN :start_date AND :end_date
    AND t.type = 'expense'
)
SELECT iim.date AS date, cp.node_id AS category_id, c.name AS category_name,
       SUM(iim.amount)::bigint AS total_amount
FROM items_in_month iim
JOIN cat_path cp ON cp.leaf_id = iim.leaf_category_id
JOIN categories c ON c.id = cp.node_id
GROUP BY iim.date, cp.node_id, c.name
ORDER BY iim.date, cp.node_id;
```

On the client, map `category_id` → `category_name` (or use `name` as key). Production path: summary API (§5.2).

### 5.5. AI Scan Pipeline (ScanController)

**Route:** `POST /api/transactions/scan` — multipart/form-data, field `image` (jpeg/jpg/png/webp, max 5 MB).

**Flow:**

1. Check scan quota via `FeatureAccessService::canUseAiScan($user)`: load user's active subscription (with plan). If no active subscription → 403 "Subscription required to scan." If `used_checks_count >= plan.check_limit` → 403 "Scan limit reached for this billing period." (Done before upload/AI to avoid wasting resources.)
2. Validate image (ScanRequest: file type, size ≤ 5 MB)
3. Base64-encode the uploaded file (still in memory)
4. Upload original to Supabase Storage (private bucket `receipts`, service role key, path: `{userId}/{uuid}.{ext}`)
5. Fetch all Level 2 categories from DB that are not in the user's `app_user_category_settings` (disabled list); then with('parent').
6. Build prompt with categories grouped by parent (ScanPromptBuilder), send base64 image to OpenAI GPT-4o-mini (**Structured Outputs** via `ScanResponseSchemaBuilder`)
7. Parse AI response: if `is_receipt = false` → only `{ is_receipt, message }` (no receipt fields); if `is_receipt = true` → full receipt shape enforced by JSON Schema
8. If `is_receipt = false` → delete image from Storage, return 422 with AI `message`
9. If AI call fails → keep image in Storage (ops); return 503 **without** `img_path` (no retry endpoint; client uploads again).
10. If valid → in one DB transaction: create Transaction (`created_by = 'ai'`, `confirmed_at = null`, `type = expense`) + N TransactionItems, then increment `user_subscriptions.used_checks_count` for the active subscription.
11. Return 201 with unconfirmed transaction (invisible in lists/aggregations until saved via receipt update)

**Receipt update route:** `PUT /api/transactions/{id}/update` — body same as former draft (`date`, `items[]` with optional `id` per line). In **one** DB transaction: sync line items, recalculate `total_amount`, set `confirmed_at = now()`. Only AI-created, unconfirmed transactions; wrong state → 404.

**Key implementation details:**

- **Category fallback:** if AI returns an invalid `category_id`, the item falls back to `finance_other_misc`. The prompt also instructs AI to use `*_other` subcategories when no specific match fits.
- **Prompt (ScanPromptBuilder):** categories are passed grouped by parent label. Accepts receipt-like images (paper receipt, app/email screenshot, invoice, etc.) with line items and a total. Business rules (amounts in minor units, document total as ground truth, item sum check) stay in the prompt; response shape is enforced by OpenAI Structured Outputs (`ScanResponseSchemaBuilder`).
- **Type is always `expense`** — hardcoded in controller, not requested from AI.
- **Private bucket** — Supabase Storage bucket `receipts` is private. All access (upload, download, delete) uses the service role key. No public URLs, no RLS policies needed. OpenAI receives image as base64, not URL.
- **`raw_ai_data`** stores the full OpenAI response JSON for debugging.

**AI response schema (OpenAI Structured Outputs — `oneOf`):**

Not a financial document:

```json
{
  "is_receipt": false,
  "message": "This looks like a photo, not a receipt."
}
```

Valid receipt:

```json
{
  "is_receipt": true,
  "date": "2026-03-05",
  "total_amount": 15000,
  "items": [
    {
      "product": "Bread",
      "amount": 5000,
      "category_id": 15,
      "category_label": "Groceries"
    }
  ]
}
```

**Files:**

- `app/Http/Controllers/ScanController.php` — scan(), updateReceipt(), image()
- `app/Services/ScanService.php` — OpenAI API call orchestration
- `app/Services/Scan/ScanPromptBuilder.php` — prompt construction and category grouping
- `app/Services/Scan/ScanResponseSchemaBuilder.php` — OpenAI Structured Outputs JSON Schema (`oneOf`: not-receipt vs receipt)
- `app/Services/SupabaseStorageService.php` — Supabase Storage REST API (upload, delete, signed URLs)
- `app/Http/Requests/ScanRequest.php` — image validation

**Between steps 10–11 and `PUT .../update`** the draft row exists in the database but does not appear in lists or aggregates. Confirmation happens in one request together with saving edits.

### 5.5.1. Voice expense entry (Premium, VoiceTransactionController)

**Route:** `POST /api/transactions/voice` — multipart/form-data, field `audio` (extension + MIME validation; max 5 MB). **Premium only**, **same AI scan quota** as `ai_scan` (`FeatureAccessService::canAccess($user, 'voice_expense')` with `requires_quota`: if `used_checks_count >= plan.check_limit` → 403 `Scan limit reached for this billing period.`). **After a successful draft** (same DB transaction), increment `used_checks_count`. Audio is not persisted.

**Flow:** transcribe via OpenAI Whisper (model `config('services.openai.audio_model')`, default `whisper-1` from env `OPENAI_AUDIO_MODEL`; no fixed `language` parameter — Whisper auto-detects per clip). The upload is **copied to a short-lived temp file whose extension matches the client MIME rules** so the multipart client sends a correct `filename`/Content-Type to OpenAI (PHP’s default `php*.tmp` basename can break Whisper). Audio is not persisted. Parse transcript with GPT-4o-mini + `VoicePromptBuilder` (same category rules as scan); validate `is_expense`, `total_amount`, `date`, `items`; persist draft via `AiTransactionDraftService::createExpenseDraft` with `raw_ai_data` `{ "source": "voice_expense", "transcript", "parsed" }`; **increment `used_checks_count`** in the same DB transaction. Plan + quota: `feature.access:voice_expense` middleware and `VoiceTransactionController` both call `FeatureAccessService::canAccess($user, 'voice_expense')` before OpenAI (defence in depth). Transcription/parse failures → 503 with generic messages (no transcript in JSON errors). Not an expense / incomplete AI → 422.

**Confirm:** same `PUT /api/transactions/{id}/update` as receipt drafts.

**Files:** `VoiceTransactionController`, `VoiceTranscriptionService`, `VoiceExpenseParserService`, `VoicePromptBuilder`, `AiTransactionDraftService`, `VoiceTransactionRequest`, `config/feature_access.php` (`voice_expense`), `config/services.php` (`audio_model`).

### 5.6. Visibility tags and category settings

**Data:** Table `visibility_questions` — list of visibility questions (visibility_tag, question_key, type, order, translation_key, is_active). Seeded by `php artisan visibility-questions:seed`. Backend provides structure only; frontend handles translation via i18n. Used by `GET /api/funnel/visibility-questions` and `GET /api/visibility-tags`.

**Rules:**
- Categories (and subcategories) whose id is in `app_user_category_settings` for the user are **hidden**: excluded from `GET /api/categories`, from AI scan category list, and from transaction summary aggregation.
- **Disable by tag** (e.g. front sends `kids` = false): insert into `app_user_category_settings` one row per category that has `visibility_tag = 'kids'` (with `visibility_tag` stored in the row). **Enable by tag:** delete all rows for that user where `visibility_tag = 'kids'`.
- **GET /api/visibility-tags** returns each tag with `enabled: true` if the user has no rows with that tag in `app_user_category_settings`; otherwise `enabled: false`.
- Funnel: show questions from `/api/funnel/visibility-questions`; on answer "no" for a tag, call `PATCH /api/me/category-visibility` with `{ "visibility_tag": "kids", "enabled": false }`. Settings screen: list tags with toggles from `/api/visibility-tags`, update via same PATCH.
- Future: per-category toggle = insert/delete single row by `category_id`.

### 5.7. Scan quota (plan-based limit)

Each plan defines `check_limit` (max AI scans per billing period). The count is stored in `user_subscriptions.used_checks_count` and is incremented after each successful scan (when a transaction is created).

**Reset rules:**

- **Free plan:** rolling 30-day reset via `ScanQuotaResetService::ensureReset()` — called lazily before quota checks (`FeatureAccessService`) and in `GET /api/me`. When `scans_reset_at` is in the past, `used_checks_count` is set to 0 and `scans_reset_at` is advanced by 30 days. New free subscriptions get `scans_reset_at = now() + 30 days` from `EnsureDefaultSubscriptionService`.
- **Paid plans:** reset only on Stripe `invoice.paid` with `billing_reason = subscription_cycle` (and on initial paid subscription creation). `scans_reset_at` is updated alongside the counter for display in `scan_usage`.

**Before calling OpenAI (in `ScanController::scan()`):**

- Load the user's active subscription (status `active` or `trialing`) with `plan`. If there is no active subscription → 403 with message "Subscription required to scan."
- If `used_checks_count >= plan.check_limit` → 403 with message "Scan limit reached for this billing period."
- After successfully creating the transaction and items (inside the same DB transaction), call `$subscription->increment('used_checks_count')`.

**GET /api/me response** — flat JSON (no `data` envelope). Explicit API payload only (not raw Eloquent):

- **User fields** (top-level): `id`, `email`, `first_name`, `last_name`, `email_verified_at` (ISO 8601 or `null`), `has_password` (bool — `false` for social-only accounts; lets the client hide "change password"). Omits `created_at`, `updated_at`, `password`, `payment_customer_id`.
- **`settings`**: `{ currency, language, timezone }` or `null` if not configured. Omits `id`, `app_user_id`.
- **`subscription`**: Active subscription per `AppUser::activeSubscription()` (status `active`, `trialing`, or `canceled` within period), or `null`. Shape:
  - `id`, `status` (string — same value as `subscription_status`), `used_checks_count`, `current_period_end` (ISO 8601 or `null`)
  - `plan`: `{ type, price, check_limit, label, description }` — omits internal ids and Stripe fields
- **`subscription_status`**: String — `statuses.name` for the subscription above; `null` if no active row.
- **`scan_usage`**: `{ scans_remaining, scans_limit, period_end, scans_reset_at }`. If no subscription: zeros and `period_end` / `scans_reset_at`: null. Otherwise: `scans_remaining = max(0, plan.check_limit - used_checks_count)`, `scans_limit = plan.check_limit`, `period_end` = billing period end (ISO 8601), `scans_reset_at` = next quota reset (ISO 8601 or `null`).

**PUT /api/me response** — user fields only (same five fields as GET, no settings/subscription/scan_usage).

**GET / POST / PUT /api/me/settings response** — `{ currency, language, timezone }` only (no `id`, `app_user_id`).

### 5.8. Stripe Integration (StripeController)

**Checkout** (`POST /api/stripe/checkout`):
- Accepts `{ plan_type: "basic"|"plus"|"premium" }`. Rejects `free`.
- Gets or creates Stripe Customer (saves `payment_customer_id` on `app_users`).
- Resolves `price_id` from user's currency (`app_user_settings.currency`, default `usd`) via `config('services.stripe.prices')`, fallback to USD.
- Creates `Stripe\Checkout\Session` with `mode: subscription`, `client_reference_id`, metadata (`app_user_id`, `plan_type`, `currency`).
- Returns `{ checkout_url }`.

**Customer Portal** (`POST /api/stripe/portal`):
- Requires existing `payment_customer_id`; returns 400 if missing.
- Creates `Stripe\BillingPortal\Session` and returns `{ portal_url }`.

**Webhook** (`POST /api/stripe/webhook`):
- Outside auth middleware; verified solely by Stripe signature (`STRIPE_WEBHOOK_SECRET`).
- All handlers are **idempotent** (upsert by `payment_subscription_id` / `payment_invoice_id`).
- Webhooks are the **single source of truth** for subscription state.
- Events handled (see `docs/stripe-webhook-samples/` for real payload shapes):
  - `checkout.session.completed` — logs traceability data (session ID, customer, metadata). Not authoritative for subscription activation.
  - `customer.subscription.created` — upserts `user_subscriptions` (plan, status, period end, usage = 0). Cancels local free subscription. With Stripe **flexible billing**, root `current_period_end` may be absent; period is read from `items.data[0].current_period_end` (and API retrieve as fallback).
  - `customer.subscription.updated` — updates status, period end, cancel_at_period_end, plan if price changed.
  - `customer.subscription.deleted` — sets status to `canceled`, creates fallback free subscription via `EnsureDefaultSubscriptionService`.
  - `invoice.paid` — creates/updates `payments` record. Subscription id is taken from `subscription`, or `parent.subscription_details.subscription`, or line `parent.subscription_item_details.subscription` when the top-level `subscription` field is missing. Recovers subscription from `past_due`/`pending` to `active`. Resets `used_checks_count` and updates `scans_reset_at` only on renewal (`billing_reason = subscription_cycle`).
  - `invoice.payment_failed` — sets subscription status to `past_due`.
- Missing AppUser for a customer ID is logged and reported via `ErrorNotifier`, webhook returns 200 without throwing.

**Free Plan Auto-Creation** (`EnsureDefaultSubscriptionService`):
- Called in `AuthController::register()` and **after successful** `login()` (when `AppUser` already exists).
- Ensures every user has an active subscription. If no active subscription exists, creates a local-only free subscription (`payment_subscription_id = null`, `current_period_end = null`, `used_checks_count = 0`, `scans_reset_at = now() + 30 days`).
- Free plan is local-only — no Stripe Product/Price. When paid subscription activates, free is canceled. When paid is deleted, free is re-created with fresh usage.

**Plans** (`GET /api/plans`):
- Returns all plans ordered Free → Basic → Plus → Premium with currency-aware `price_id` from config.

### 5.9. CSV export of expenses

- **Route:** `GET /api/transactions/export?from=YYYY-MM-DD&to=YYYY-MM-DD`
- **Access:** Enforced by `feature.access:csv_export` middleware via `FeatureAccessService`. Only for users with active subscription on Basic, Plus, or Premium plan. If no subscription or plan is Free → 403 "CSV export is not available on your plan."
- **Query:** `from`, `to` — required, dates, `to >= from`. Max range (e.g. one year) is enforced on the client.
- **Data:** Confirmed expense transactions in the date range; transaction items whose category is not in the user's disabled list. One CSV row per transaction item.
- **Response:** Streamed CSV file, `Content-Disposition: attachment; filename="expenses_YYYY-MM-DD_YYYY-MM-DD.csv"`. UTF-8 with BOM. Columns: Date, Category (subcategory label), Amount (in currency units, 2 decimals), Currency (from app_user_settings), Label (item label or empty).

### 5.10. Analytics dashboard (Plus and Premium)

- **Route:** `GET /api/analytics/dashboard?month=YYYY-MM`
- **Access:** `feature.access:analytics_dashboard` via `FeatureAccessService`. **Allowed plans: Plus and Premium** (Basic and Free → **403** with `Analytics dashboard is not available on your plan.`). *Product copy* in `docs/plans.md` distinguishes «dashboard» (Plus) vs «advanced analytics» (Premium); **the current backend exposes one implementation** for both plan types — same payload and rules.
- **Validation:** `month` required; `date_format:Y-m` (invalid month → **422**).
- **Data:** Same basis as transaction summaries: confirmed expenses only, not soft-deleted, Level 2 items rolled up to parents; categories in `app_user_category_settings` excluded. Amounts in **minor units** (integers). `currency` from `app_user_settings` (uppercased). `overview.average_per_day` = `total_spent / days_in_month` (integer division). `overview.average_per_day_diff` compares the current daily average against the previous month's daily average (`previous_month_total / days_in_previous_month`, integer division) and carries `previous_amount`, `difference_amount`, `difference_percent`, `direction`.
- **Response:** Top-level JSON (no `data` wrapper): `month`, `currency`, `period`, `overview`, `categories`, `trends`. Month-over-month comparison uses the **previous calendar month**. Percents in diff metadata rounded to 2 decimals; `difference_percent` may be null when the previous value is zero and the current is positive.

## 6. Environment Variables

| Variable                | Purpose                                                                               |
| ----------------------- | ------------------------------------------------------------------------------------- |
| `DB_CONNECTION`         | Set to `pgsql` for PostgreSQL                                                        |
| `DB_URL`                | Supabase Postgres connection URI (Dashboard → Database)                               |
| `SUPABASE_URL`          | Project URL — used for Storage only (Settings → API → Project URL)                    |
| `SUPABASE_SERVICE_ROLE` | Supabase **Storage** access (service_role — never expose). Not used for auth.         |
| `JWT_SECRET`            | Secret for signing our access JWT (required). Generate a long random string.          |
| `JWT_TTL` / `JWT_REFRESH_TTL` | Access / refresh token lifetimes in seconds (defaults 900 / 2592000)            |
| `GOOGLE_CLIENT_IDS` / `APPLE_CLIENT_IDS` | Comma-separated allowed OAuth client ids (the `aud` of the id_token) for social sign-in |
| `OPENAI_API_KEY`        | OpenAI key for receipt scan                                                           |
| `SUPABASE_STORAGE_BUCKET` | Supabase Storage bucket name (default: `receipts`)                                  |
| `OPENAI_REQUEST_TIMEOUT`| OpenAI request timeout in seconds (default: 60)                                       |
| `STRIPE_SECRET_KEY`     | Stripe secret key                                                                   |
| `STRIPE_PUBLIC_KEY`     | Stripe publishable key (for frontend if needed)                                     |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret                                                       |
| `STRIPE_SUCCESS_URL`    | Redirect after successful checkout; if unset, defaults to `{APP_URL}/stripe/checkout/success` (public test page) |
| `STRIPE_CANCEL_URL`     | Redirect after canceled checkout; if unset, defaults to `{APP_URL}/stripe/checkout/cancel` (public test page)   |
| `STRIPE_BASIC_PRICE_EUR` / `STRIPE_BASIC_PRICE_USD` | Stripe Price IDs for **Basic** (EUR / USD)                          |
| `STRIPE_PLUS_PRICE_EUR` / `STRIPE_PLUS_PRICE_USD`   | Stripe Price IDs for **Plus** (EUR / USD)                           |
| `STRIPE_PREMIUM_PRICE_EUR` / `STRIPE_PREMIUM_PRICE_USD` | Stripe Price IDs for **Premium** (EUR / USD)                    |
| `DISCORD_ERRORS_WEBHOOK_URL` | Errors / Stripe issues → `#error-logs` (fallback: `DISCORD_WEBHOOK_URL`)          |
| `DISCORD_REGISTRATIONS_WEBHOOK_URL` | Successful sign-ups → `#new-users`                                              |
| `DISCORD_PAYMENTS_WEBHOOK_URL` | Paid subscription start / cancel → `#payment-info` (not renewals)              |
| `DISCORD_REGISTRATION_INCLUDE_EMAIL` | `true` — include full email in registration ping; otherwise `app_user_id` + domain only |

**Supabase keys:**

- `SUPABASE_SERVICE_ROLE` — Supabase Storage access only (**never ship to clients**). Supabase Auth is no longer used.

## 7. MVP Scope

Included:

- Local auth: register, login, social (Google/Apple), refresh, logout, change password (AuthController + AppUserController)
- `auth.app` JWT middleware (mobile users)
- Laravel Auth (admin users)
- Manual transaction CRUD
- AI receipt scanning (single photo → transaction suggestion → `PUT .../update` to save + confirm)
- Category system (static, seeded)
- Category visibility: app_user_category_settings, funnel/visibility-questions, visibility-tags, PATCH category-visibility; categories/summary/scan respect disabled list
- Subscription plans with AI scan limits
- Stripe integration (checkout, webhooks)
- CSV export of expenses (GET /api/transactions/export; Basic/Plus/Premium only)
- **Analytics dashboard** (`GET /api/analytics/dashboard`; Plus/Premium only — see §5.10)

Deferred (post-MVP):

- Per-category (single category_id) toggle in settings (table supports it; API can be extended)
- Google Sheets export
- Chat/message history for AI interactions
- Multi-currency support
