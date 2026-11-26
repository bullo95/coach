# Installation de la VM (lot 1)

Étapes à faire une fois, à la main. Prérequis : Freebox Delta ou Ultra première version (voir le cahier des charges, « Hébergement sur la Freebox »).

## 1. Créer la VM dans Freebox OS

- Image Debian 13 (trixie) ARM64, 1 vCPU, 1 Go de RAM (512 Mo suffisent), disque de 8 Go.
- Activer le démarrage automatique.
- Ajouter votre clé SSH publique à la création.

## 2. Réseau

1. Freebox OS → État Internet : vérifier que l'IPv4 est « full-stack ». Sinon, la demander dans l'Espace Abonné (Ma Freebox) et redémarrer la box.
2. Bail DHCP statique pour la VM.
3. Redirection des ports TCP 80 et 443 vers l'IP locale de la VM. Ne jamais rediriger le port 22.
4. Chez le registraire du domaine : enregistrement DNS A `coach.<votre-domaine>` → IP publique de la Freebox.

## 3. Paquets

```sh
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-gd php8.4-mbstring php8.4-intl \
  php8.4-gmp php8.4-curl php8.4-xml unzip git sqlite3 rsync caddy composer
```

Si le paquet `caddy` de Debian est trop ancien, utiliser le dépôt officiel de Caddy (caddyserver.com/docs/install).

## 4. Application

```sh
sudo adduser --system --group --home /srv/coach coach
sudo -u coach git clone <url-du-dépôt> /srv/coach
cd /srv/coach
sudo -u coach cp .env.example .env        # APP_ENV=prod
sudo -u coach mkdir -p var/log
sudo -u coach composer install --no-dev --optimize-autoloader
sudo -u coach php bin/migrate.php
sudo -u coach php bin/seed.php
sudo -u coach php bin/create-user.php votre@email.fr Europe/Paris
sudo -u coach php bin/vapid-keys.php mailto:votre@email.fr   # clés des notifications
```

## 5. Services

```sh
sudo cp deploy/php-fpm-coach.conf /etc/php/8.4/fpm/pool.d/coach.conf
sudo systemctl restart php8.4-fpm
sudo cp deploy/Caddyfile /etc/caddy/Caddyfile   # remplacer coach.example.fr
sudo systemctl reload caddy
sudo crontab -u coach deploy/crontab
```

Caddy obtient le certificat Let's Encrypt au premier accès : ouvrir `https://coach.<votre-domaine>/api/health`, la réponse doit être `{"ok":true}`.

## 6. Sauvegarde

Monter un partage du disque de la Freebox sur `/mnt/freebox` (SMB, via `/etc/fstab`), puis lancer une fois `deploy/backup.sh` et tester la restauration :

```sh
gunzip -c /mnt/freebox/coach-backups/db/coach-AAAA-MM-JJ.sqlite.gz > /tmp/test.sqlite
sqlite3 /tmp/test.sqlite "select count(*) from exercise;"
```

## 7. Sécurité

- SSH accessible seulement depuis le réseau local ou le VPN de la Freebox.
- `sudo apt install unattended-upgrades` pour les mises à jour de sécurité automatiques.

## VM Ubuntu avec nginx (installation en place sur 192.168.1.199)

La VM actuelle est en Ubuntu 24.04 avec nginx et PHP-FPM 8.3 ; elle sert déjà free-control sur le port 80. Coach y est installé à côté, sans compte ni pool dédiés :

- code dans `/srv/coach` (compte `freebox`), données dans `/srv/coach/var` (compte `www-data`) ;
- site nginx `deploy/nginx-coach.conf` sur le port 8080, en HTTP, réseau local seulement ;
- rappels : crontab de `www-data`, chaque minute ;
- paquets ajoutés : `php8.3-sqlite3 php8.3-mbstring php8.3-gmp composer unzip sqlite3`.

Livrer une nouvelle version depuis le Mac : `deploy/livrer.sh`.

Créer le compte (une fois) : `ssh -t freebox@192.168.1.199 'cd /srv/coach && sudo -u www-data php bin/create-user.php votre@email.fr'`.

Reste à faire pour l'iPhone : un nom de domaine en HTTPS (installation de l'app et notifications), puis la sauvegarde (`deploy/backup.sh`).
