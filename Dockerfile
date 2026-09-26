# Payroll & Benefits PHP API — Render Docker image.
# php -S serves backend/public exactly like local dev; PATH_INFO routing works.
FROM php:8.3-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY backend/ ./

ENV PORT=8080
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public"]
