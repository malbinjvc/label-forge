FROM php:8.4-cli AS builder

RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./

RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

FROM php:8.4-cli

RUN groupadd -r appuser && useradd -r -g appuser -d /app -s /sbin/nologin appuser

WORKDIR /app

COPY --from=builder /app /app

RUN chown -R appuser:appuser /app

USER appuser

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD php -r "echo file_get_contents('http://localhost:8080/health');" || exit 1

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/"]
