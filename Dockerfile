FROM php:8.4-fpm-alpine

RUN apk add --no-cache postgresql-dev libpq mysql-client

RUN docker-php-ext-install pdo_pgsql pgsql
RUN docker-php-ext-install pdo_mysql mysqli

RUN apk add --no-cache curl
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

EXPOSE 80

CMD ["php-fpm"]