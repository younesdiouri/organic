FROM php:8.4-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip && docker-php-ext-install pdo_pgsql intl && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri "s!/var/www/html!/app/public!g" /etc/apache2/sites-available/000-default.conf && printf "<Directory /app/public>\nAllowOverride All\nRequire all granted\n</Directory>\n" > /etc/apache2/conf-available/organic.conf && a2enconf organic
WORKDIR /app

# Phone photos and one bounded foreground extraction per request.
RUN printf "upload_max_filesize=8M\npost_max_size=22M\nmax_file_uploads=7\nmemory_limit=256M\nmax_execution_time=120\n" > /usr/local/etc/php/conf.d/organic-invoices.ini
