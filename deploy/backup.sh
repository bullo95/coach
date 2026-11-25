#!/bin/sh
# Sauvegarde quotidienne : copie cohérente de la base SQLite + visuels, conservée 30 jours.
# BACKUP_DIR : stockage de la Freebox monté sur la VM (ex. partage SMB monté sur /mnt/freebox).
set -eu
APP=/srv/coach
BACKUP_DIR=${BACKUP_DIR:-/mnt/freebox/coach-backups}
STAMP=$(date +%Y-%m-%d)

mkdir -p "$BACKUP_DIR/db" "$BACKUP_DIR/media"
sqlite3 "$APP/var/coach.sqlite" ".backup '$BACKUP_DIR/db/coach-$STAMP.sqlite'"
gzip -f "$BACKUP_DIR/db/coach-$STAMP.sqlite"
rsync -a --delete "$APP/public/media/" "$BACKUP_DIR/media/"
find "$BACKUP_DIR/db" -name 'coach-*.sqlite.gz' -mtime +30 -delete

# Copie hebdomadaire hors du domicile (le dimanche), une fois le fournisseur choisi :
# if [ "$(date +%u)" = 7 ]; then restic -r "$RESTIC_REPOSITORY" backup "$BACKUP_DIR"; fi

echo "$(date -Is) sauvegarde OK"
