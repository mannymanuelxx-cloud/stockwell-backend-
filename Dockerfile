FROM php:8.2-apache

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /var/www/html/

RUN a2enmod rewrite

# LavaLust connects to MySQL through PHP PDO.  The base Apache image does
# not include this driver, so install it for the Aiven MySQL connection.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    Options Indexes FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/lavalust.conf

RUN a2enconf lavalust

# Render's Docker secret files are readable by group 1000.
RUN if getent group 1000 >/dev/null; then \
        secret_group="$(getent group 1000 | cut -d: -f1)"; \
    else \
        groupadd --gid 1000 render-secrets; \
        secret_group=render-secrets; \
    fi \
    && usermod --append --groups "$secret_group" www-data

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
