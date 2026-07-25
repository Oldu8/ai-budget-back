## AI Budget — Backend overview for frontend

This document is a concise but complete description of the AI Budget backend, focused on what matters for the mobile frontend.

The stack: Laravel 12, PostgreSQL (Supabase, DB only), Laravel-issued JWT auth (email/password + Google/Apple), Stripe, OpenAI. The full low-level spec lives in `ai-budget-back/.cursor/backend-spec.md`.

> Canonical copy for the monorepo. Keep in sync when editing API/domain docs.

---

## 1. App concept (from frontend POV)

AI Budget is a personal budget tracker:

- Users register / log in with email + password, or via Google / Apple — all handled by the backend, which issues its own JWT.
- Users can:
  - create **manual transactions** (incomes / expenses),
  - scan receipts with **AI scan** (photos → categorized items; Free/Basic: one image per scan; Plus/Premium: multiple — see [`ai-budget-back/docs/plans.md`](../ai-budget-back/docs/plans.md)),
  - on **Premium**, add expenses via **voice messages** (product feature; see [`ai-budget-back/docs/plans.md`](../ai-budget-back/docs/plans.md)),
  - enable / disable expense categories (via visibility tags; **all plans**, including Free),
  - see expense summaries by category / subcategory for a period,
  - export expenses to **CSV** (paid plans only).
- Subscriptions and AI scan limits are managed via Stripe.

---

## 2. Core domain objects (frontend level)

### 2.1. AppUser and settings

- Table `app_users`:
  - `id` — internal user id.
  - `email`, `first_name`, `last_name`.
  - `has_password` (in `/api/me`) — `false` for social-only accounts; use it to hide "change password".
  - `email_verified_at` — whether email is verified.
- Table `app_user_settings` (1:1 with `app_users`):
  - `currency` — currency code (e.g. `USD`, `EUR`, `RUB`).
  - `language` — language (`en`, `ru`, …).
  - `timezone` — IANA timezone (`Europe/Berlin`, etc.), affects transaction dates.

Frontend:

- After register / login the client should create or update settings via `/api/me/settings`.
- **`GET /api/me`** returns user fields at the top level plus `settings`, `subscription`, `subscription_status`, `scan_usage` (exact shapes in [`ai-budget-back/.cursor/backend-spec.md`](../ai-budget-back/.cursor/backend-spec.md) §5.7). Client consumes the payload directly without defensive parsing.
- **`GET|POST|PUT /api/me/settings`** return `{ currency, language, timezone }` only.
- Currency from settings is used in the UI and in CSV export.

### 2.2. Categories

- Table `categories`:
  - Level 1 (parent categories) — aggregating, **cannot be selected** when creating a transaction.
  - Level 2 (subcategories) — selectable categories the user chooses.
  - Fields:
    - `id`
    - `category_id` — parent category id (null for Level 1).
    - `name` — slug (exposed as `slug` in the API).
    - `label` — display label.
    - `visibility_tag` — tag for bulk enabling/disabling (kids, car, pets, etc.).

Frontend:

- Load the category tree via `GET /api/categories`.
- Use `id` / `slug` / `label` for charts, dropdowns, etc.

### 2.3. Category visibility

- Table `app_user_category_settings`:
  - `app_user_id`, `category_id`, `visibility_tag`.
  - A row means this category is **disabled** (hidden) for the user.
- Visibility by `visibility_tag`:
  - disabling = insert one row per category that has this tag;
  - enabling = delete all rows with this tag for the user.
- All endpoints that return categories / summaries / AI results respect this table and exclude disabled categories.
- **Plans:** category visibility is **not** paywalled — Free users can use the same visibility APIs as paid tiers.

Frontend:

- Visibility settings via:
  - `GET /api/funnel/visibility-questions` — returns `visibility_tag`, `question_key`, `type`, `order`, `translation_key`. Use `t(translation_key + '.title')`, `t(translation_key + '.description')` for i18n.
  - `GET /api/visibility-tags` — returns `visibility_tag`, `enabled`, `translation_key`. Use `t(translation_key)` for i18n.
  - `PATCH /api/me/category-visibility` with `{ visibility_tag, enabled }`.
- In the UI you can simply show enabled categories: the backend already filters disabled ones out.

### 2.4. Transactions and items

- Table `transactions`:
  - `id`
  - `app_user_id`
  - `total_amount` — total amount (in the smallest units: cents).
  - `date` — transaction date (created using user’s timezone).
  - `type` — enum `income` or `expense`.
  - `created_by` — enum `user` (manual) or `ai` (AI scan).
  - `raw_ai_data` — full JSON response from OpenAI for AI receipts.
  - `confirmed_at` — when the transaction was confirmed (key field).
  - `img_path` — receipt image path in Supabase Storage.
  - Soft deletes via `deleted_at`.

- Table `transaction_items`:
  - `transaction_id` → `transactions.id`.
  - `category_id` → always a Level 2 category.
  - `amount` — item amount in smallest units.
  - `label` — item name (e.g. “Bread”, “Gasoline”).

Rules:

- Manual transaction:
  - 1 `transaction` + 1 `transaction_item` (all in one category).
  - `created_by = user`, `confirmed_at = now()`.
- AI receipt:
  - 1 `transaction` + N `transaction_items` across categories.
  - `created_by = ai`, `confirmed_at = null` initially.
  - It only appears in lists / summaries **after** `PUT /transactions/{id}/update` (save + confirm).

Frontend:

- Everywhere you need “final” data (history, summaries) the backend automatically applies:
  - `confirmed_at IS NOT NULL` and `deleted_at IS NULL`.

### 2.5. Subscriptions and scan limits

- Table `plans`:
  - `type` — enum: `free`, `basic`, `plus`, `premium`.
  - `price` — plan price (in cents).
  - `check_limit` — max AI scans per billing period.
  - `label`, `description` — text for UI.
- Table `user_subscriptions`:
  - `app_user_id`
  - `payment_subscription_id` (Stripe)
  - `status_id` → row in `statuses` (`name` is what the API exposes as a string).
  - `used_checks_count` — how many scans were used in the current period.
  - `current_period_end` — end of billing period.

**Subscription / payment status names** (dictionary `statuses`; Stripe-aligned):

- **`active`** — paid and in good standing for the current period; plan features apply (within plan limits).
- **`trialing`** — trial period; the backend treats this like `active` for “current subscription” (`activeSubscription()` includes both).
- **`past_due`** — last invoice payment failed; the provider may retry. Not included in `activeSubscription()` until status returns to `active` or `trialing`.
- **`canceled`** — subscription ended or canceled; access may last until `current_period_end` depending on product rules. Not returned as the current row from `activeSubscription()`.
- **`pending`** — not fully active yet (e.g. awaiting first successful payment).

Frontend:

- `/api/me` returns:
  - **User** — `id`, `email`, `first_name`, `last_name`, `email_verified_at`
  - **`settings`** — `{ currency, language, timezone }` or `null`
  - `subscription` — the **active** subscription with nested `plan` (or `null`). Shape: `{ id, status, used_checks_count, current_period_end, plan: { type, price, check_limit, label, description } }`. Only rows whose status is `active` or `trialing` are selected (plus `canceled` within period). Users who completed **`POST /api/auth/register`** get a **free** subscription auto-created on register and on **successful login** (`EnsureDefaultSubscriptionService`); if data is inconsistent, `subscription` can be `null`.
  - `subscription_status` — string, the status `name` for that subscription (typically `active` or `trialing` when `subscription` is non-null); `null` if there is no such subscription. Same value as `subscription.status` when present.
  - `scan_usage` — `{ scans_remaining, scans_limit, period_end }`. For free plan `period_end` is `null` (no expiration).
- Use `subscription.plan.label` (and related plan fields) plus `subscription_status` for subscription UI (badges, “Manage plan” screens).
- For AI scans the client should:
  - show remaining scans using `scan_usage`,
  - handle 403 errors:
    - `Subscription required to scan.`
    - `Scan limit reached for this billing period.`
- Paywall / plan copy (multi-photo receipt scan, voice entry on Premium, advanced analytics without «AI insights») should match `GET /api/plans` → `description` and the matrix in [`ai-budget-back/docs/plans.md`](../ai-budget-back/docs/plans.md).

### 2.6. Stripe: Checkout, Portal, Plans

**Plans list** (`GET /api/plans`):
- Returns all plans with currency-aware pricing: `type`, `label`, `price` (cents), `currency`, `price_id` (Stripe, null for free), `check_limit`, `description`.
- Use for the paywall / subscription selection UI.

**Checkout** (`POST /api/stripe/checkout`):
- Body: `{ "plan_type": "basic"|"plus"|"premium" }`.
- Response: `{ "checkout_url": "https://checkout.stripe.com/..." }`.
- Frontend opens this URL in a browser (not WebView). After completion Stripe redirects to the configured deep link.
- **Important:** The redirect to `success_url` is **not** confirmation of subscription activation. After returning from Checkout, the app **must** refetch `GET /api/me` to read the actual subscription state (updated by backend webhooks).

**Customer Portal** (`POST /api/stripe/portal`):
- Response: `{ "portal_url": "https://billing.stripe.com/..." }`.
- Opens a Stripe-hosted page where the user can manage payment methods, cancel, or change subscription.
- Requires that the user has gone through Checkout at least once (has a Stripe Customer). Returns 400 if not: `{ "message": "No billing account found. Please subscribe to a plan first." }`.
- After returning from Portal, refetch `GET /api/me`.

**Webhook** (`POST /api/stripe/webhook`):
- Not called by the frontend. Stripe sends events here to keep subscription state in sync.
- The backend DB (and thus `/api/me`) is the frontend's source of truth, not the Checkout/Portal redirect.

---

## 3. Authentication and tokens

### 3.1. API key (optional)

When the backend has `API_SECRET` set, **all** requests to `/api/*` must include the header:

```http
X-API-Key: <API_SECRET>
```

- Used for server-side calls (cron, webhooks) — the secret is stored in env on the server.
- **Do not store** in mobile/web client — the secret can be extracted from the app.

If `API_SECRET` is not set, the header is not required.

### 3.2. Auth flows

Authentication is fully handled by the backend, which issues **its own** tokens (no Supabase Auth). All auth responses return `{ access_token, refresh_token, expires_in, user }` (login/social also include `settings`). The `access_token` is a short-lived JWT (~15 min); `refresh_token` is opaque and rotates on use.

- Register: `POST /api/auth/register` `{ email, password }` → 201. **422** if the email is already registered.
- Login: `POST /api/auth/login` `{ email, password }` → 200. **401** for unknown email, wrong password, or a social-only account (no password set).
- Social: `POST /api/auth/social` `{ provider: "google"|"apple", id_token, name? }` → 200. The client gets `id_token` from the native Google/Apple SDK; the backend verifies it (JWKS) and finds/creates the user. **401** if verification fails.
- Refresh: `POST /api/auth/refresh` `{ refresh_token }` → new tokens (the old refresh token is revoked). **401** if invalid/expired/used.
- Logout: `POST /api/auth/logout` `{ refresh_token }` → revokes that refresh token.
- Change password: `PUT /api/me/password` `{ current_password, new_password, refresh_token? }` (protected) → 200. **422** on wrong/invalid password, **409** for social-only accounts. Pass the current `refresh_token` to stay signed in on this device while other sessions are revoked.
- Delete account: `DELETE /api/me` (protected) → 200. Soft-deletes the user after canceling Stripe subscriptions, revoking refresh tokens, deleting social identities, and anonymizing PII so the email can be re-registered. Client clears local tokens and returns to login (no separate logout call needed).

All `/api/auth/*` routes are throttled (5/min). Protected endpoints require:

```http
Authorization: Bearer <access_token>
```

### 3.3. Client behavior

- Store `access_token` and `refresh_token` in secure storage (SecureStorage / Keychain).
- On 401 from a protected endpoint:
  1. Call `/api/auth/refresh` with `refresh_token`.
  2. If successful, retry the original request.
  3. If refresh returns 401, clear tokens and navigate to login.

---

## 4. Main features and related endpoints

This is a feature-oriented view (more friendly than raw route listing in `api-routes.json`).

### 4.1. Profile and settings

- `GET /api/me` — profile + active subscription + `subscription_status` + `scan_usage`.
- `POST /api/me/settings` — create settings during onboarding (once).
- `PUT /api/me/settings` — update language / currency / timezone.

Frontend:

- After registration:
  - call `POST /api/me/settings`,
  - store settings in app state.
- On app start:
  - call `GET /api/me` to load profile, subscription, `subscription_status`, limits, and settings.

### 4.2. Categories and visibility funnel

- `GET /api/categories` — category tree (with disabled categories filtered out).
- `GET /api/funnel/visibility-questions` — questions per `visibility_tag` (visibility_tag, question_key, type, order, translation_key). Frontend uses i18n: `t(translation_key + '.title')`, `t(translation_key + '.description')`.
- `GET /api/visibility-tags` — list of tags with `enabled`, `translation_key`. Frontend uses `t(translation_key)` for toggle labels.
- `PATCH /api/me/category-visibility` — enable / disable groups of categories by tag.

Frontend:

- Onboarding:
  - Build a UI based on `visibility-questions` / `visibility-tags` (contexts: car, kids, etc.).
  - Send answers as `PATCH /api/me/category-visibility`.
- Regular UI:
  - Work only with categories returned by `/categories` and summary endpoints (disabled ones are already excluded).

### 4.3. Manual transactions

- `GET /api/transactions` — list of confirmed user transactions:
  - filters: `from`, `to`, `type`, `per_page`.
- `POST /api/transactions` — create manual transaction.
- `GET /api/transactions/{id}` — get a single transaction.
- `PUT /api/transactions/{id}` — update a transaction.
- `DELETE /api/transactions/{id}` — soft-delete a transaction.

Frontend:

- Create:
  - body: `{ total_amount (int, cents), date, type, category_id }`.
  - response already includes `items` with attached category.
- Display:
  - use `type` and `items.amount` / `total_amount`,
  - convert to currency units by dividing by 100.

### 4.4. AI scan (receipt recognition)

- `POST /api/transactions/scan`:
  - multipart with `image` (jpeg/png) in the current API; product target is **multiple** images per scan on Plus/Premium (see [`ai-budget-back/docs/plans.md`](../ai-budget-back/docs/plans.md), `ai-budget-back/.cursor/receipt-scan-backend.en.md`).
  - requires active subscription and free scan quota.
  - returns a draft transaction with categorized items.
- `PUT /api/transactions/{transaction}/update`:
  - saves edited `date` / `items[]` (full line sync); recalculates `total_amount`.
  - on **draft** (unconfirmed) transactions: sets `confirmed_at` on first successful save so they appear in history.
  - on **already confirmed** transactions: updates lines without changing `confirmed_at` (same endpoint for history editing of multi-line expenses).
- After first confirmation, the transaction appears in history and summaries.

Frontend:

- Before scanning:
  - optionally show remaining scans using `scan_usage` from `/api/me`.
- After scan:
  - show recognized items; allow editing categories / amounts;
  - on save call `PUT .../update` with final payload.
- History / edit screens for multi-line transactions can use the same `PUT .../update` with `date` and `items[]`.

### 4.5. Expense summaries (by date range)

Summaries of expenses over an arbitrary date range (all plans):

- `GET /api/transactions/summary?from&to` — by Level 1 categories.
  - `days`: map `YYYY-MM-DD` → `{ category_id: total_amount_in_cents }`.
  - `meta.totals_by_category` and `meta.grand_total`.
- `GET /api/transactions/summary/{category}?from&to` — by subcategories for one parent category.
  - same shape, but keys in `days` are subcategory ids; plus `category` object in the root.

Frontend:

- For charts / dashboards:
  - combine `days` with `/api/categories` to resolve labels,
  - convert amounts to currency units (`amount / 100`).

### 4.6. Analytics dashboard (Plus / Premium — calendar month)

**Different from §4.5:** this is a **single calendar month** view with **automatic previous-month comparison**, richer `overview`, per–Level-1 `categories` with nested `subcategories`, and `trends` (mom changes). **Not available on Free or Basic** (`feature.access:analytics_dashboard`).

- **Endpoint:** `GET /api/analytics/dashboard?month=YYYY-MM` (e.g. `2026-05`).
- **403** on disallowed plans: `Analytics dashboard is not available on your plan.`
- **Response:** top-level JSON (no `data` envelope): `month`, `currency`, `period` (`from`, `to`, `previous_from`, `previous_to`), `overview`, `categories`, `trends`. Amounts in **cents**. Only **confirmed** expenses; respects disabled categories.

Product copy in [`ai-budget-back/docs/plans.md`](../ai-budget-back/docs/plans.md) calls Plus «dashboard» and Premium «advanced analytics»; **the backend currently implements one shared payload** for both plan types.

Frontend:

- Use for the dedicated Analytics screen; keep using `/api/transactions/summary` for custom date ranges on all tiers.

### 4.7. CSV export of expenses (Dynamic CSV export)

Feature for exporting expenses to CSV.

- **Endpoint:** `GET /api/transactions/export?from=YYYY-MM-DD&to=YYYY-MM-DD`.
- **Auth:** `Authorization: Bearer <access_token>`.
- **Plan restriction:**
  - available only if the user has an active subscription with plan type `basic`, `plus` or `premium`;
  - if no subscription or plan is `free` → 403 with:
    - `CSV export is not available on your plan.`
- **Date range:**
  - `from` and `to` are required; `to >= from`;
  - the “no more than one year (Jan–Dec)” limit is enforced on the client — backend does not enforce a max range.
- **Data:**
  - only confirmed expenses (`type = expense`, `confirmed_at IS NOT NULL`);
  - only categories that are not disabled for the user;
  - one CSV row = one `transaction_item` (one expense line).
- **File format:**
  - Response: `text/csv` + `Content-Disposition: attachment; filename="expenses_FROM_TO.csv"`.
  - Encoding: UTF‑8 with BOM (so Excel opens it correctly).
  - Separator: comma; quoting/escaping handled by `fputcsv` on backend.
  - Columns:
    - `Date` — `YYYY-MM-DD` (transaction date).
    - `Category` — Level 2 `categories.label`.
    - `Amount` — amount in currency units (`amount / 100`, 2 decimal places).
    - `Currency` — from `app_user_settings.currency` (same for the whole file).
    - `Label` — `transaction_items.label` or empty string.

Frontend:

- Build `from` / `to` from selected months (e.g. 2026-01-01…2026-02-29).
- Perform a `GET` with `Accept: text/csv` and use platform tools to download / share / open the file.

### 4.8. Voice expense entry (Premium)

- `POST /api/transactions/voice`:
  - multipart field `audio` (common mobile formats; max 5 MB).
  - **Premium only** (`feature.access:voice_expense`). On other plans → **403** with `Voice expense entry is only available on Premium.`
  - **Uses the same AI scan quota** as receipt scan (`plan.check_limit` / `used_checks_count`; `scan_usage` on `/api/me` applies). When the period limit is exhausted → **403** with `Scan limit reached for this billing period.`
  - On **successful** draft creation, `used_checks_count` is incremented once (same counter as `POST /api/transactions/scan`).
  - Transcribes audio with OpenAI, parses text into categorized line items, returns the same **`TransactionResource`** shape as receipt scan (`type: expense`, `created_by: ai`, `confirmed_at: null` until saved via `PUT /api/transactions/{transaction}/update`).
  - `raw_ai_data` includes a `source` marker (`voice_expense`), `transcript`, and parsed JSON.
  - Audio is **not** stored server-side (temporary upload only).

Frontend:

- Same post-processing as §4.4: show draft lines, let the user edit, then `PUT .../update` to confirm.

---

## 5. File map — where to look in the backend

If you need more detail:

- Full specification and business rules — `ai-budget-back/.cursor/backend-spec.md`.
- Machine-readable API route list — `ai-budget-front/docs/api-routes.json`.
- Key controllers:
  - `App/Http/Controllers/AuthController.php` — register / login / social / refresh / logout.
  - `App/Http/Controllers/AppUserController.php` — `/me`, `/me/password`, `/me/settings`, subscription, `subscription_status`, `scan_usage`.
  - `App/Http/Controllers/CategoryController.php` — `/categories`.
  - `App/Http/Controllers/CategoryVisibilityController.php` — visibility tags and onboarding.
  - `App/Http/Controllers/ManualTransactionController.php` — transaction CRUD.
  - `App/Http/Controllers/ScanController.php` — AI scan and receipt update.
  - `App/Http/Controllers/VoiceTransactionController.php` — Premium voice expense draft (`POST /api/transactions/voice`).
  - `App/Http/Controllers/TransactionSummaryController.php` — expense summaries (date range).
  - `App/Http/Controllers/AnalyticsDashboardController.php` — analytics dashboard (month, Plus/Premium).
  - `App/Http/Controllers/ExpenseExportController.php` — CSV export.
- Models:
  - `App/Models/AppUser.php`, `App/Models/AppUserSettings.php`
  - `App/Models/Category.php`, `App/Models/Transaction.php`, `App/Models/TransactionItem.php`
  - `App/Models/Plan.php`, `App/Models/UserSubscription.php`

`frontend-backend-spec.en.md` together with `api-routes.json` should be enough to understand which features exist, how data is shaped, and which endpoints are available when working on the frontend.

