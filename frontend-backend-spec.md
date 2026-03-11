## AI Budget — Backend overview for frontend

Этот файл — краткая, но полная спецификация бекенда AI Budget с фокусом на том, что важно для фронтенда (мобильного приложения).

Основа: Laravel 12, PostgreSQL (Supabase), Supabase Auth, Stripe, OpenAI. Подробный низкоуровневый документ — `.cursor/backend-spec.md`.

---

## 1. Общая концепция приложения

AI Budget — персональный трекер бюджета:

- Пользователь регистрируется/логинится через email + пароль (Supabase Auth под капотом).
- Может:
  - создавать **ручные транзакции** (доходы/расходы),
  - сканировать чеки через **AI scan** (фото → распознавание позиций по категориям),
  - включать/выключать категории расходов (через visibility tags),
  - смотреть сводку трат по категориям/подкатегориям за период,
  - выгружать траты в **CSV** (на платных планах).
- Подписки и лимиты AI-сканов управляются через Stripe.

---

## 2. Ключевые сущности (уровень фронта)

### 2.1. AppUser и настройки

- Таблица `app_users`:
  - `id` — внутренний id пользователя.
  - `supabase_user_id` — id пользователя в Supabase Auth.
  - `email`, `first_name`, `last_name`.
  - `email_verified_at` — подтверждён ли email.
- Таблица `app_user_settings` (1:1 с `app_users`):
  - `currency` — код валюты (например, `USD`, `EUR`, `RUB`).
  - `language` — язык (`en`, `ru`, …).
  - `timezone` — IANA timezone (`Europe/Berlin` и т.п.), влияет на даты транзакций.

Фронтенд:

- После регистрации/логина нужно создать/обновить настройки через `/api/me/settings`.
- Валюта из настроек используется в UI и в CSV-экспорте.

### 2.2. Категории

- Таблица `categories`:
  - Level 1 (родительские категории) — агрегирующие, **не выбираются** при создании транзакции.
  - Level 2 (подкатегории) — реальные категории, которые выбирает пользователь.
  - Поля:
    - `id`
    - `category_id` — ссылка на родительскую категорию (null для Level 1).
    - `name` — slug (используется как `slug` в API).
    - `label` — отображаемое название.
    - `visibility_tag` — метка для массового скрытия/отображения (kids, car, pets и т.д.).

Фронтенд:

- Загружает дерево категорий через `GET /api/categories`.
- Использует `id`/`slug`/`label` для подписи графиков, выпадающих списков, и пр.

### 2.3. Видимость категорий

- Таблица `app_user_category_settings`:
  - `app_user_id`, `category_id`, `visibility_tag`.
  - Строка означает, что **эта категория выключена** (скрыта) для пользователя.
- Выключение по `visibility_tag`:
  - disabled = добавляется одна строка на каждую категорию с этим тегом;
  - enabled = все строки с этим тегом удаляются.
- Все эндпоинты, которые отдают категории/сводки/AI-результаты, учитывают эту таблицу и исключают отключённые категории.

Фронтенд:

- Настройки видимости через:
  - `GET /api/funnel/visibility-questions`
  - `GET /api/visibility-tags`
  - `PATCH /api/me/category-visibility` с `{ visibility_tag, enabled }`.
- В UI достаточно показывать только включённые категории (бекенд уже фильтрует).

### 2.4. Транзакции и позиции

- Таблица `transactions`:
  - `id`
  - `app_user_id`
  - `total_amount` — сумма чека в минимальных единицах (копейки/центы).
  - `date` — дата операции (учитывает timezone пользователя при создании).
  - `type` — enum `income` или `expense`.
  - `created_by` — enum `user` (ручная) или `ai` (AI-скан).
  - `raw_ai_data` — JSON-ответ от OpenAI (для AI-чеков).
  - `confirmed_at` — когда транзакция подтверждена (ключевое поле).
  - `img_path` — путь к изображению чека в Supabase Storage.
  - soft deletes (`deleted_at`).

- Таблица `transaction_items`:
  - `transaction_id` → `transactions.id`.
  - `category_id` → всегда подкатегория (Level 2).
  - `amount` — сумма позиции в минимальных единицах.
  - `label` — название позиции (например, «Хлеб», «Бензин»).

Правила:

- Ручная транзакция:
  - 1 `transaction` + 1 `transaction_item` (целиком в одной категории).
  - `created_by = user`, `confirmed_at = now()`.
- AI-чек:
  - 1 `transaction` + N `transaction_items` по категориям.
  - `created_by = ai`, `confirmed_at = null` сначала.
  - Попадает в списки/графики **только после** `/transactions/{id}/confirm`.

Фронтенд:

- Везде, где нужны «финальные» данные (история, сводки), бекенд автоматически фильтрует по `confirmed_at IS NOT NULL` и `deleted_at IS NULL`.

### 2.5. Подписки и лимиты сканов

- Таблица `plans`:
  - `type` — enum: `free`, `basic`, `plus`, `premium`.
  - `price` (в центах).
  - `check_limit` — лимит AI-сканов в биллинг-период.
  - `label`, `description` — тексты для UI.
- Таблица `user_subscriptions`:
  - `app_user_id`
  - `payment_subscription_id` (Stripe)
  - `status_id` → статус (`active`, `trialing`, `canceled`, …)
  - `used_checks_count` — сколько сканов уже сделано в текущем периоде.
  - `current_period_end` — конец биллинг-периода.

Фронтенд:

- Текущее состояние подписки и лимиты приходят в `/api/me` в полях:
  - `subscription` — активная подписка + вложенный `plan`.
  - `scan_usage` — `{ scans_remaining, scans_limit, period_end }`.
- Для AI-скана фронт должен показывать текущий остаток и обрабатывать 403-ошибки:
  - «Subscription required to scan.»
  - «Scan limit reached for this billing period.»

---

## 3. Аутентификация и токены

### 3.1. Потоки

Auth проксируется через бекенд, клиент **не** ходит напрямую в Supabase:

- Регистрация: `POST /api/auth/register` → 201 `{ access_token, refresh_token, user }`.
- Логин: `POST /api/auth/login` → 200 `{ access_token, refresh_token, user + settings }`.
- Обновление токена: `POST /api/auth/refresh` → новые токены.
- Логаут: `POST /api/auth/logout` с `Authorization: Bearer <token>`.

Дальше все защищённые эндпоинты требуют заголовок:

```http
Authorization: Bearer <access_token>
```

### 3.2. Поведение на клиенте

- Хранить `access_token` и `refresh_token` в защищённом хранилище (SecureStorage/Keychain).
- При 401 на защищённом запросе:
  1. вызвать `/api/auth/refresh` c `refresh_token`;
  2. если успешно — повторить исходный запрос;
  3. если 401 на refresh — очистить токены и отправить пользователя на логин.

---

## 4. Основные фичи и связанные эндпоинты

Ниже — более «фичевое» представление, чем в `api-routes.json`.

### 4.1. Профиль и настройки

- `GET /api/me` — профиль + активная подписка + `scan_usage`.
- `POST /api/me/settings` — создать настройки при онбординге (одноразово).
- `PUT /api/me/settings` — обновить язык/валюту/таймзону.

Фронтенд:

- После регистрации:
  - вызвать `POST /api/me/settings`;
  - сохранить настройки в стейт.
- При открытии приложения:
  - вызывать `GET /api/me` для получения профиля, подписки, лимитов и настроек.

### 4.2. Категории и visibility-фаннел

- `GET /api/categories` — дерево категорий (с учётом выключенных).
- `GET /api/funnel/visibility-questions` — список вопросов по `visibility_tag`.
- `GET /api/visibility-tags` — список тегов с флагом `enabled` для юзера.
- `PATCH /api/me/category-visibility` — включение/выключение группы категорий по тегу.

Фронтенд:

- Онбординг:
  - по `visibility-questions` и `visibility-tags` строится UI выбора «жизненных контекстов» (машина, дети и т.п.).
  - отправка ответов → `PATCH /api/me/category-visibility`.
- В обычном UI:
  - работа только с существующими категориями; скрытые уже не приходят в /categories и summary.

### 4.3. Ручные транзакции

- `GET /api/transactions` — список подтверждённых транзакций пользователя:
  - фильтры: `from`, `to`, `type`, `per_page`.
- `POST /api/transactions` — создать ручную транзакцию.
- `GET /api/transactions/{id}` — получить одну транзакцию.
- `PUT /api/transactions/{id}` — обновить транзакцию.
- `DELETE /api/transactions/{id}` — soft-delete транзакции.

Фронтенд:

- Создание:
  - тело: `{ total_amount (int, в копейках), date, type, category_id }`.
  - в ответе уже приходят `items` с привязанной категорией.
- Отображение:
  - использовать `type` и `items.amount`/`total_amount` в выбранной валюте (делить на 100).

### 4.4. AI scan (распознавание чеков)

- `POST /api/transactions/scan`:
  - multipart-запрос с `image` (jpeg/png).
  - требует активную подписку и свободный `check_limit`.
  - возвращает черновик транзакции (с позициями по категориям).
- `POST /api/transactions/{transaction}/confirm`:
  - подтверждает AI-транзакцию.
  - после этого `confirmed_at` заполняется, и транзакция попадает в историю и summary.

Фронтенд:

- Перед сканом:
  - можно проверить остаток по `scan_usage` из `/api/me`.
- После скана:
  - показывать пользователю распознанные позиции, давать менять категории/суммы.
  - при подтверждении дергать `.../confirm`.

### 4.5. Сводки по тратам (analytics)

Сводки по суммам трат за период:

- `GET /api/transactions/summary?from&to` — по категориям (Level 1).
  - `days`: словарь `YYYY-MM-DD` → `{ category_id: total_amount_in_cents }`.
  - `meta.totals_by_category` и `meta.grand_total`.
- `GET /api/transactions/summary/{category}?from&to` — по подкатегориям одной категории.
  - тот же формат, но ключи — id подкатегорий, плюс `category` в корне ответа.

Фронтенд:

- Для графиков/дешборда:
  - использовать `days` вместе со списком категорий (`/api/categories`) для подписи осей и легенд.
  - суммы переводить в валюту: `amount/100`.

### 4.6. CSV export of expenses (Dynamic CSV export)

Новая фича для выгрузки трат в CSV.

- **Endpoint:** `GET /api/transactions/export?from=YYYY-MM-DD&to=YYYY-MM-DD`.
- **Auth:** `Authorization: Bearer <access_token>`.
- **Доступ по плану:**
  - Только для активной подписки с планом `basic`, `plus` или `premium`.
  - Если подписки нет или план `free` → 403 с сообщением:
    - `CSV export is not available on your plan.`
- **Период:**
  - `from` и `to` обязательны; `to >= from`.
  - Лимит «не больше года (январь–декабрь)» реализуется на фронте — бекенд не проверяет максимальный диапазон.
- **Данные:**
  - только подтверждённые расходы (`type = expense`, `confirmed_at IS NOT NULL`);
  - только категории, не отключённые пользователем;
  - одна строка CSV = один `transaction_item` (позиция чека).
- **Формат файла:**
  - Ответ: `text/csv` + заголовок `Content-Disposition: attachment; filename="expenses_FROM_TO.csv"`.
  - Кодировка: UTF-8 с BOM (корректное открытие в Excel).
  - Разделитель: запятая, экранирование делает `fputcsv`.
  - Столбцы:
    - `Date` — `YYYY-MM-DD` (дата транзакции).
    - `Category` — `categories.label` подкатегории (Level 2).
    - `Amount` — сумма в единицах валюты (`amount / 100`, 2 знака после запятой).
    - `Currency` — из `app_user_settings.currency` (одна и та же в файле).
    - `Label` — `transaction_items.label` или пустая строка.

Фронтенд:

- Формировать `from`/`to` на основе выбранных месяцев (например, 2026-01-01…2026-02-29).
- Делать обычный `GET` с заголовком `Accept: text/csv` и скачивать/открывать файл средствами платформы (Share / Open in…).

---

## 5. Сводка по файлам и где искать детали

Если нужно углубиться в детали бекенда:

- Общая спецификация и бизнес-правила — `.cursor/backend-spec.md`.
- API-роуты в машиночитаемом виде — `api-routes.json` (можно импортировать на фронт как справочник).
- Ключевые контроллеры:
  - `App/Http/Controllers/AuthController.php` — register/login/refresh/logout.
  - `App/Http/Controllers/AppUserController.php` — `/me` + `/me/settings` + subscription/scan_usage.
  - `App/Http/Controllers/CategoryController.php` — `/categories`.
  - `App/Http/Controllers/CategoryVisibilityController.php` — visibility-теги и онбординг.
  - `App/Http/Controllers/ManualTransactionController.php` — CRUD транзакций.
  - `App/Http/Controllers/ScanController.php` — AI scan + confirm.
  - `App/Http/Controllers/TransactionSummaryController.php` — summary по тратам.
  - `App/Http/Controllers/ExpenseExportController.php` — CSV export.
- Модели:
  - `App/Models/AppUser.php`, `App/Models/AppUserSettings.php`
  - `App/Models/Category.php`, `App/Models/Transaction.php`, `App/Models/TransactionItem.php`
  - `App/Models/Plan.php`, `App/Models/UserSubscription.php`

Этого файла + `api-routes.json` должно быть достаточно, чтобы на фронте понимать, какие есть фичи, как устроены данные и какие эндпоинты доступны.

