FROM php:8.3-apache

RUN a2enmod headers

COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY public/ /var/www/html/
COPY api/ /var/www/html/api/

# Race state is written here at runtime; seed ownership so a fresh named
# volume initializes www-data-owned on every deployment.
RUN mkdir -p /var/www/html/data && chown www-data:www-data /var/www/html/data

EXPOSE 80
