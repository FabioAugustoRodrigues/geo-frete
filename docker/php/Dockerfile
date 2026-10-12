FROM php:8.2-apache

# curl and mbstring ship with the official image. pdo_mysql is the database
# driver; zip extracts CNEFE downloads (bin/import-cnefe.php).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql zip \
    && a2enmod rewrite

# The project root is the document root (see .htaccess), mounted as a volume
# by docker-compose.yml during development.
WORKDIR /var/www/html
