#!/bin/bash
set -e
: "${ADMIN_USER:?Set ADMIN_USER in Render Environment}"
: "${ADMIN_PASS:?Set ADMIN_PASS in Render Environment}"
if [[ "$ADMIN_USER" == *:* ]]; then echo "ADMIN_USER must not contain a colon"; exit 1; fi
htpasswd -bc /etc/apache2/.htpasswd "$ADMIN_USER" "$ADMIN_PASS"

# Storage: /var/data is the Render persistent disk if attached; otherwise ephemeral
STORE=/var/data
mkdir -p "$STORE/data" "$STORE/img" "$STORE/backups"
cp -n /opt/seed-data/* "$STORE/data/" 2>/dev/null || true
cp -n /opt/seed-img/* "$STORE/img/" 2>/dev/null || true

# Remove anything in the way, then link the web folders to the storage
rm -rf /var/www/html/data /var/www/html/img /var/www/html/admin/backups
ln -s "$STORE/data" /var/www/html/data
ln -s "$STORE/img" /var/www/html/img
ln -s "$STORE/backups" /var/www/html/admin/backups

# Make everything writable for the web server
chown -R www-data:www-data "$STORE"
chmod -R u+rwX,g+rwX "$STORE"

# Prove it: try writing as the web server user
for d in data img backups; do
  if su -s /bin/bash www-data -c "touch '$STORE/$d/.w' && rm '$STORE/$d/.w'"; then
    echo "OK writable: $d"
  else
    echo "NOT writable: $d"
  fi
done
ls -ld "$STORE" "$STORE/data" "$STORE/img" "$STORE/backups"

printf '<?php header("Location: /admin/"); ' > /var/www/html/index.php

# Render gives us a PORT
sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground