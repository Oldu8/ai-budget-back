[← Configuration](configuration.md) · [Back to README](../README.md)

# Deployment

## Railway (Production)

Railway runs the app from the `Dockerfile` (multi-stage: Node/Vite → Composer → PHP CLI).

### Steps

1. **Connect repo** — New Project → Deploy from GitHub → select this repo.

2. **Set environment variables** in the Web service Variables tab:

   | Variable | Required |
   |----------|----------|
   | `APP_ENV=production` | ✅ |
   | `APP_DEBUG=false` | ✅ |
   | `APP_KEY` | ✅ |
   | `APP_URL` | ✅ Full URL, no trailing slash |
   | `DB_CONNECTION=pgsql` | ✅ |
   | `DB_URL` | ✅ Supabase connection string |
   | `JWT_SECRET` | ✅ Signs access tokens |
   | `GOOGLE_CLIENT_IDS`, `APPLE_CLIENT_IDS` | For social sign-in |
   | `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE` | ✅ Storage only |
   | `OPENAI_API_KEY` | ✅ |
   | `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | ✅ |
   | `ADMIN_PASSWORD` | ✅ |
   | `API_SECRET` | Optional |
   | `DISCORD_*` | Optional |

3. **Set Pre-deploy Command:**
   ```
   php artisan migrate --force && php artisan app:seed-initial
   ```

4. **Generate domain** in Networking tab.

5. **Add Cron service** for recurring expenses:
   - New Service → same repo
   - Start Command: `php artisan schedule:work`
   - Copy all Variables from the Web service

### Admin Styles Not Loading?

Set `APP_URL` to your full Railway domain (e.g. `https://ai-budget-back-production.up.railway.app`). The app trusts reverse proxies so HTTPS asset URLs resolve correctly.

### Stripe Webhooks

1. Stripe Dashboard → Developers → Webhooks → Add endpoint
2. URL: `https://your-domain/api/stripe/webhook`
3. Events to listen for:
   - `checkout.session.completed`
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
4. Copy the **Signing secret** to `STRIPE_WEBHOOK_SECRET`

## Docker (Local)

The `docker-compose.yml` is for local development only. Railway uses the `Dockerfile` directly.

```bash
cp .env.example .env.docker
# Edit .env.docker: APP_URL=http://localhost:8080, set DB credentials

docker compose up --build
```

Access at `http://localhost:8080`. Stop with `Ctrl+C` or `docker compose down`.

## Supabase Email Limits

The default Supabase SMTP allows ~3 emails/hour — for demo use only.

For production:
1. Supabase Dashboard → Project Settings → Auth → **SMTP Settings**
2. Enable custom SMTP: [Resend](https://resend.com) (3K/month free), SendGrid, or AWS SES
3. Configure rate limits under Auth → Rate Limits

## See Also

- [Configuration](configuration.md) — all environment variables
- [Getting Started](getting-started.md) — local development setup
- [Architecture](architecture.md) — system overview
