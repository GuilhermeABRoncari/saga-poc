FROM ghcr.io/roadrunner-server/roadrunner:2024.3 AS rr

FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip linux-headers zlib-dev $PHPIZE_DEPS \
 && pecl install grpc \
 && docker-php-ext-enable grpc \
 && docker-php-ext-install sockets pcntl \
 && apk del $PHPIZE_DEPS

# Runtime libs que o apk del removeu junto com o $PHPIZE_DEPS (grpc.so depende delas).
RUN apk add --no-cache libstdc++ zlib

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=rr /usr/bin/rr /usr/bin/rr

WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY . .
RUN composer dump-autoload -o
