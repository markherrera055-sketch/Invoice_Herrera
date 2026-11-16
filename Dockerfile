# ---------------------------------------------------------------------------
# React admin UI (prebuilt)
#
# The React admin app is developed in a separate repository (invoiceninja/ui).
# Upstream only ships its compiled files inside the official release tarball
# (see .github/workflows/react_release.yml): dist/* is copied into public/ and
# dist/index.html becomes resources/views/react/index.blade.php. A plain source
# checkout does not contain them, which renders an empty <div id="root">.
#
# IN_VERSION must match config('ninja.app_version') / VERSION.txt. When upgrading,
# update both build args (sha256sum of the new invoiceninja.tar.gz).
# ---------------------------------------------------------------------------
FROM debian:bookworm-slim AS react-ui

ARG IN_VERSION=5.13.49
ARG IN_RELEASE_SHA256=19d4c2315570ee396039ebce1a69f50fca7bbae7965cc2bf9e18f8ef01d44154

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL -o /tmp/invoiceninja.tar.gz \
        "https://github.com/invoiceninja/invoiceninja/releases/download/v${IN_VERSION}/invoiceninja.tar.gz" \
    && echo "${IN_RELEASE_SHA256}  /tmp/invoiceninja.tar.gz" | sha256sum -c - \
    && mkdir -p /ui \
    && tar -xzf /tmp/invoiceninja.tar.gz -C /ui public resources/views/react/index.blade.php \
    && rm /tmp/invoiceninja.tar.gz \
    && test -f /ui/resources/views/react/index.blade.php \
    && grep -q 'type="module"' /ui/resources/views/react/index.blade.php

FROM php:8.3-apache-bookworm

# PHP extension installer (handles system libraries for each extension)
COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip mariadb-client \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions bcmath curl dom exif gd gmp intl mysqli opcache pcntl pdo_mysql soap xsl zip

# Apache: serve from /public and allow .htaccess rewrites
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && a2enmod rewrite headers

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-invoiceninja.ini

WORKDIR /var/www/html
COPY . .

# Add the React admin build: only files missing from public/ are added (project
# files such as manifest.json, robots.txt, build/, css/ are never overwritten),
# and the React entry view is the compiled index.html, as in the official release.
COPY --from=react-ui /ui /tmp/react-ui
RUN tar -C /tmp/react-ui/public -cf - . | tar -C public -xf - --skip-old-files \
    && cp /tmp/react-ui/resources/views/react/index.blade.php resources/views/react/index.blade.php \
    && rm -rf /tmp/react-ui

# Use the bundled vendor/ folder if present, otherwise install dependencies
RUN if [ ! -f vendor/autoload.php ]; then \
        composer install --no-dev --no-interaction --optimize-autoloader --no-scripts; \
    fi \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
