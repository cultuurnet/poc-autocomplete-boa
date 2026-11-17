#!/usr/bin/env bash
# Start command of the Railway web service: PHP-FPM in the background, nginx in
# the foreground on the port Railway routes to.
set -euo pipefail

export PORT="${PORT:-8080}"

# Only ${PORT} is substituted; nginx's own $uri, $args, ... must survive.
sed "s/\${PORT}/${PORT}/g" /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf

php-fpm --daemonize
exec nginx -g 'daemon off;'
