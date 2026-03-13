# ============================================
# Stage 1: Build frontend assets (Vite)
# ============================================
FROM node:20-alpine AS node

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ============================================
# Stage 2: Install PHP dependencies
# ============================================
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ============================================
# Stage 3: Production image (Debian for reliable extension build)
# ============================================
FROM php:8.2-cli

# Install PHP extensions for Laravel + PostgreSQL
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    mbstring \
    xml \
    intl \
    zip \
    bcmath \
    opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy application from composer stage
COPY --from=composer /app /app

# Copy built assets from node stage
COPY --from=node /app/public/build /app/public/build

WORKDIR /app

# Railway uses PORT env (default 8080)
EXPOSE 8080

# Storage link, cache (non-fatal so server starts even if DB not ready), then serve
# Required Railway env vars: APP_KEY, DATABASE_URL (or DB_*), SUPABASE_*
CMD ["sh", "-c", "php artisan storage:link 2>/dev/null || true; php artisan config:cache 2>/dev/null || true; php artisan route:cache 2>/dev/null || true; exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
