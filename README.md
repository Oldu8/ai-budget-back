# ai-budget-back

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## Seeders (new app setup)

After migrations, run all initial seeders in one command:

```bash
php artisan app:seed-initial
```

This runs categories, plans, and admin user seeders. Or run individually:

1. **Categories** — `php artisan categories:seed` (from `database/data/categories.php`)
2. **Plans** — `php artisan plans:seed` (from `database/data/plans.php`)
3. **Admin user** — `php artisan db:seed --class=AdminUserSeeder` (creates/updates admin for panel)

Optional: `php artisan db:seed` runs `DatabaseSeeder` (PlanSeeder + test User); use if you need the default test user.

## Admin panel

Web admin at `/admin` (Blade + Tailwind). Single admin; login uses table `users`.

- Set `ADMIN_PASSWORD` in `.env`.
- Create/update admin user: `php artisan db:seed --class=AdminUserSeeder` (email: oldu2002@gmail.com).
- Open `/admin` and sign in with that email and `ADMIN_PASSWORD`.

**UI:** Left sidebar (dark) with branding **Anton**, search field, navigation (Dashboard, Users, Subscriptions) with icons, and Logout at the bottom. Main content area is light; layout uses `resources/views/admin/layout.blade.php`.

**Pages:**

- **Dashboard** (`/admin`) — Two metric cards: total app users count and active subscriptions count (statuses `active`, `trialing`).
- **Users** (`/admin/users`) — Paginated table of app users: ID, email, first name, last name, email verified, created. Link “View” opens the user detail.
- **User detail** (`/admin/users/{id}`) — View-only: profile (email, first/last name, email verified, created), settings (currency, language, timezone) if present, and list of subscriptions (plan, status, period end, scans used, cancel at period end). Back link to Users list.
- **Subscriptions** (`/admin/subscriptions`) — Paginated table of all user subscriptions: ID, user (link to user detail), plan, status, scans used, period end, cancel at period end. Filter by plan (dropdown); “Filter” applies, “All” shows every plan.

## Docker (local development)

Run the app locally with Docker:

1. **Prerequisites:** Docker Desktop (Windows/Mac) or Docker + Docker Compose (Linux).

2. **Create `.env.docker`** — copy from `.env.example` and adjust:
   - `APP_URL=http://localhost:8080`
   - `DB_HOST=host.docker.internal` (if PostgreSQL runs on your host)
   - Or use your cloud DB credentials as-is.

3. **Build and run:**
   ```bash
   docker compose up --build
   ```

4. **Access:** http://localhost:8080

5. **Stop:** `Ctrl+C` or `docker compose down`

**Note:** `docker-compose.yml` is for local use only. Railway deploys from the Dockerfile directly and ignores docker-compose.

## Deployment (Railway)

1. Connect the repo to [Railway](https://railway.app) (New Project → Deploy from GitHub).
2. Add variables in the Web service (Variables tab): `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL`, `DB_CONNECTION=pgsql`, `DB_URL` (from Supabase), `SUPABASE_*`, `OPENAI_API_KEY`, `ADMIN_PASSWORD`. Optional: `API_SECRET` — if set, all `/api/*` requests must include header `X-API-Key: <API_SECRET>` (restricts access to your app only).

   **Admin styles not loading?** Set `APP_URL` to your full Railway URL (e.g. `https://ai-budget-back-production.up.railway.app`) with no trailing slash. The app trusts proxies so asset URLs use the correct scheme.
3. Set Pre-deploy Command: `php artisan migrate --force && php artisan app:seed-initial`
4. Generate domain in Networking.
5. Add Cron service: same repo, Start Command `php artisan schedule:work`, copy Variables from Web.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
