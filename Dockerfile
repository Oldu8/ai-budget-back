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
# Stage 3: Production image
# ============================================
FROM php:8.2-cli-alpine

# Install PHP extensions for Laravel + PostgreSQL
RUN apk add --no-cache \
    libpq \
    libzip \
    icu-libs \
    oniguruma \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    mbstring \
    xml \
    intl \
    zip \
    bcmath \
    opcache

# Copy application from composer stage
COPY --from=composer /app /app

# Copy built assets from node stage
COPY --from=node /app/public/build /app/public/build

WORKDIR /app

# Railway uses PORT env (default 8080)
EXPOSE 8080

# Storage link, cache, and serve (needs .env at runtime from Railway)
CMD ["sh", "-c", "php artisan storage:link 2>/dev/null || true && php artisan config:cache && php artisan route:cache && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
