FROM php:8.3-apache

RUN a2enmod headers

COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY public/ /var/www/html/
COPY api/ /var/www/html/api/

EXPOSE 80
