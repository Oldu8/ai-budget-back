# AI Budget: Backend Technical Specification (v1.5)

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

| Layer      | Technology                                    |
| ---------- | --------------------------------------------- |
| Framework  | Laravel 12 (PHP 8.2+)                         |
| Database   | PostgreSQL (managed via Supabase)             |
| ORM        | Eloquent ORM                                  |
| Migrations | Laravel Migrations (`php artisan migrate`)    |
| Auth       | Supabase Auth (mobile) + Laravel Auth (admin) |
| AI         | OpenAI SDK (gpt-4o-mini, JSON mode)           |
| Payments   | Stripe SDK (Subscriptions & Webhooks)         |
| Storage    | Supabase Storage (receipt images)             |
| Host       | Railway.app                                   |

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

| Guard | Driver  | Provider    | Purpose         |
| ----- | ------- | ----------- | --------------- |
| `web` | session | `users`     | Admin panel     |
| `app` | session | `app_users` | Mobile (backup) |

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

| Method | Route                | Action            | Body                     |
| ------ | -------------------- | ----------------- | ------------------------ |
| `POST` | `/api/auth/register` | Register new user | `{ email, password }`    |
| `POST` | `/api/auth/login`    | Login             | `{ email, password }`    |
| `POST` | `/api/auth/refresh`  | Refresh tokens    | `{ refresh_token }`      |
| `POST` | `/api/auth/logout`   | Logout            | Header: `Bearer <token>` |

**Protected (requires `Authorization: Bearer <token>`):**

| Method   | Route              | Action                       |
| -------- | ------------------ | ---------------------------- |
| `GET`    | `/api/me`          | Get profile, settings, subscription, `subscription_status`, and scan_usage (scans remaining/limit, period_end) |
| `PUT`    | `/api/me`          | Update profile               |
| `DELETE` | `/api/me`          | Soft-delete account          |
| `GET`    | `/api/me/settings` | Get user settings            |
| `POST`   | `/api/me/settings` | Create settings (onboarding) |
| `PUT`    | `/api/me/settings` | Update user settings         |

| `GET`    | `/api/categories`               | List categories and subcategories (id, slug, label, children); filtered by user's disabled list; optional `?visibility_tag=` for funnel preview     |
| `GET`    | `/api/funnel/visibility-questions` | List funnel questions per visibility tag (visibility_tag, question_key, type, order, translation_key). Frontend uses `t(translation_key + '.title')`, `t(translation_key + '.description')` for i18n.     |
| `GET`    | `/api/visibility-tags`         | List visibility tags with `enabled`, `translation_key` for current user (for settings toggles). Frontend uses `t(translation_key)` for i18n.     |
| `PATCH`  | `/api/me/category-visibility`   | Set tag enabled/disabled: body `{ "visibility_tag", "enabled" }` — disabled = add all categories with tag to app_user_category_settings; enabled = delete those rows     |
| `GET`    | `/api/transactions`             | List transactions (paginated, filters: from, to, type)            |
| `GET`    | `/api/transactions/summary`     | Expense summary by category for date range (query: from, to)      |
| `GET`    | `/api/transactions/summary/{category}` | Expense summary by subcategory for one category (query: from, to) |
| `GET`    | `/api/transactions/export`     | CSV export of expenses for date range (query: from, to); Basic/Plus/Premium only |
| `POST`   | `/api/transactions`             | Create transaction (manual, ManualTransactionController)          |
| `GET`    | `/api/transactions/{id}`        | Get transaction — JSON envelope `{ "data": { ... } }` (same shape as scan / receipt update / show) |
| `PUT`    | `/api/transactions/{id}`        | Update transaction                                                  |
| `DELETE` | `/api/transactions/{id}`        | Soft-delete transaction                                             |

| `POST`   | `/api/transactions/scan`               | AI scan: upload image; 201 `{ "data": { ... } }` (transaction with `items[].category`) |
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

### 3.6. Email Confirmation (Supabase)

Подтверждение почты полностью обрабатывается Supabase. Бэкенд не создаёт отдельных роутов для подтверждения.

**Текущее поведение:**
- `app_users.email_verified_at` — синхронизируется из Supabase `email_confirmed_at` в middleware
- Юзер в ответах auth получает `user.email_verified_at` (null до подтверждения)
- **Ничего не блокируется** — юзер может пользоваться приложением без подтверждения

**Планируется (после реализации транзакций):**
- Транзакции (создание, просмотр) будут доступны только при `email_verified_at !== null`
- Остальные эндпоинты (профиль, настройки) — без ограничений

**Настройка Supabase (после деплоя):**

1. Supabase Dashboard → Authentication → Providers → Email → **Confirm email** (уже включено)
2. Authentication → URL Configuration:
   - **Site URL** — URL приложения (например `https://yourapp.com` или deep link `myapp://`)
   - **Redirect URLs** — куда редиректить после клика по ссылке в письме (например `myapp://auth/confirm`)
3. Email Templates → Confirm signup — при необходимости изменить текст письма

**Порядок:** сначала деплой бэка и фронта, затем добавить реальные ссылки в Supabase.

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
- **Level 1 (parent)** — категории агрегации (e.g. Housing, Food). НЕ выбираются пользователем при создании транзакции. На фронте отображают сумму всех дочерних подкатегорий.
- **Level 2 (child)** — конкретные подкатегории (e.g. Rent, Groceries). Именно их пользователь выбирает при создании/редактировании транзакции.
- `transaction_items.category_id` всегда ссылается на Level 2 категорию. Привязка к Level 1 запрещена на уровне клиента (parent-категории не отображаются как опции для выбора).

**Seeding categories:** `php artisan categories:seed`. Data: `database/data/categories.php`. Uses `updateOrCreate` by `name` — safe to run multiple times.

### 4.3. Transactions, Transaction Items & Recurring Expenses

**transactions** — операция / чек (дата, общая сумма, картинка).

- `total_amount` — integer (в копейках, сумма чека)
- `type` — enum: `income`, `expense`
- `created_by` — enum: `user`, `ai`, `recurring`
- `raw_ai_data` (JSON, nullable) — полный ответ OpenAI для AI-чеков
- `confirmed_at` (nullable) — когда транзакция подтверждена
- `img_path` (nullable) — путь к фото чека в Supabase Storage
- `recurring_expense_id` (nullable) — FK на `recurring_expenses` (nullOnDelete)
- `recurring_run_at` (nullable, datetime) — дата/время, для какого запуска правила создана транзакция (используется для защиты от дублей)
- Soft deletes
- Indexes: (app_user_id, date), (app_user_id, date, type), (recurring_expense_id, recurring_run_at)

**transaction_items** — позиции внутри операции (категория, сумма, название).

- `transaction_id` — FK на transactions (cascadeOnDelete)
- `category_id` — FK на categories (restrictOnDelete)
- `amount` — integer (в копейках)
- `label` (nullable) — название позиции (хлеб, бананы и т.д.)
- Indexes: (transaction_id, category_id), (category_id)

**Структура:**
- **Ручная:** 1 transaction с total_amount, 1 transaction_item с amount = total_amount
- **AI-чек:** 1 transaction с общей суммой, N transaction_items по категориям
- **Рекуррентный расход:** 1 transaction с total_amount, 1 transaction_item (amount = total_amount) по одной категории; создаётся по расписанию, всегда с `type = expense`, `created_by = 'recurring'`, `confirmed_at = now()`

**confirmed_at — ключевое поле для видимости транзакции:**
- **Manual:** устанавливается сразу (`now()`) при создании
- **AI:** `null` при создании. Заполняется только когда юзер подтверждает результат сканирования
- **Неподтверждённые транзакции (`confirmed_at IS NULL`):**
  - НЕ отображаются в списке транзакций (`GET /api/transactions`)
  - НЕ учитываются в агрегации по месяцу/категориям (CTE)
  - Существуют в БД, но невидимы для итоговых данных на всех устройствах
- Это гарантирует, что если юзер отсканировал чек, но не подтвердил — транзакция не повлияет на статистику и не появится в списках

### 4.4. Subscriptions & Billing (Stripe)

**plans** — catalog of subscription tiers (no timestamps).

- `type` — enum: `free`, `basic`, `plus`, `premium`
- `payment_price_id` (nullable) — Stripe Price ID (null for free plan)
- `price` — integer (cents)
- `label` — display name (e.g. Free, Basic, Plus, Premium)
- `description` (nullable) — text listing plan features (for UI/table)
- `check_limit` — max AI scans per billing cycle

**Тарифы (каталог планов)**

Фиксированный порядок тарифов: **Free → Basic → Plus → Premium**. Порядок признаков для описания/UI: AI scans, Manual transactions, History, Categories, Recurring transactions, Analytics, CSV export, прочее.

| Plan    | Price | AI scans | Manual | History   | Categories | Recurring | Analytics              | CSV | Other                |
| ------- | ----- | -------- | ------ | --------- | ---------- | --------- | ---------------------- | --- | -------------------- |
| Free    | $0    | 20/mo    | unlim  | 1 month   | basic only | —         | —                      | —   | —                    |
| Basic   | $3    | 200/mo   | unlim  | unlimited | custom     | —         | —                      | yes | —                    |
| Plus    | $5    | 500/mo   | unlim  | unlimited | custom     | yes       | dashboard              | yes | —                    |
| Premium | $10   | 2000/mo  | unlim  | unlimited | custom     | yes       | advanced + AI insights | yes | AI spending insights |

Seeding: `php artisan plans:seed`. Data: `database/data/plans.php`. Uses `updateOrCreate` by `type` — safe to run multiple times.

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
- **Semantics (Stripe-aligned; same row may apply to subscriptions or payments):**
  - **`active`** — subscription is paid and in good standing for the current period; app features that require a plan are available (subject to plan type and quotas).
  - **`trialing`** — user is in a trial period; treated like active for access where `activeSubscription()` includes both `active` and `trialing`.
  - **`past_due`** — latest invoice payment failed; subscription may still be retried by the payment provider. Not treated as active in `activeSubscription()` until status is updated back to `active` (or trialing).
  - **`canceled`** — subscription ended or was canceled; access follows product rules (e.g. until `current_period_end`). Not returned as the current row from `activeSubscription()`.
  - **`pending`** — subscription or payment not yet fully activated (e.g. awaiting first successful payment).

**recurring_expenses** — правила для рекуррентных расходов.

- `app_user_id` — FK на app_users (cascadeOnDelete)
- `category_id` — FK на categories (restrictOnDelete), всегда Level 2 категория
- `amount` — integer (в копейках), сумма одного списания
- `label` (nullable) — подпись/описание расхода
- `starts_at` (datetime) — дата/время старта правила
- `frequency` — string: `weekly` | `monthly` | `yearly`
- `interval` — integer, шаг между запусками (по умолчанию 1)
- `ends_at` (nullable, datetime) — опциональная дата окончания; если null — бесконечно
- `is_active` — boolean, включает/выключает правило (по умолчанию true)
- `last_run_at` (nullable, datetime) — когда правило выполнялось в последний раз
- `next_run_at` (datetime) — запланированное время следующего запуска
- Soft deletes включены

**Правила:**
- `category_id` всегда Level 2 (подкатегория, `categories.category_id IS NOT NULL`).
- Категория не должна быть в `app_user_category_settings` для пользователя (скрытые категории нельзя выбирать).
- При выключении правила (`is_active = false`) планировщик его не обрабатывает.
- При включении (`toggle` с false → true) `next_run_at` двигается вперёд до ближайшего будущего запуска.

## 5. Key Business Rules

### 5.1. Manual Transactions (ManualTransactionController)

- `POST /api/transactions` — body: `{ total_amount, date, type, category_id }`
- `type`: `income` | `expense`
- `total_amount` — в копейках (целое число)
- Создаётся 1 transaction + 1 transaction_item (amount = total_amount)
- `created_by` = `user`, `confirmed_at` = now() (сразу)
- Список: `GET /api/transactions?from=YYYY-MM-DD&to=YYYY-MM-DD&type=expense&per_page=20`

### 5.2. Categories list and transaction summary

Эндпоинты для отображения дерева категорий и сводки трат за период. Учитываются только подтверждённые транзакции (`confirmed_at IS NOT NULL`), тип `expense`, данные текущего пользователя. Период задаётся параметрами `from` и `to` (YYYY-MM-DD); в ответах не передаются лейблы категорий/подкатегорий — только id, фронт резолвит названия по списку категорий.

**1. Список категорий и подкатегорий**

- **Route:** `GET /api/categories`. Optional query `?visibility_tag=X` — return only categories with that tag (for funnel preview; no user filter). Without query — categories are filtered by user's disabled list: any category whose id is in `app_user_category_settings` for the current user is excluded.
- **Response:** `{ "data": [ { "id", "slug", "label", "children": [ { "id", "slug", "label" } ] } ] }`
- `slug` = поле `categories.name` из БД. Level 1 (родители) — объекты с массивом `children` (Level 2). Используется как единственный источник id → slug/label для подписей на графиках и в сводках.

**2. Сводка по категориям (Level 1) за период**

- **Route:** `GET /api/transactions/summary?from=YYYY-MM-DD&to=YYYY-MM-DD`
- **Query:** `from`, `to` — обязательны, даты, `to >= from`.
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

- `days` — объект «дата → объект category_id → сумма в копейках за день». Все даты в интервале присутствуют (при отсутствии трат — пустой объект). Only categories not in the user's `app_user_category_settings` are included in aggregation.
- `meta.totals_by_category` — сумма по каждой категории за период; `meta.grand_total` — общая сумма.

**3. Сводка по подкатегориям одной категории за период**

- **Route:** `GET /api/transactions/summary/{category}?from=YYYY-MM-DD&to=YYYY-MM-DD`
- **URL:** `{category}` — id категории Level 1. Если категория не верхнего уровня — 404.
- **Query:** `from`, `to` — как выше.
- **Response:** то же, что в п.2, плюс в начале объект `category: { "id", "slug", "label" }`; в `days` ключи — id подкатегорий (Level 2); в `meta` — `totals_by_subcategory` и `grand_total` (итог по выбранной категории).

**Файлы:** `CategoryController` (index), `TransactionSummaryController` (index, show), `SummaryRangeRequest`, `SummaryResponse` (DTO).

### 5.3. Recurring expenses (rules + scheduler)

**Доступ по тарифу:**

- Рекуррентные расходы доступны только пользователям с активной/пробной подпиской на планах **Plus** или **Premium**.
- Проверка делается через `FeatureAccessService::canAccess($user, 'recurring_expenses')` (middleware `feature.access:recurring_expenses` на routes).
  - Берётся `activeSubscription()` (status `active` или `trialing`) с планом.
  - Разрешены только планы с `plan.type` = `plus` или `premium`.
  - Если нет подходящей подписки → 403 `"Recurring expenses are not available on your plan."`

**API: правила рекуррентных расходов**

- Все эндпоинты защищены `supabase.auth` и работают только в контексте текущего `AppUser`.
- **Routes:**
  - `GET /api/recurring-expenses` — список правил текущего пользователя.
  - `POST /api/recurring-expenses` — создание правила.
  - `GET /api/recurring-expenses/{id}` — получить одно правило.
  - `PUT /api/recurring-expenses/{id}` — обновление правила.
  - `DELETE /api/recurring-expenses/{id}` — soft-delete правила.
  - `PATCH /api/recurring-expenses/{id}/toggle` — включить/выключить правило (`is_active`).

**Валидация (Store/UpdateRecurringExpenseRequest):**

- `category_id`:
  - `required|integer|exists:categories,id` (для создания),
  - только Level 2 (`categories.category_id IS NOT NULL`),
  - категория не должна быть в `app_user_category_settings` для текущего пользователя.
- `amount`: `required|integer|min:1` (или `sometimes` для update).
- `label`: `nullable|string|max:255`.
- `starts_at`: `required|date` (или `sometimes` для update).
- `frequency`: `required|in:weekly,monthly,yearly` (или `sometimes`).
- `interval`: `integer|min:1` (по умолчанию 1).
- `ends_at`: `nullable|date|after_or_equal:starts_at`.

**Создание правила (POST /api/recurring-expenses):**

- Использует `StoreRecurringExpenseRequest`.
- `app_user_id` берётся из `$request->user()->id`.
- При создании:
  - `interval` по умолчанию 1.
  - `next_run_at` = `max(starts_at, now())` — чтобы правило либо стартовало в будущем, либо как можно скорее.
  - `is_active` = true.

**Обновление (PUT /api/recurring-expenses/{id}):**

- Работает только с правилами текущего пользователя.
- При изменении `starts_at`/`frequency`/`interval` — пересчитывается `next_run_at` по тому же правилу `max(starts_at, now())`, чтобы не вызвать каскадный запуск задним числом.

**Toggle (PATCH /api/recurring-expenses/{id}/toggle`):**

- Меняет `is_active` на противоположное.
- При включении (`is_active = true`):
  - Берёт `next_run_at` (или `starts_at`, если оно не задано; иначе `now()`).
  - Пока `next_run_at < now()` — двигает его вперёд на один шаг (`interval` в зависимости от `frequency`).
  - Сохраняет обновлённый `next_run_at`, чтобы следующее списание было в будущем.

**Планировщик (app:run-recurring-expenses):**

- Консольная команда `app:run-recurring-expenses` (`RunRecurringExpensesCommand`).
- Планируется в `App\Console\Kernel` раз в час:

  - `$schedule->command('app:run-recurring-expenses')->hourly()->withoutOverlapping()->onOneServer();`

- Отбирает правила:

  - `is_active = true`
  - `next_run_at <= now() + 14 hours` (предварительная фильтрация с учётом максимального UTC+14)
  - `deleted_at IS NULL` (через SoftDeletes)

- Обработка:

  - Идёт по правилам чанками (`chunkById(100)`), упорядоченным по `next_run_at`.
  - Для каждого правила:
    - Открывает DB-транзакцию.
    - Берёт правило с `lockForUpdate()` по `id`.
    - Повторно проверяет условия (`is_active`, `next_run_at <= userToday`, `next_run_at <= ends_at`).
    - Берёт пользователя `appUser` и ещё раз проверяет `FeatureAccessService::canAccess($user, 'recurring_expenses')` (если подписка больше не подходит — правило пропускается).
    - Определяет `userToday` по таймзоне юзера (`app_user_settings.timezone`). Сравнивает `next_run_at` (startOfDay) с `userToday` — если ещё не наступил день, пропускает.
    - Проверяет `ends_at`: если `next_run_at > ends_at` — пропускает (правило завершено).
    - Запоминает текущее `runAt = next_run_at` (дата, startOfDay).
    - Проверяет дубликаты:
      - Поиск `transactions` с тем же `recurring_expense_id` и `recurring_run_at = runAt`.
      - Если такая транзакция уже есть — просто двигает правило вперёд (обновляет `last_run_at` и `next_run_at`) и выходит.
    - Создаёт:
      - 1 `transaction`:
        - `app_user_id` = `recurring_expenses.app_user_id`
        - `total_amount` = `amount`
        - `date` = `runAt` (дата, `Y-m-d`)
        - `type` = `expense`
        - `created_by` = `recurring`
        - `confirmed_at` = `now()`
        - `recurring_expense_id` = id правила
        - `recurring_run_at` = `runAt`
      - 1 `transaction_item`:
        - `category_id` = `recurring_expenses.category_id`
        - `amount` = `recurring_expenses.amount`
        - `label` = `recurring_expenses.label`
    - Обновляет правило:
      - `last_run_at` = `runAt`
      - `next_run_at` = `runAt`, сдвинутый на один шаг:
        - `weekly`: `+ interval weeks`
        - `monthly`: `+ interval months (no overflow)`
        - `yearly`: `+ interval years`

- **Без бэкфилла:** если правило сильно «просрочено» (несколько периодов подряд не запускалось), при одном проходе команды создаётся только **одна** транзакция, а `next_run_at` сдвигается ровно на один шаг вперёд. Следующий запуск произойдёт уже на следующем цикле планировщика.

**Влияние на списки и сводки:**

- Рекуррентно созданные транзакции:
  - `type = expense`
  - `confirmed_at IS NOT NULL`
  - `transaction_items` с валидной Level 2 категорией.
- Поэтому они автоматически:
  - Видны в `GET /api/transactions` (ManualTransactionController@index), если попадают в диапазон дат.
  - Участвуют в агрегации в `TransactionSummaryController` (обе сводки), т.к. фильтры уже учитывают только `type = expense` и `confirmed_at IS NOT NULL`.

### 5.3. Агрегация по месяцу и категориям (концепция / legacy SQL)

Items поднимаются вверх по дереву категорий. Шампунь и гель из разных транзакций суммируются в home_cosmetics и home. Фактическая отдача данных — через эндпоинты summary (см. 5.2).

**Результат (концептуально):** `date`, `category_id`, `category_name`, `total_amount`.

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

На фронте: маппить `category_id` → `category_name` (или использовать `name` как ключ). Реализация — через summary API (5.2).

### 5.4. AI Scan Pipeline (ScanController)

**Route:** `POST /api/transactions/scan` — multipart/form-data, field `image` (jpeg/jpg/png/webp, max 5 MB).

**Flow:**

1. Check scan quota via `FeatureAccessService::canUseAiScan($user)`: load user's active subscription (with plan). If no active subscription → 403 "Subscription required to scan." If `used_checks_count >= plan.check_limit` → 403 "Scan limit reached for this billing period." (Done before upload/AI to avoid wasting resources.)
2. Validate image (ScanRequest: file type, size ≤ 5 MB)
3. Base64-encode the uploaded file (still in memory)
4. Upload original to Supabase Storage (private bucket `receipts`, service role key, path: `{userId}/{uuid}.{ext}`)
5. Fetch all Level 2 categories from DB that are not in the user's `app_user_category_settings` (disabled list); then with('parent').
6. Build prompt with categories grouped by parent (ScanPromptBuilder), send base64 image to OpenAI GPT-4o-mini (JSON mode)
7. Validate AI response structure (`is_receipt`, `total_amount`, `date`, `items` must be present)
8. If `is_receipt = false` → delete image from Storage, return 422
9. If AI call fails → keep image in Storage (ops); return 503 **without** `img_path` (no retry endpoint; client uploads again).
10. If valid → in one DB transaction: create Transaction (`created_by = 'ai'`, `confirmed_at = null`, `type = expense`) + N TransactionItems, then increment `user_subscriptions.used_checks_count` for the active subscription.
11. Return 201 with unconfirmed transaction (invisible in lists/aggregations until saved via receipt update)

**Receipt update route:** `PUT /api/transactions/{id}/update` — body same as former draft (`date`, `items[]` with optional `id` per line). In **one** DB transaction: sync line items, recalculate `total_amount`, set `confirmed_at = now()`. Only AI-created, unconfirmed transactions; wrong state → 404.

**Key implementation details:**

- **Category fallback:** if AI returns an invalid `category_id`, the item falls back to `finance_other_misc`. The prompt also instructs AI to use `*_other` subcategories when no specific match fits.
- **Prompt (ScanPromptBuilder):** categories are passed grouped by parent label. AI must return strict JSON with amounts in smallest currency unit (cents/kopecks).
- **Type is always `expense`** — hardcoded in controller, not requested from AI.
- **Private bucket** — Supabase Storage bucket `receipts` is private. All access (upload, download, delete) uses the service role key. No public URLs, no RLS policies needed. OpenAI receives image as base64, not URL.
- **`raw_ai_data`** stores the full OpenAI response JSON for debugging.

**AI response schema (from OpenAI):**

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
- `app/Services/SupabaseStorageService.php` — Supabase Storage REST API (upload, delete, signed URLs)
- `app/Http/Requests/ScanRequest.php` — image validation

**Между шагами 10–11 и `PUT .../update`** транзакция существует в БД, но не учитывается ни в списках, ни в статистике. Подтверждение происходит вместе с финальным сохранением правок одним запросом.

### 5.5. Visibility tags and category settings

**Data:** Table `visibility_questions` — list of visibility questions (visibility_tag, question_key, type, order, translation_key, is_active). Seeded by `php artisan visibility-questions:seed`. Backend provides structure only; frontend handles translation via i18n. Used by `GET /api/funnel/visibility-questions` and `GET /api/visibility-tags`.

**Rules:**
- Categories (and subcategories) whose id is in `app_user_category_settings` for the user are **hidden**: excluded from `GET /api/categories`, from AI scan category list, and from transaction summary aggregation.
- **Disable by tag** (e.g. front sends `kids` = false): insert into `app_user_category_settings` one row per category that has `visibility_tag = 'kids'` (with `visibility_tag` stored in the row). **Enable by tag:** delete all rows for that user where `visibility_tag = 'kids'`.
- **GET /api/visibility-tags** returns each tag with `enabled: true` if the user has no rows with that tag in `app_user_category_settings`; otherwise `enabled: false`.
- Funnel: show questions from `/api/funnel/visibility-questions`; on answer "no" for a tag, call `PATCH /api/me/category-visibility` with `{ "visibility_tag": "kids", "enabled": false }`. Settings screen: list tags with toggles from `/api/visibility-tags`, update via same PATCH.
- Future: per-category toggle = insert/delete single row by `category_id`.

### 5.6. Scan quota (plan-based limit)

Each plan defines `check_limit` (max AI scans per billing period). The count is stored in `user_subscriptions.used_checks_count` and is incremented after each successful scan (when a transaction is created); it is reset to 0 by the Stripe `invoice.paid` webhook at the start of a new billing cycle.

**Before calling OpenAI (in `ScanController::scan()`):**

- Load the user's active subscription (status `active` or `trialing`) with `plan`. If there is no active subscription → 403 with message "Subscription required to scan."
- If `used_checks_count >= plan.check_limit` → 403 with message "Scan limit reached for this billing period."
- After successfully creating the transaction and items (inside the same DB transaction), call `$subscription->increment('used_checks_count')`.

**GET /api/me response** includes subscription and scan usage so the client can show remaining scans without a separate endpoint:

- **`subscription`**: The active subscription with nested `plan` (id, type, label, check_limit, etc.), plus `current_period_end`, `used_checks_count`, etc.; or `null` if the user has no active subscription. (Only subscriptions with status `active` or `trialing` are selected; see `AppUser::activeSubscription()`.)
- **`subscription_status`**: String — the `statuses.name` for that subscription (typically `active` or `trialing` when `subscription` is non-null); `null` if there is no active subscription row.
- **`scan_usage`**: Object `{ scans_remaining, scans_limit, period_end }`. If no subscription: `scans_remaining: 0`, `scans_limit: 0`, `period_end: null`. If subscription exists: `scans_remaining = max(0, plan.check_limit - used_checks_count)`, `scans_limit = plan.check_limit`, `period_end` = end of current billing period (ISO 8601).

### 5.7. Stripe Integration (StripeController)

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
  - `invoice.paid` — creates/updates `payments` record. Subscription id is taken from `subscription`, or `parent.subscription_details.subscription`, or line `parent.subscription_item_details.subscription` when the top-level `subscription` field is missing. Recovers subscription from `past_due`/`pending` to `active`. Resets `used_checks_count` only on renewal (`billing_reason = subscription_cycle`).
  - `invoice.payment_failed` — sets subscription status to `past_due`.
- Missing AppUser for a customer ID is logged and reported via `ErrorNotifier`, webhook returns 200 without throwing.

**Free Plan Auto-Creation** (`EnsureDefaultSubscriptionService`):
- Called in `AuthController::register()` and `login()` after user creation/lookup.
- Ensures every user has an active subscription. If no active subscription exists, creates a local-only free subscription (`payment_subscription_id = null`, `current_period_end = null`, `used_checks_count = 0`).
- Free plan is local-only — no Stripe Product/Price. When paid subscription activates, free is canceled. When paid is deleted, free is re-created with fresh usage.

**Plans** (`GET /api/plans`):
- Returns all plans ordered Free → Basic → Plus → Premium with currency-aware `price_id` from config.

### 5.8. CSV export of expenses

- **Route:** `GET /api/transactions/export?from=YYYY-MM-DD&to=YYYY-MM-DD`
- **Access:** Enforced by `feature.access:csv_export` middleware via `FeatureAccessService`. Only for users with active subscription on Basic, Plus, or Premium plan. If no subscription or plan is Free → 403 "CSV export is not available on your plan."
- **Query:** `from`, `to` — required, dates, `to >= from`. Max range (e.g. one year) is enforced on the client.
- **Data:** Confirmed expense transactions in the date range; transaction items whose category is not in the user's disabled list. One CSV row per transaction item.
- **Response:** Streamed CSV file, `Content-Disposition: attachment; filename="expenses_YYYY-MM-DD_YYYY-MM-DD.csv"`. UTF-8 with BOM. Columns: Date, Category (subcategory label), Amount (in currency units, 2 decimals), Currency (from app_user_settings), Label (item label or empty).

## 6. Environment Variables

| Variable                | Purpose                                                                               |
| ----------------------- | ------------------------------------------------------------------------------------- |
| `DB_CONNECTION`         | `pgsql` — использовать PostgreSQL                                                     |
| `DB_URL`                | Connection string к Supabase PostgreSQL (Settings → Database → URI)                   |
| `SUPABASE_URL`          | Project URL (Settings → API → Project URL)                                            |
| `SUPABASE_ANON_KEY`     | Используется в AuthController для register/login/refresh (Supabase → anon public)     |
| `SUPABASE_SERVICE_ROLE` | Используется в middleware и logout (валидация токена, Supabase → service_role secret) |
| `OPENAI_API_KEY`        | OpenAI API key для AI scan                                                            |
| `SUPABASE_STORAGE_BUCKET` | Supabase Storage bucket name (default: `receipts`)                                  |
| `OPENAI_REQUEST_TIMEOUT`| OpenAI request timeout in seconds (default: 60)                                       |
| `STRIPE_SECRET_KEY`     | Stripe secret key                                                                   |
| `STRIPE_PUBLIC_KEY`     | Stripe publishable key (for frontend if needed)                                     |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret                                                       |
| `STRIPE_SUCCESS_URL`    | Redirect after successful checkout; if unset, defaults to `{APP_URL}/stripe/checkout/success` (public test page) |
| `STRIPE_CANCEL_URL`     | Redirect after canceled checkout; if unset, defaults to `{APP_URL}/stripe/checkout/cancel` (public test page)   |
| `STRIPE_BASIC_PRICE_EUR` / `STRIPE_BASIC_PRICE_USD` | Stripe Price IDs для плана Basic (EUR / USD)                    |
| `STRIPE_PLUS_PRICE_EUR` / `STRIPE_PLUS_PRICE_USD`   | Stripe Price IDs для плана Plus (EUR / USD)                   |
| `STRIPE_PREMIUM_PRICE_EUR` / `STRIPE_PREMIUM_PRICE_USD` | Stripe Price IDs для плана Premium (EUR / USD)            |
| `DISCORD_ERRORS_WEBHOOK_URL` | Ошибки / Stripe issues → `#error-logs` (fallback: `DISCORD_WEBHOOK_URL`) |
| `DISCORD_REGISTRATIONS_WEBHOOK_URL` | Успешная регистрация → `#new-users` |
| `DISCORD_PAYMENTS_WEBHOOK_URL` | Старт платной подписки / отмена подписки → `#payment-info` (не продления) |
| `DISCORD_REGISTRATION_INCLUDE_EMAIL` | `true` — в сообщении о регистрации полный email; иначе только `app_user_id` + домен |

**Supabase keys:**

- `SUPABASE_ANON_KEY` — для прокси auth-запросов (signup, token)
- `SUPABASE_SERVICE_ROLE` — для проверки JWT и logout (никогда не отдавать клиенту)

## 7. MVP Scope

Included:

- Auth proxy: register, login, refresh, logout (AuthController)
- Supabase Auth middleware (mobile users)
- Laravel Auth (admin users)
- Manual transaction CRUD
- AI receipt scanning (single photo → transaction suggestion → `PUT .../update` to save + confirm)
- Category system (static, seeded)
- Category visibility: app_user_category_settings, funnel/visibility-questions, visibility-tags, PATCH category-visibility; categories/summary/scan respect disabled list
- Subscription plans with AI scan limits
- Stripe integration (checkout, webhooks)
- CSV export of expenses (GET /api/transactions/export; Basic/Plus/Premium only)

Deferred (post-MVP):

- Per-category (single category_id) toggle in settings (table supports it; API can be extended)
- Google Sheets export
- Chat/message history for AI interactions
- Multi-currency support
