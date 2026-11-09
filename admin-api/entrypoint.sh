#!/bin/bash
# Runs as root every time the container starts (Render: every deploy / restart).
set -e

ENTRYPOINT_VERSION="2026-11-09-supabase-r1"

echo "=== MacOs admin entrypoint ${ENTRYPOINT_VERSION} ==="
echo "commit=${RENDER_GIT_COMMIT:-unknown} branch=${RENDER_GIT_BRANCH:-unknown} running-as=$(id -un)"

# ---- Admin login (from Render > Environment; never stored in the repo) ---------
: "${ADMIN_USER:?Set ADMIN_USER in Render Environment}"
: "${ADMIN_PASS:?Set ADMIN_PASS in Render Environment}"
if [[ "$ADMIN_USER" == *:* ]]; then echo "ADMIN_USER must not contain a colon"; exit 1; fi
printf '%s' "$ADMIN_PASS" | htpasswd -i -c /etc/apache2/.htpasswd "$ADMIN_USER"

# ---- Supabase ------------------------------------------------------------------
echo "--- supabase check"
if [ -z "${SUPABASE_DB_URL:-}" ]; then
  echo "!!! SUPABASE_DB_URL is not set. Add it in Render > Environment, then redeploy."
else
  OK=0
  for TRY in 1 2 3; do
    if php /opt/app/seed.php; then OK=1; break; fi
    echo "[seed] attempt $TRY failed, retrying in 5 seconds..."
    sleep 5
  done
  if [ "$OK" != "1" ]; then
    echo "!!! Could not reach Supabase. The site will start, but saving and photos will not work"
    echo "!!! until this is fixed. Check SUPABASE_DB_URL (user, password, host, port 6543 or 5432)."
  fi
fi
echo "--- end checks"

# Lets admin/save.php report which version is running
echo "entrypoint=${ENTRYPOINT_VERSION} commit=${RENDER_GIT_COMMIT:-unknown} started=$(date -u +%Y-%m-%dT%H:%M:%SZ)" > /etc/macos-build
chmod 644 /etc/macos-build

# ---- Apache must listen on Render's $PORT --------------------------------------
sed -i -E "s/^Listen 80$/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -i -E "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
