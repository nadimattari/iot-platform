# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.4-alpine AS builder
RUN install-php-extensions pdo_pgsql
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
ENV APP_ENV=prod
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader \
    && composer dump-autoload --optimize --no-scripts
COPY . .
RUN printf 'APP_ENV=prod\n' > .env \
    && mkdir -p var/cache var/log && chown -R www-data:www-data var

FROM dunglas/frankenphp:1-php8.4-alpine
RUN install-php-extensions pdo_pgsql
WORKDIR /app
COPY --from=builder /app ./
COPY Caddyfile /etc/frankenphp/Caddyfile
ENV APP_ENV=prod
EXPOSE 80
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
