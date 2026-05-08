# Тарифные планы (ai-budget-backend)

Единая справка: цены, лимиты, маркетинговое описание, что реально проверяется в коде и где это лежит в репозитории.

## Порядок и идентификаторы

Фиксированный порядок: **Free → Basic → Plus → Premium**.

| `type` (enum `PlanType`) | Лейбл (`label`) |
| ------------------------ | --------------- |
| `free`                   | Free            |
| `basic`                  | Basic           |
| `plus`                   | Plus            |
| `premium`                | Premium         |

Платные типы в коде: `PlanType::paidValues()` → `basic`, `plus`, `premium`.

---

## Цены и лимиты AI-сканов

Цены в БД хранятся в **центах** (`plans.price`). Публичные суммы в долларах — для сопоставления со спекой; фактические Stripe ID задаются через env (см. ниже).

| План    | Цена (реф.) | `price` (центы) | AI scans / период (`check_limit`) |
| ------- | ----------- | --------------- | --------------------------------- |
| Free    | $0          | 0               | 20                                |
| Basic   | $3          | 300             | 200                               |
| Plus    | $5          | 500             | 500                               |
| Premium | $10         | 1000            | 2000                              |

Источник данных для сидов: `database/data/plans.php`. Команда: `php artisan plans:seed` (идемпотентно через `updateOrCreate` по `type`).

---

## Маркетинговая матрица фич (product / UI)

Ориентир для экранов и таблиц (порядок колонок из спеки): AI scans (фото за сканирование см. ниже), Manual transactions, History, Categories, Recurring, Analytics, CSV, прочее.

| План    | Price | AI scans | Manual | History   | Categories | Recurring | Analytics | CSV | Другое                          |
| ------- | ----- | -------- | ------ | --------- | ---------- | --------- | --------- | --- | ------------------------------- |
| Free    | $0    | 20/mo    | unlim  | 1 month   | basic only | —         | —         | —   | 1 фото на скан                  |
| Basic   | $3    | 200/mo   | unlim  | unlimited | custom     | —         | —         | yes | 1 фото на скан                  |
| Plus    | $5    | 500/mo   | unlim  | unlimited | custom     | yes       | dashboard | yes | несколько фото на скан          |
| Premium | $10   | 2000/mo  | unlim  | unlimited | custom     | yes       | advanced  | yes | несколько фото; траты голосом   |

**Видимость категорий** (выбор групп для показа / скрытия) — базовая возможность для **всех** тарифов, в таблицу не включается; отдельного гейта по подписке нет.

**AI scan — фото за один сеанс:** Free и Basic — одно изображение; Plus и Premium — несколько изображений (например, многостраничный чек).

**Premium:** без «AI spending insights»; отличительная фича — **ввод расходов через голосовые сообщения**.

Часть отличий (история, набор категорий basic vs custom, расширенная аналитика, голос, мультфото) **не вынесена** в отдельные флаги в `config/feature_access.php` — это продуктовое описание и/или логика фронта; уточнять по доменным эндпоинтам и `backend-spec.md`.

### Тексты для UI из сидов (`plans.description`)

Многострочные строки в `database/data/plans.php` (для выдачи в `GET /api/plans`):

- **Free:** AI scans 20/month (1 photo per scan); Manual unlimited; History 1 month; Basic categories only; Category visibility controls.
- **Basic:** AI 200/mo (1 photo per scan); Manual unlimited; History unlimited; Custom categories; CSV export; Category visibility controls.
- **Plus:** AI 500/mo (multiple photos per scan); Manual unlimited; History unlimited; Custom categories; Recurring; Analytics dashboard; CSV export; Category visibility controls.
- **Premium:** AI 2000/mo (multiple photos per scan); Manual unlimited; History unlimited; Custom categories; Recurring; Advanced analytics; Add expenses via voice messages; CSV export; Category visibility controls.

---

## Что проверяется на бэкенде (feature access)

Конфиг: `config/feature_access.php`. Сервис: `App\Services\FeatureAccessService`.

| Фича (`feature`)     | Планы с доступом           | Квота                                     | Сообщение при отказе (ключ)                                                      |
| -------------------- | -------------------------- | ----------------------------------------- | -------------------------------------------------------------------------------- |
| `ai_scan`            | free, basic, plus, premium | да (`used_checks_count` vs `check_limit`) | «Subscription required to scan.» / «Scan limit reached for this billing period.» |
| `recurring_expenses` | plus, premium              | нет                                       | «Recurring expenses are not available on your plan.»                             |
| `analytics_dashboard` | plus, premium             | нет                                       | «Analytics dashboard is not available on your plan.»                          |
| `csv_export`         | basic, plus, premium       | нет                                       | «CSV export is not available on your plan.»                                      |

**Видимость категорий** (`GET /api/categories`, `PATCH /api/me/category-visibility`, воронка тегов) **не** завязана на `feature_access.php`: доступна всем аутентифицированным пользователям на любом тарифе.

Активная подписка берётся как у пользователя с планом и статусом, который попадает в `activeSubscription()` (обычно `active` и `trialing` — см. `backend-spec.md` и модель `AppUser`).

---

## Stripe

- Платёжные Price ID **не** захардкожены в `database/data/plans.php` для отображения клиенту: `PlanController` подставляет `price_id` из `config('services.stripe.prices')` по валюте пользователя (`app_user_settings.currency`, по умолчанию `usd`) и типу плана.
- Переменные окружения (см. `config/services.php`):
    - `STRIPE_BASIC_PRICE_USD`, `STRIPE_BASIC_PRICE_EUR`
    - `STRIPE_PLUS_PRICE_USD`, `STRIPE_PLUS_PRICE_EUR`
    - `STRIPE_PREMIUM_PRICE_USD`, `STRIPE_PREMIUM_PRICE_EUR`
- У бесплатного плана `price_id` в ответе API будет `null` (нет Stripe Price).

Checkout: `POST /api/stripe/checkout` с телом `{ "plan_type": "basic" | "plus" | "premium" }` — `free` не принимается.

Список планов для paywall: `GET /api/plans` — поля: `type`, `label`, `price` (центы), `currency`, `price_id`, `check_limit`, `description`.

---

## Схема БД (кратко)

Таблица `plans` (без timestamps):

- `type` — строка/enum: free, basic, plus, premium
- `payment_price_id` — nullable, Stripe Price ID (может дублировать/архивировать связь; основной источник для checkout — конфиг env)
- `price` — int, центы
- `label`, `description`
- `check_limit` — максимум AI-сканов за биллинговый период

Счётчик использования: `user_subscriptions.used_checks_count`; сброс при новом периоде обрабатывается вебхуками Stripe (`invoice.paid` и т.д. — детали в `backend-spec.md`).

---

## Связанные файлы

| Назначение             | Путь                                                                 |
| ---------------------- | -------------------------------------------------------------------- |
| Данные сидов           | `database/data/plans.php`                                            |
| Команда сидирования    | `app/Console/Commands/SeedPlansCommand.php` (`plans:seed`)           |
| Enum типов             | `app/Enums/PlanType.php`                                             |
| Модель                 | `app/Models/Plan.php`                                                |
| API списка планов      | `app/Http/Controllers/PlanController.php`                            |
| Доступ по фичам        | `config/feature_access.php`, `app/Services/FeatureAccessService.php` |
| Цены Stripe по валютам | `config/services.php` → `stripe.prices`                              |
| Подробная спека        | `.cursor/backend-spec.md` (раздел subscriptions & billing)           |

При изменении тарифов обновляйте `database/data/plans.php`, при необходимости — матрицу в этом файле и `.cursor/backend-spec.md`, и синхронизируйте Price ID в Stripe / env.
