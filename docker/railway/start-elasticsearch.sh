#!/bin/bash
set -euo pipefail

chown -R 1000:0 /usr/share/elasticsearch/data

exec setpriv --reuid=1000 --regid=0 --clear-groups /usr/local/bin/docker-entrypoint.sh "$@"
