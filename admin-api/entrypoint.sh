#!/bin/bash
set -e
: "${ADMIN_USER:?Set ADMIN_USER in Render Environment}"
: "${ADMIN_PASS:?Set ADMIN_PASS in Render Environment}"
if [[ "$ADMIN_USER" == *:* ]]; then echo "ADMIN_USER must not contain a colon"; exit 1; fi
htpasswd -bc /etc/apache2/.htpasswd "$ADMIN_USER" "$ADMIN_PASS"

STORE=/var/data
mkdir -p "$STORE/data" "$STORE/img" "$STORE/backups"
cp -n /opt/seed-data/* "$STORE/data/" 2>/dev/null || true
cp -n /opt/seed-img/* "$STORE/img/" 2>/dev/null || true

rm -rf /var/www/html/data /var/www/html/img /var/www/html/admin/backups
ln -s "$STORE/data" /var/www/html/data
ln -s "$STORE/img" /var/www/html/img
ln -s "$STORE/backups" /var/www/html/admin/backups

# Make storage writable by the web server whoever owns the disk
chown -R www-data:www-data "$STORE" || true
chmod 777 "$STORE" "$STORE/data" "$STORE/img" "$STORE/backups"
chmod -R a+rwX "$STORE/data" "$STORE/img" "$STORE/backups"

echo "=== WRITE TEST (as web server user) ==="
for d in data img backups; do
  if su -s /bin/bash www-data -c "touch '$STORE/$d/.w' && rm '$STORE/$d/.w'"; then
    echo "OK writable: $d"
  else
    echo "NOT writable: $d"
  fi
done
ls -ld "$STORE" "$STORE/data" "$STORE/img" "$STORE/backups"
mount | grep "$STORE" || echo "NO DISK mounted at $STORE (changes will reset on redeploy)"

printf '<?php header("Location: /admin/"); ' > /var/www/html/index.php

sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground