FROM php:8.4-apache-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip && docker-php-ext-install pdo_pgsql intl bcmath && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri "s!/var/www/html!/app/public!g" /etc/apache2/sites-available/000-default.conf && printf "<Directory /app/public>\nAllowOverride All\nRequire all granted\n</Directory>\n" > /etc/apache2/conf-available/organic.conf && a2enconf organic
WORKDIR /app

# Phone photos and one bounded foreground extraction per request.
RUN printf "upload_max_filesize=8M\npost_max_size=22M\nmax_file_uploads=7\nmemory_limit=256M\nmax_execution_time=120\n" > /usr/local/etc/php/conf.d/organic-invoices.ini

FROM base AS dev

FROM base AS dependencies
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY composer.json composer.lock ./
COPY bin/console bin/console
COPY config config
COPY src src
COPY migrations migrations
COPY public public
COPY templates templates
COPY docker docker
COPY --from=dependencies /app/vendor /app/vendor
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --no-scripts --classmap-authoritative && test -s vendor/autoload_runtime.php && mkdir -p var && chown www-data:www-data var && chmod +x docker/production-entrypoint.sh
RUN printf "<IfModule mpm_prefork_module>\nStartServers 1\nMinSpareServers 1\nMaxSpareServers 1\nServerLimit 2\nMaxRequestWorkers 2\nMaxConnectionsPerChild 500\n</IfModule>\n" > /etc/apache2/mods-available/mpm_prefork.conf
ENTRYPOINT ["/app/docker/production-entrypoint.sh"]
CMD ["apache2-foreground"]
