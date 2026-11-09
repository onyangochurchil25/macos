FROM php:8.2-apache

# pdo_pgsql = PHP's driver for Postgres (Supabase)
RUN apt-get update \
 && apt-get install -y --no-install-recommends apache2-utils libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && a2enmod headers \
 && rm -rf /var/lib/apt/lists/*

# Public web files. Nothing else is copied into the web root.
COPY admin/ /var/www/html/admin/
COPY api/   /var/www/html/api/
# Private (not reachable from the web): database code and the first-start seed data
COPY app/   /opt/app/
COPY data/  /opt/seed-data/
COPY img/   /opt/seed-img/
COPY apache.conf /etc/apache2/conf-available/shop.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && a2enconf shop \
 && sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
 && chmod +x /usr/local/bin/entrypoint.sh \
 && printf '<?php header("Location: /admin/"); exit;\n' > /var/www/html/index.php

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
