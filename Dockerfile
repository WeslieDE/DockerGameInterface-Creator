# SGI-Creator — companion tool for SGI (Simple Game Interface).
# Single minimal container: PHP 8.3 + Apache.
FROM php:8.3-apache

# Enable URL rewriting (.htaccess: /api/* -> public/api/index.php).
RUN a2enmod rewrite

# ext-curl (Docker socket) is bundled in the official PHP image. ext-yaml, used
# to parse the compose templates, is not — build it from PECL against libyaml.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libyaml-dev \
    && pecl install yaml \
    && docker-php-ext-enable yaml \
    && rm -rf /var/lib/apt/lists/*

# Serve from public/ only; src/ and templates/ stay outside the document root.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides in the document root.
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/sgi-creator.conf \
    && a2enconf sgi-creator

# Application code.
COPY public/    /var/www/html/public/
COPY src/       /var/www/html/src/
COPY templates/ /var/www/html/templates/
COPY composer.json /var/www/html/composer.json

# Entrypoint grants www-data access to the mounted Docker socket at runtime.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
