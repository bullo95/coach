# Coach

Application personnelle de suivi sportif (PWA) : séances guidées A/B/C, pompes quotidiennes, protéines, rappels.
Spécifications : [docs/cahier-des-charges.md](docs/cahier-des-charges.md).

Stack : PHP 8.4 sans framework, SQLite, HTML/CSS/JavaScript natif, service worker écrit à la main, cron.

## Démarrer en local

```sh
composer install
cp .env.example .env
php bin/migrate.php
php bin/seed.php
php bin/create-user.php vous@exemple.fr Europe/Paris
composer serve            # http://localhost:8010
```

## Commandes

| Commande | Rôle |
| --- | --- |
| `php bin/migrate.php` | Applique les fichiers `migrations/*.sql` pas encore passés |
| `php bin/seed.php` | Recharge la bibliothèque et le programme depuis `data/*.json`, sans toucher à l'historique |
| `php bin/create-user.php email [fuseau]` | Crée le compte unique |
| `php bin/media-import.php` | Télécharge les visuels listés dans `data/exercise_media.json`, les convertit en WebP dans `public/media/` (relancer le seed ensuite) |
| `php bin/vapid-keys.php [sujet]` | Crée les clés des notifications dans `.env` ; sujet : `mailto:adresse` ou l'adresse https de l'app |
| `php bin/reminders.php [--dry-run] [--at="AAAA-MM-JJ HH:MM"]` | Envoie les rappels dus (lancé chaque minute par cron) ; `--dry-run` montre ce qui partirait |
| `php bin/make-icons.php` | Régénère les icônes de la PWA |
| `composer test` | Tests PHPUnit |

## Organisation

```text
public/      index.php (routeur), index.html, css/, js/, sw.js, manifest, icons/, media/
src/         Config, Db, Auth, Migrator, Seeder, Program, App (routes), Http/
bin/         scripts en ligne de commande
migrations/  schéma SQL numéroté
data/        program.json, exercises.json, exercise_media.json, encouragements.json
deploy/      Caddyfile, pool PHP-FPM, crontab, deploy.sh, backup.sh, INSTALL-VM.md
tests/       PHPUnit
```

## Modifier le programme

Éditer `data/program.json` ou `data/exercises.json`, puis `php bin/seed.php`. Les clés `ref` et `slug` sont stables :
les renommer revient à supprimer un élément et en créer un autre. Un élément retiré qui a déjà servi dans l'historique est désactivé, pas supprimé.

## Mise en production

Voir [deploy/INSTALL-VM.md](deploy/INSTALL-VM.md), puis `deploy/deploy.sh` à chaque mise à jour.
Si la liste des fichiers de l'app change (nouveau fichier JS ou CSS), l'ajouter à `SHELL` et incrémenter `VERSION` dans `public/sw.js`.
