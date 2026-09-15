FROM mcr.microsoft.com/devcontainers/php:1-8.4-bookworm
RUN docker-php-ext-install mysqli pdo_mysql
RUN rm -f /etc/apt/sources.list.d/yarn.list && apt-get update && apt-get install -y ca-certificates && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000}"]
