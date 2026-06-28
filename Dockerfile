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
# Stage 2: Install PHP dependencies (production)
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
# Stage 2-dev: Install PHP dependencies (including dev)
# ============================================
FROM composer:2 AS composer-dev

WORKDIR /app

COPY composer.json composer.lock* ./

RUN composer install \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize

# ============================================
# Stage 3: Production image (Debian for reliable extension build)
# ============================================
FROM php:8.4-cli AS production

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

# Raise upload limits (defaults are 2M/8M — too small for cover images up to 4MB+)
RUN { \
      echo 'upload_max_filesize = 8M'; \
      echo 'post_max_size = 10M'; \
      echo 'memory_limit = 256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Copy application from composer stage
COPY --from=composer /app /app

# Copy built assets from node stage
COPY --from=node /app/public/build /app/public/build

WORKDIR /app

# Railway uses PORT env (default 8080)
EXPOSE 8080

# /health.php served directly by PHP (no Laravel) — for Railway healthcheck
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]

# ============================================
# Stage 4: Development image (uses dev composer deps + composer binary)
# ============================================
FROM production AS development
COPY --from=composer-dev /app/vendor /app/vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PCOV for code coverage (dev only)
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get purge -y $PHPIZE_DEPS \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*
