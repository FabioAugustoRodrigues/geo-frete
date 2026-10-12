FROM php:8.2-apache

# curl and mbstring ship with the official image; only the MySQL driver is missing.
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

# The project root is the document root (see .htaccess), mounted as a volume
# by docker-compose.yml during development.
WORKDIR /var/www/html
