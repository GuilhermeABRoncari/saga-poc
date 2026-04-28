FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip sqlite sqlite-dev linux-headers \
 && docker-php-ext-install pcntl pdo_sqlite sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . .
RUN composer dump-autoload -o

ENV PHP_CLI_SERVER_WORKERS=1
