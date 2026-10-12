FROM php:8.3-apache

RUN a2enmod headers

# GD with AVIF, WebP and PNG for the map road extractor (Stage 9,
# api/tools/extract_roads.php); the running site does not need it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libavif-dev libwebp-dev libpng-dev libjpeg62-turbo-dev \
    && docker-php-ext-configure gd --with-avif --with-webp --with-jpeg \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*

COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY public/ /var/www/html/
COPY api/ /var/www/html/api/

# Race state is written here at runtime; seed ownership so a fresh named
# volume initializes www-data-owned on every deployment.
RUN mkdir -p /var/www/html/data && chown www-data:www-data /var/www/html/data

EXPOSE 80
