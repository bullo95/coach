#!/bin/sh
# Déploiement : lancé sur la VM, en SSH, par l'utilisateur coach : /srv/coach/deploy/deploy.sh
set -eu
cd /srv/coach
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php bin/migrate.php
php bin/seed.php
echo "Déploiement terminé."
