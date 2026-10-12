#!/bin/sh
set -e

# Railway injects $PORT; fall back to 80 for local docker runs.
PORT_TO_USE="${PORT:-80}"

# Apache must listen on the Railway port.
sed -i "s/^Listen .*/Listen ${PORT_TO_USE}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:.*>/<VirtualHost *:${PORT_TO_USE}>/" /etc/apache2/sites-available/000-default.conf

# First boot: .env is NOT committed, so create it from the example when missing.
if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

# Cache config/routes/views only when an APP_KEY exists; otherwise the
# container still starts and the operator sees a clear artisan error.
if grep -q '^APP_KEY=.\+' .env 2>/dev/null; then
  php artisan config:cache --no-ansi || true
  php artisan route:cache --no-ansi || true
  php artisan view:cache --no-ansi || true
# DEPLOYMENT STEP IS SEPARATED: migration does not run at launch.
# After you have verified the production database, backed it up, and reviewed
# the migrations, run explicitly:
#   docker compose exec web php artisan migrate --force
# Run `php artisan migrate --force --seed` only for master/demo data, after
# review. Never leave `migrate --force` inside the container launch script.
else
  echo 'WARNING: APP_KEY is empty; set it in Railway variables before use.'
fi

exec apache2-foreground
