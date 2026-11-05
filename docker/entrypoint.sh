#!/bin/sh
set -e

# --- Upload limits -------------------------------------------------------
# Render the transport-layer caps (nginx client_max_body_size and PHP
# upload_max_filesize / post_max_size) from the SAME env the app reads
# (config/uploads.php), so they always stay at/above the app caps. Change
# UPLOAD_MAX_IMAGES / UPLOAD_MAX_IMAGE_BYTES in .env, then `docker compose
# up -d` (recreates the container) to retune the whole chain. The defaults
# below mirror config/uploads.php.
MAX_IMAGES=${UPLOAD_MAX_IMAGES:-8}
MAX_IMAGE_BYTES=${UPLOAD_MAX_IMAGE_BYTES:-10485760}   # 10 MiB

# Single-file PHP cap: app per-image cap + 2 MiB headroom, so an over-cap
# file still reaches the app (422) instead of being silently truncated by PHP.
FILE_MIB=$(( MAX_IMAGE_BYTES / 1048576 + 2 ))

# Whole-POST PHP + nginx cap: up to MAX_IMAGES files of MAX_IMAGE_BYTES each
# plus multipart overhead, rounded up to a whole MiB.
POST_MIB=$(( (MAX_IMAGES * MAX_IMAGE_BYTES + 8 * 1048576 + 1048575) / 1048576 ))

NGINX_CONF=/etc/nginx/http.d/default.conf
PHP_INI=/usr/local/etc/php/conf.d/99-imgsli-limits.ini

sed -i "s/__MAX_BODY__/${POST_MIB}m/" "$NGINX_CONF"
# The image ships pre-rendered defaults in the PHP ini (containers that skip
# this entrypoint must still get valid values), so rewrite the whole file
# rather than substituting placeholders.
cat > "$PHP_INI" <<EOF
upload_max_filesize = ${FILE_MIB}M
post_max_size = ${POST_MIB}M
EOF

# Fail fast if the nginx template placeholder survived (drift / missing file).
if grep -qE '__MAX_BODY__' "$NGINX_CONF"; then
    echo "upload-limit placeholder left unrendered in $NGINX_CONF" >&2
    exit 1
fi

# Self-contained bind mounts: storage/app is host state (may start empty or
# root-owned), not image state — make it ready for the www-data workers.
mkdir -p /var/www/html/storage/app/public
chown -R www-data:www-data /var/www/html/storage/app

php artisan migrate --force
php artisan storage:link || true

exec "$@"
