# Currency Conversion — Implementation Plan

Status: planning. Created 2026-06-22.

## Problem

Expenses can come in a currency different from the user's accounting currency
(receipt paid in USD, voice "купил 1 мая бензин на 100 грн", etc.). We must
convert using the **exchange rate on the transaction date**, not today's rate.
Paid FX-history services cost $15–30/mo — we avoid them by storing our own rates
from free official sources.

## Decisions (locked)

- **Monolith, not microservice.** A module inside the Laravel app:
  `app/Services/Currency/` + a console command on the Scheduler.
- **History starts 2026-01-01.** Transactions dated earlier are blocked.
  Single source of truth: `config('rates.earliest_date')`. Validate on the
  backend (not just a frontend notice).
- **AI extracts, backend converts.** The LLM never does math and never knows
  rates. It returns the original amount + original currency + date. The backend
  does the deterministic DB lookup and conversion.
- **Store both original and converted** values for audit/reproducibility.
- **Supported currencies (launch): 23.**
  - From ECB/Frankfurter (20 + EUR base): USD, PLN, GBP, CAD, CHF, TRY, CZK,
    HUF, RON, BGN, SEK, NOK, DKK, JPY, CNY, AUD, BRL, ILS, MXN, IDR
  - From NBU (2): UAH, MDL
  - (see `AppUserSettingsRequest::allowedCurrencies()`).
- **Storage model: cross pairs (chosen).** We store every directed pair
  `(from, to, date) → rate` so any conversion is a single-row lookup, no EUR
  pivot at read/convert time. Acceptable at this curated set size
  (23 currencies → 23×22 = 506 directed pairs/day). Pairs are still *computed*
  via EUR at sync time (sources are EUR-based for ECB, UAH-based for NBU), then
  the expanded matrix is persisted. Trade-off accepted knowingly: quadratic
  growth if the set expands a lot, and reciprocal pairs are stored from one
  consistent EUR computation per day to avoid drift.

## Data sources (free, no API key)

| Currency | Source | Notes |
|----------|--------|-------|
| EUR, USD, PLN, GBP, CAD, CHF, TRY, CZK, HUF, RON, BGN, SEK, NOK, DKK, JPY, CNY, AUD, BRL, ILS, MXN, IDR | **Frankfurter** (`api.frankfurter.dev`) — ECB reference rates | Base EUR, daily history, no weekends/holidays. ~30 currencies. Returns all symbols in one call. |
| UAH, MDL | **NBU** (National Bank of Ukraine open API) | Not in ECB. Free, historical by date, no key. NBU gives UAH per unit; convert to EUR via NBU's EUR rate. |

Both publish once per business day → fallback to nearest previous business day
when a date has no rate (see fallback logic below).

## Scope of THIS work (p3 + p4.1 + p4.2)

### 1. DB migration — add currency fields to `transactions`
- `original_amount` (int, minor units), `original_currency` (string ISO 4217)
- keep `amount` = converted amount in user's currency, add `currency`
- `exchange_rate` (decimal), `rate_date` (date), `rate_source` (string)
- backfill existing rows: original = amount, currency = user currency, rate = 1

### 2. Prompts — return original currency, stop pre-converting
- **VoicePromptBuilder**: remove the "do not return currency" rule (line ~23).
  New rule: detect currency from text; if not stated, default to user's
  `{currencyCode}`. Return `"currency": "<ISO>"`.
- **ScanPromptBuilder**: add currency detection from symbol/code on the
  receipt (₴, zł, $, EUR…); if unreadable, default to user's currency.
  Return `"currency"`.
- Update both JSON response schemas (ScanResponseSchemaBuilder + voice schema)
  to include the new `currency` field.

### 3. `exchange_rates` table + model (cross pairs)
- columns: `from_currency` (ISO), `to_currency` (ISO), `date`, `rate` (decimal),
  `source`, timestamps. Unique index `(from_currency, to_currency, date)`.
- `convert(amount, from, to, date)` = single-row lookup `from→to` on date.
- Sync builds the full matrix: fetch EUR-based rates (ECB) + UAH-based (NBU),
  normalize everything to EUR once, then expand to all directed pairs.

### 4. `ExchangeRateService`
- `convert(amount, fromCurrency, toCurrency, date): ConversionResult`
- Fallback ladder:
  1. rate for currency on exact date → use it
  2. supported currency, no rate on that date → nearest previous business day
  3. unsupported currency → **out of scope here** (see separate task 4.3 below)

### 5. Daily sync command + Scheduler
- `php artisan rates:sync` — pulls yesterday/today from Frankfurter (all 20 ECB
  currencies in one call) and NBU (UAH, MDL), normalizes to EUR, expands to all
  directed pairs, upserts into `exchange_rates`.
- Register on Scheduler (daily, after ECB publish ~16:00 CET).

### 6. Backfill history from 2026-01-01
- one-off command (or `rates:sync --from=2026-01-01`) using Frankfurter
  time-series + NBU per-date history.

### 7. Wire conversion into AI flow
- After the LLM returns its draft, call `ExchangeRateService->convert()` before
  persisting. Save original + converted + rate metadata.
- Backend validation: reject `date < rates.earliest_date` with a clear message.

### 8. Sync the 23-currency list (backend + frontend)
- Backend: extend `AppUserSettingsRequest::allowedCurrencies()` (currently only
  USD/EUR/UAH/PLN) to the full 23.
- Frontend: extend `shared/constants/currencies.ts` `CURRENCY_ITEMS` to all 23,
  each with `label` (display name), `leftIcon` (symbol), `badge`, `keywords`.
  Today only 4 exist → user must see "Japanese Yen ¥", not bare `JPY`.
- Keep both lists in sync (backend validates, frontend renders the picker).

### 9. Lock the user's base currency after it's set
- `currency` is the conversion target; changing it mid-use makes all historical
  conversions inconsistent. Allow it only in `createSettings` (first time).
- In `updateSettings` (`AppUserController`): if settings already exist with a
  currency, ignore/reject changes to `currency`; allow `language`/`timezone`.

## SEPARATE TASK — unsupported currency fallback (p4.3, do later)

Goal: user scans a receipt in a currency we don't track (e.g. IDR). Don't error
out — they can't change the receipt.

- 3rd rung of the fallback ladder: if currency is not in our supported set,
  do **one live request for the CURRENT rate** to a wide-coverage free API
  (e.g. `open.er-api.com`, ~160 currencies, no key).
- Convert by current rate and set flag `rate_is_approximate = true`.
- Show a small UI hint to the user ("approximate rate").
- If even the fallback API lacks the currency → soft error
  "currency not supported".

## Build order (so nothing blocks)

1. migration (transactions fields) + `exchange_rates` table
2. `ExchangeRateService` (rungs 1–2) + config `rates.earliest_date`
3. `rates:sync` command + backfill from 2026-01-01
4. Scheduler registration
5. prompts + JSON schemas (return `currency`)
6. wire conversion into AI draft flow + backend date validation
7. sync 23-currency list (backend `allowedCurrencies` + frontend `CURRENCY_ITEMS`)
8. lock base currency after first set (`updateSettings`)
9. (separate) unsupported-currency live fallback (p4.3)
10. (separate, needs clarification) transaction-added notification. Findings:
    in-app **Toast** system already exists and is used → a confirmation toast is
    trivial. **Push** does NOT exist: no `expo-notifications`, no device tokens;
    `app/(profile)/notifications.tsx` is only a "Coming soon" placeholder. Real
    push = its own project (Expo Push + token storage + backend send). Out of
    scope unless confirmed.
