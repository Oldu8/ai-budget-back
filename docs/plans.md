# Subscription plans (ai-budget-backend)

Single reference for prices, limits, marketing copy, what the backend actually enforces, and where it lives in the repo.

## Order and identifiers

Fixed order: **Free → Basic → Plus → Premium**.

| `type` (enum `PlanType`) | Label (`label`) |
| ------------------------ | --------------- |
| `free`                   | Free            |
| `basic`                  | Basic           |
| `plus`                   | Plus            |
| `premium`                | Premium         |

Paid types in code: `PlanType::paidValues()` → `basic`, `plus`, `premium`.

---

## Prices and AI scan limits

Prices in the database are stored in **cents** (`plans.price`). Reference dollar amounts are for comparison with the spec; actual Stripe Price IDs come from env (see below).

| Plan    | Ref. price | `price` (cents) | AI scans / period (`check_limit`) |
| ------- | ---------- | --------------- | --------------------------------- |
| Free    | $0         | 0               | 20                                |
| Basic   | $3         | 300             | 200                               |
| Plus    | $5         | 500             | 500                               |
| Premium | $10        | 1000            | 2000                              |

Seed data source: `database/data/plans.php`. Command: `php artisan plans:seed` (idempotent via `updateOrCreate` on `type`).

---

## Marketing feature matrix (product / UI)

Guide for screens and comparison tables (column order from the spec): AI scans (photos per scan — see below), Manual transactions, History, Categories, Recurring, Analytics, CSV, other.

| Plan    | Price | AI scans | Manual | History   | Categories | Recurring | Analytics | CSV | Other                           |
| ------- | ----- | -------- | ------ | --------- | ---------- | --------- | --------- | --- | ------------------------------- |
| Free    | $0    | 20/mo    | unlim  | 1 month   | basic only | —         | —         | —   | 1 photo per scan                |
| Basic   | $3    | 200/mo   | unlim  | unlimited | custom     | —         | —         | yes | 1 photo per scan                |
| Plus    | $5    | 500/mo   | unlim  | unlimited | custom     | yes       | dashboard | yes | multiple photos per scan        |
| Premium | $10   | 2000/mo  | unlim  | unlimited | custom     | yes       | advanced  | yes | multiple photos; voice expenses |

**Category visibility** (choosing groups to show or hide) is a **base capability on all tiers** — not shown in the table; there is no separate subscription gate.

**AI scan — photos per session:** Free and Basic — one image; Plus and Premium — multiple images (e.g. multi-page receipt).

**Premium:** no “AI spending insights”; the differentiator is **recording expenses via voice messages**.

Some differences (history length, basic vs custom categories, “advanced” analytics, voice, multi-photo) are **not** separate flags in `config/feature_access.php` — they are product copy and/or frontend logic; confirm against domain endpoints and `backend-spec.md`.

### UI copy from seeds (`plans.description`)

Multi-line strings in `database/data/plans.php` (returned by `GET /api/plans`):

- **Free:** AI scans 20/month (1 photo per scan); Manual unlimited; History 1 month; Basic categories only; Category visibility controls.
- **Basic:** AI 200/mo (1 photo per scan); Manual unlimited; History unlimited; Custom categories; CSV export; Category visibility controls.
- **Plus:** AI 500/mo (multiple photos per scan); Manual unlimited; History unlimited; Custom categories; Recurring; Analytics dashboard; CSV export; Category visibility controls.
- **Premium:** AI 2000/mo (multiple photos per scan); Manual unlimited; History unlimited; Custom categories; Recurring; Advanced analytics; Add expenses via voice messages; CSV export; Category visibility controls.

---

## Backend enforcement (feature access)

Config: `config/feature_access.php`. Service: `App\Services\FeatureAccessService`.

| Feature (`feature`)   | Allowed plans              | Quota                                      | Denied message(s)                                                                                   |
| --------------------- | -------------------------- | ------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| `ai_scan`             | free, basic, plus, premium | yes (`used_checks_count` vs `check_limit`) | «Subscription required to scan.» / «Scan limit reached for this billing period.»                    |
| `voice_expense`       | premium                    | yes (same counter as `ai_scan`)            | «Voice expense entry is only available on Premium.» / «Scan limit reached for this billing period.» |
| `recurring_expenses`  | plus, premium              | no                                         | «Recurring expenses are not available on your plan.»                                                |
| `analytics_dashboard` | plus, premium              | no                                         | «Analytics dashboard is not available on your plan.»                                                |
| `csv_export`          | basic, plus, premium       | no                                         | «CSV export is not available on your plan.»                                                         |

**Analytics dashboard API:** `GET /api/analytics/dashboard?month=YYYY-MM` (details: `.cursor/backend-spec.md` §5.10). Marketing uses “dashboard” for Plus and “advanced” for Premium; **the current backend has one implementation and one response shape** for both. **Basic** has no access to this endpoint (`allowed_plans`: `plus` and `premium` only).

**Category visibility** (`GET /api/categories`, `PATCH /api/me/category-visibility`, tag funnel) is **not** tied to `feature_access.php`: it is available to every authenticated user on any plan.

Active subscription is resolved via `AppUser::activeSubscription()`: statuses `active`/`trialing`, plus `canceled` while `current_period_end` is still in the future (cancel-at-period-end), plus `past_due` for `AppUser::PAST_DUE_GRACE_DAYS` (3 days) past `current_period_end` (failed-payment grace period) — see `backend-spec.md` and `architecture.md` §Billing & Feature Gates.

---

## Stripe

- Stripe Price IDs are **not** hard-coded in `database/data/plans.php` for client display: `PlanController` fills `price_id` from `config('services.stripe.prices')` using the user’s currency (`app_user_settings.currency`, default `usd`) and plan type.
- Environment variables (see `config/services.php`):
    - `STRIPE_BASIC_PRICE_USD`, `STRIPE_BASIC_PRICE_EUR`
    - `STRIPE_PLUS_PRICE_USD`, `STRIPE_PLUS_PRICE_EUR`
    - `STRIPE_PREMIUM_PRICE_USD`, `STRIPE_PREMIUM_PRICE_EUR`
- For the free plan, API `price_id` is `null` (no Stripe Price).

Checkout: `POST /api/stripe/checkout` with body `{ "plan_type": "basic" | "plus" | "premium" }` — `free` is not accepted. Returns **409** if the user already has an active paid subscription (`userHasActivePaidSubscription()`); the frontend must route existing paying users to the portal instead (see below).

Paywall plan list: `GET /api/plans` — fields: `type`, `label`, `price` (cents), `currency`, `price_id`, `check_limit`, `description`.

**Plan changes (upgrade/downgrade):** there is no in-app plan-switch endpoint — an existing paying customer changes plans through the Stripe Billing Portal (`POST /api/stripe/portal`), never through `/api/stripe/checkout`. Frontend enforces this: `payment-plan.tsx` and `PaywallSheet.tsx` call `openPortal(plan.type)` instead of `startCheckout(plan.type)` whenever the user already has a paid plan. Proration/date behavior is controlled entirely by the Stripe Dashboard's default Billing Portal configuration, not by app code — as of writing: `billing_cycle_anchor: "unchanged"` (switching plans mid-cycle does **not** reset the renewal date) and `proration_behavior: "always_invoice"` (price difference is invoiced immediately). `schedule_at_period_end.conditions` is empty, so downgrades also apply immediately, not at period end — change this in Stripe Dashboard → Settings → Billing → Customer portal if a different downgrade policy is wanted.

---

## Database schema (short)

`plans` table (no timestamps):

- `type` — string/enum: free, basic, plus, premium
- `payment_price_id` — nullable Stripe Price ID (may duplicate/archive linkage; checkout primarily uses env config)
- `price` — int, cents
- `label`, `description`
- `check_limit` — max AI **operations** per billing period (**receipt scan** and **Premium voice expense** share the same `used_checks_count` counter)

Usage counter: `user_subscriptions.used_checks_count`; reset on new period is handled by Stripe webhooks (`invoice.paid`, etc. — see `backend-spec.md`).

---

## Related files

| Purpose                   | Path                                                                                                  |
| ------------------------- | ----------------------------------------------------------------------------------------------------- |
| Seed data                 | `database/data/plans.php`                                                                             |
| Seed command              | `app/Console/Commands/SeedPlansCommand.php` (`plans:seed`)                                            |
| Type enum                 | `app/Enums/PlanType.php`                                                                              |
| Model                     | `app/Models/Plan.php`                                                                                 |
| Plans API                 | `app/Http/Controllers/PlanController.php`                                                             |
| Feature access            | `config/feature_access.php`, `app/Services/FeatureAccessService.php`                                  |
| Stripe prices by currency | `config/services.php` → `stripe.prices`                                                               |
| Analytics dashboard (API) | `app/Http/Controllers/AnalyticsDashboardController.php`, `app/Services/AnalyticsDashboardService.php` |
| Full backend spec         | `.cursor/backend-spec.md` (subscriptions & billing sections)                                          |

## Documentation sync notes (2026)

- **Login:** `POST /api/auth/login` **does not** insert into `app_users`. The app row must exist from `POST /api/auth/register`. Otherwise **401** with a message like _«Please sign up before signing in»_.
- **Analytics:** besides `GET /api/transactions/summary` (all plans), the calendar dashboard is `GET /api/analytics/dashboard` for **Plus** and **Premium** only; one backend implementation for both (see `config/feature_access.php`).

When plans change, update `database/data/plans.php`, this matrix and related specs (`.cursor/backend-spec.md`) as needed, and keep Stripe Price IDs in Stripe / env in sync.
