# AI Budget: Backend Technical Specification (v1.2)

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

```
DATABASE_URL=
OPENAI_API_KEY=
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
SUPABASE_URL=
SUPABASE_SERVICE_ROLE=
SUPABASE_ANON_KEY=
```

## 7. MVP Scope

Included:
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
