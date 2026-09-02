FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-dev libpq mysql-client bash git unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install pdo_pgsql pgsql pdo_mysql mysqli \
    # pcov custa ~5% quando não está coletando e é ordens de grandeza mais
    # rápido que o xdebug; fica instalado mas DESLIGADO, ligado sob demanda
    # por `php -d pcov.enabled=1` no script composer test:coverage.
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo "pcov.enabled=0" > /usr/local/etc/php/conf.d/pcov.ini \
    && apk del .build-deps

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

# A suíte unitária não abre socket nenhum; o container só precisa ficar de pé
# para receber `docker compose exec php composer test`.
CMD ["tail", "-f", "/dev/null"]
