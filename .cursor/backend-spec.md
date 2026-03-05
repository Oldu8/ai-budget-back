# AI Budget: Backend Technical Specification (v1.3)

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
| `GET`    | `/api/me`          | Get profile + settings       |
| `PUT`    | `/api/me`          | Update profile               |
| `DELETE` | `/api/me`          | Soft-delete account          |
| `GET`    | `/api/me/settings` | Get user settings            |
| `POST`   | `/api/me/settings` | Create settings (onboarding) |
| `PUT`    | `/api/me/settings` | Update user settings         |

| `GET`    | `/api/categories`        | List categories (tree: parent + children)              |
| `GET`    | `/api/transactions`      | List transactions (paginated, filters: from, to, type) |
| `POST`   | `/api/transactions`      | Create transaction (manual, ManualTransactionController) |
| `GET`    | `/api/transactions/{id}` | Get transaction                                         |
| `PUT`    | `/api/transactions/{id}` | Update transaction                                      |
| `DELETE` | `/api/transactions/{id}` | Soft-delete transaction                                 |

AI-создание транзакций — отдельный роут и контроллер (будущее): `POST /api/transactions/from-receipt` или аналогичный.

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

### 4.3. Transactions & Transaction Items

**transactions** — операция / чек (дата, общая сумма, картинка).

- `total_amount` — integer (в копейках, сумма чека)
- `type` — enum: `income`, `expense`
- `created_by` — enum: `user`, `ai`
- `raw_ai_data` (JSON, nullable) — полный ответ OpenAI для AI-чеков
- `confirmed_at` (nullable) — когда транзакция подтверждена
- `img_path` (nullable) — путь к фото чека в Supabase Storage
- Soft deletes
- Indexes: (app_user_id, date), (app_user_id, date, type)

**transaction_items** — позиции внутри операции (категория, сумма, название).

- `transaction_id` — FK на transactions (cascadeOnDelete)
- `category_id` — FK на categories (restrictOnDelete)
- `amount` — integer (в копейках)
- `label` (nullable) — название позиции (хлеб, бананы и т.д.)
- Indexes: (transaction_id, category_id), (category_id)

**Структура:**
- **Ручная:** 1 transaction с total_amount, 1 transaction_item с amount = total_amount
- **AI-чек:** 1 transaction с общей суммой, N transaction_items по категориям

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

### 5.1. Manual Transactions (ManualTransactionController)

- `POST /api/transactions` — body: `{ total_amount, date, type, category_id }`
- `type`: `income` | `expense`
- `total_amount` — в копейках (целое число)
- Создаётся 1 transaction + 1 transaction_item (amount = total_amount)
- `created_by` = `user`, `confirmed_at` = now() (сразу)
- Список: `GET /api/transactions?from=YYYY-MM-DD&to=YYYY-MM-DD&type=expense&per_page=20`

### 5.2. Агрегация по месяцу и категориям (рекурсивный CTE)

Items поднимаются вверх по дереву категорий. Шампунь и гель из разных транзакций суммируются в home_cosmetics и home.

**Результат:** `date`, `category_id`, `category_name`, `total_amount`.

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

На фронте: маппить `category_id` → `category_name` (или использовать `name` как ключ).

### 5.3. AI Receipt Processing Pipeline

1. Receive image path and context from client
2. Fetch user's active categories (only Level 2 — leaf categories)
3. Construct prompt with categories list, send to OpenAI (JSON mode)
4. OpenAI returns: items (amount, category_id, label), total amount, date
5. Create Transaction (`created_by = 'ai'`, `confirmed_at = null`) + N TransactionItems
6. Return transaction to client for review (transaction is invisible in lists/aggregations until confirmed)
7. User reviews items, may edit categories/amounts, then confirms → set `confirmed_at = now()`
8. Increment `used_checks_count` in UserSubscription

**Важно:** между шагами 5 и 7 транзакция существует в БД, но не учитывается ни в списках, ни в статистике. Это позволяет юзеру отсканировать чек на одном устройстве и подтвердить позже, при этом неподтверждённые данные не влияют на финансовую картину.

### 5.4. Quota Enforcement

Before calling OpenAI:

- Verify `user_subscription.used_checks_count < plan.check_limit`
- If limit reached → 403 Forbidden with "Limit Exceeded"

### 5.5. Stripe Webhook Handling

- `checkout.session.completed` → activate new subscription
- `invoice.paid` → reset `used_checks_count` to 0 for new billing cycle
- `customer.subscription.deleted` → update status to canceled, restrict access

## 6. Environment Variables

| Variable                | Purpose                                                                               |
| ----------------------- | ------------------------------------------------------------------------------------- |
| `DB_CONNECTION`         | `pgsql` — использовать PostgreSQL                                                     |
| `DB_URL`                | Connection string к Supabase PostgreSQL (Settings → Database → URI)                   |
| `SUPABASE_URL`          | Project URL (Settings → API → Project URL)                                            |
| `SUPABASE_ANON_KEY`     | Используется в AuthController для register/login/refresh (Supabase → anon public)     |
| `SUPABASE_SERVICE_ROLE` | Используется в middleware и logout (валидация токена, Supabase → service_role secret) |
| `OPENAI_API_KEY`        | OpenAI API key для AI receipt scanning                                                |
| `STRIPE_SECRET_KEY`     | Stripe secret key (отложено)                                                          |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret (отложено)                                              |

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
