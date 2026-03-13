## AI Budget — Backend overview for frontend

This document is a concise but complete description of the AI Budget backend, focused on what matters for the mobile frontend.

The stack: Laravel 12, PostgreSQL (Supabase), Supabase Auth, Stripe, OpenAI. The full low-level spec lives in `.cursor/backend-spec.md`.

---

## 1. App concept (from frontend POV)

AI Budget is a personal budget tracker:

- Users register / log in with email + password (Supabase Auth behind an API proxy).
- Users can:
  - create **manual transactions** (incomes / expenses),
  - scan receipts with **AI scan** (photo → categorized items),
  - enable / disable expense categories (via visibility tags),
  - see expense summaries by category / subcategory for a period,
  - export expenses to **CSV** (paid plans only).
- Subscriptions and AI scan limits are managed via Stripe.

---

## 2. Core domain objects (frontend level)

### 2.1. AppUser and settings

- Table `app_users`:
  - `id` — internal user id.
  - `supabase_user_id` — id in Supabase Auth.
  - `email`, `first_name`, `last_name`.
  - `email_verified_at` — whether email is verified.
- Table `app_user_settings` (1:1 with `app_users`):
  - `currency` — currency code (e.g. `USD`, `EUR`, `RUB`).
  - `language` — language (`en`, `ru`, …).
  - `timezone` — IANA timezone (`Europe/Berlin`, etc.), affects transaction dates.

Frontend:

- After register / login the client should create or update settings via `/api/me/settings`.
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

Frontend:

- Visibility settings via:
  - `GET /api/funnel/visibility-questions`
  - `GET /api/visibility-tags`
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
  - It only appears in lists / summaries **after** `/transactions/{id}/confirm`.

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
  - `status_id` → status (`active`, `trialing`, `canceled`, …)
  - `used_checks_count` — how many scans were used in the current period.
  - `current_period_end` — end of billing period.

Frontend:

- `/api/me` returns:
  - `subscription` — active subscription with nested `plan` (or null).
  - `scan_usage` — `{ scans_remaining, scans_limit, period_end }`.
- For AI scans the client should:
  - show remaining scans using `scan_usage`,
  - handle 403 errors:
    - `Subscription required to scan.`
    - `Scan limit reached for this billing period.`

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

Auth is proxied through the backend. The client **never** calls Supabase Auth directly:

- Register: `POST /api/auth/register` → 201 `{ access_token, refresh_token, user }`.
- Login: `POST /api/auth/login` → 200 `{ access_token, refresh_token, user + settings }`.
- Refresh: `POST /api/auth/refresh` → new tokens.
- Logout: `POST /api/auth/logout` with `Authorization: Bearer <token>`.

All protected endpoints require:

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

- `GET /api/me` — profile + active subscription + `scan_usage`.
- `POST /api/me/settings` — create settings during onboarding (once).
- `PUT /api/me/settings` — update language / currency / timezone.

Frontend:

- After registration:
  - call `POST /api/me/settings`,
  - store settings in app state.
- On app start:
  - call `GET /api/me` to load profile, subscription, limits, and settings.

### 4.2. Categories and visibility funnel

- `GET /api/categories` — category tree (with disabled categories filtered out).
- `GET /api/funnel/visibility-questions` — questions per `visibility_tag` for onboarding.
- `GET /api/visibility-tags` — list of tags with `enabled` flag for current user.
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
  - multipart with `image` (jpeg/png).
  - requires active subscription and free scan quota.
  - returns a draft transaction with categorized items.
- `POST /api/transactions/{transaction}/confirm`:
  - confirms AI-generated transaction.
  - sets `confirmed_at`, after which the transaction appears in history and summaries.

Frontend:

- Before scanning:
  - optionally show remaining scans using `scan_usage` from `/api/me`.
- After scan:
  - show recognized items; allow editing categories / amounts;
  - on confirmation call `.../confirm`.

### 4.5. Expense summaries (analytics)

Summaries of expenses over a date range:

- `GET /api/transactions/summary?from&to` — by Level 1 categories.
  - `days`: map `YYYY-MM-DD` → `{ category_id: total_amount_in_cents }`.
  - `meta.totals_by_category` and `meta.grand_total`.
- `GET /api/transactions/summary/{category}?from&to` — by subcategories for one parent category.
  - same shape, but keys in `days` are subcategory ids; plus `category` object in the root.

Frontend:

- For charts / dashboards:
  - combine `days` with `/api/categories` to resolve labels,
  - convert amounts to currency units (`amount / 100`).

### 4.6. CSV export of expenses (Dynamic CSV export)

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

---

## 5. File map — where to look in the backend

If you need more detail:

- Full specification and business rules — `.cursor/backend-spec.md`.
- Machine-readable API route list — `api-routes.json` (importable on the frontend as a reference).
- Key controllers:
  - `App/Http/Controllers/AuthController.php` — register / login / refresh / logout.
  - `App/Http/Controllers/AppUserController.php` — `/me`, `/me/settings`, subscription, `scan_usage`.
  - `App/Http/Controllers/CategoryController.php` — `/categories`.
  - `App/Http/Controllers/CategoryVisibilityController.php` — visibility tags and onboarding.
  - `App/Http/Controllers/ManualTransactionController.php` — transaction CRUD.
  - `App/Http/Controllers/ScanController.php` — AI scan and confirm.
  - `App/Http/Controllers/TransactionSummaryController.php` — expense summaries.
  - `App/Http/Controllers/ExpenseExportController.php` — CSV export.
- Models:
  - `App/Models/AppUser.php`, `App/Models/AppUserSettings.php`
  - `App/Models/Category.php`, `App/Models/Transaction.php`, `App/Models/TransactionItem.php`
  - `App/Models/Plan.php`, `App/Models/UserSubscription.php`

`frontend-backend-spec.en.md` together with `api-routes.json` should be enough to understand which features exist, how data is shaped, and which endpoints are available when working on the frontend.

