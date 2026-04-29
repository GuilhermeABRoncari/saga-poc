FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip sqlite-dev linux-headers \
 && docker-php-ext-install sockets pcntl pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . .
RUN composer dump-autoload -o
