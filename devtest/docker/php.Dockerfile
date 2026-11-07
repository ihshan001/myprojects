# PHP 8.3 + Apache, with only the extensions this app actually needs.
# No Composer stage: the application is dependency-free by design.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers expires \
 && rm -rf /var/www/html/*

# Hardening: the document root is public/, never the repository root.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/sites-available/*.conf \
      /etc/apache2/apache2.conf \
      /etc/apache2/conf-available/*.conf \
 && printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' \
      > /etc/apache2/conf-available/hardening.conf \
 && a2enconf hardening

# Upload limits are irrelevant here (no file uploads) but the timeout is not:
# a report export on a slow connection can exceed the 30s default.
RUN printf 'expose_php = Off\ndisplay_errors = Off\nlog_errors = On\n' \
      > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p storage/db storage/logs storage/sessions \
 && chown -R www-data:www-data storage \
 && chmod -R 775 storage \
 && rm -rf node_modules tests .git

EXPOSE 80
