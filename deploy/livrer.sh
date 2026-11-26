#!/bin/sh
# Livraison depuis le Mac vers la VM de la Freebox : deploy/livrer.sh [compte@machine]
# Copie le code (sans la base, ni .env, ni vendor), installe les dépendances, applique migrations et seed.
# Première installation : voir deploy/INSTALL-VM.md, « VM Ubuntu avec nginx ».
set -eu
HOST="${1:-freebox@192.168.1.199}"
cd "$(dirname "$0")/.."
rsync -az --delete \
  --exclude '.git' --exclude '.claude' --exclude '.env' --exclude '/var' --exclude '/vendor' \
  --exclude '.phpunit.cache' --exclude '.DS_Store' \
  ./ "$HOST:/srv/coach/"
ssh "$HOST" 'set -eu; cd /srv/coach
  composer install --no-dev --optimize-autoloader --no-interaction --quiet
  sudo -u www-data php bin/migrate.php
  sudo -u www-data php bin/seed.php
  curl -fsS http://localhost:8080/api/health; echo'
echo "Livraison terminée."
