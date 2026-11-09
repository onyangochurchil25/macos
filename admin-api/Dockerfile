FROM php:8.2-apache
RUN a2enmod headers && apt-get update && apt-get install -y --no-install-recommends apache2-utils && rm -rf /var/lib/apt/lists/*
COPY . /var/www/html/
COPY apache.conf /etc/apache2/conf-available/shop.conf
RUN a2enconf shop && mv /var/www/html/data /opt/seed-data && mv /var/www/html/img /opt/seed-img \
 && rm -rf /var/www/html/admin/backups && chmod +x /var/www/html/entrypoint.sh
ENTRYPOINT ["/var/www/html/entrypoint.sh"]
