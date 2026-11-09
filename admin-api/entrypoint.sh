#!/bin/bash
set -e
: "${ADMIN_USER:?Set ADMIN_USER}"; : "${ADMIN_PASS:?Set ADMIN_PASS}"
htpasswd -bc /etc/apache2/.htpasswd "$ADMIN_USER" "$ADMIN_PASS"
# Storage: /var/data is the Render persistent disk if attached; otherwise ephemeral
STORE=/var/data; mkdir -p $STORE
mkdir -p $STORE/data $STORE/img $STORE/backups
cp -n /opt/seed-data/* $STORE/data/ 2>/dev/null || true
cp -n /opt/seed-img/* $STORE/img/ 2>/dev/null || true
ln -sfn $STORE/data /var/www/html/data
ln -sfn $STORE/img /var/www/html/img
ln -sfn $STORE/backups /var/www/html/admin/backups
chown -R www-data:www-data $STORE
printf '<?php header("Location: /admin/"); ' > /var/www/html/index.php
# Render gives us a PORT
sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
