FROM unit:1.34.2-php8.4 AS base

ENV TZ=Europe/Prague

RUN apt-get update -y
RUN apt-get install git zip vim libpq-dev postgresql libicu-dev \
    libjpeg-dev libpng-dev libwebp-dev libavif-dev libfreetype6-dev -y

RUN docker-php-ext-configure gd \
    --with-jpeg \
    --with-webp \
    --with-avif \
    --with-freetype

RUN docker-php-ext-install pgsql pdo_pgsql pdo intl gd

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN mkdir -p /var/www/.composer

RUN chown -R www-data:www-data /var/www/.composer

WORKDIR /var/www/html

ADD --chown=www-data:www-data . /var/www/html

COPY ./.infrastructure/docker/php/unit.json /docker-entrypoint.d/config.json

#Prepare assets
FROM node:20 AS assets

WORKDIR /app

COPY --from=base /var/www/html /app

RUN cd front && npm install
RUN cd front && npm run build


#Runtime for staging
FROM base AS build-local

#COPY --from=assets /app/front/dist /var/www/html/front/dist
#COPY --from=assets /app/front/dist/style.css /var/www/html/www/css/styles.css

# Install Xdebug
RUN apt-get update -y
RUN apt-get install autoconf gcc g++ make nodejs npm -y

RUN pecl install xdebug
RUN docker-php-ext-enable xdebug

COPY ./.infrastructure/docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

RUN cd /var/www/html && curl -fsSL https://claude.ai/install.sh | bash
RUN echo 'export PATH="$HOME/.local/bin:$PATH"' >> $HOME/.bashrc


#Runtime for staging
FROM base AS build-staging

#COPY --from=assets /app/front/dist /var/www/html/front/dist
#COPY --from=assets /app/front/dist/style.css /var/www/html/www/css/styles.css

RUN composer install

RUN rm -f /home/www-data/.composer/auth.json


#Runtime for production
FROM base AS build-prod

#COPY --from=assets /app/front/dist /var/www/html/front/dist
#COPY --from=assets /app/front/dist/style.css /var/www/html/www/css/styles.css

RUN composer install --no-dev --optimize-autoloader

RUN rm -f /home/www-data/.composer/auth.json /var/www/html/.env
