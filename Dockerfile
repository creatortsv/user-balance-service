# TODO: прод-образа нет, нужен stage с кодом внутри без xdebug и dev-зависимостей и с прогретым кэшем
FROM php:8.4-cli
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions bcmath opcache pcntl pdo_pgsql rdkafka zip xdebug
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/10-app.ini
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/var/cache/composer
WORKDIR /app
