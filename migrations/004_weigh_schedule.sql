-- Pesée hebdomadaire : jour (ISO, 1 = lundi) et heure auxquels l'app demande de se peser.
ALTER TABLE settings ADD COLUMN weigh_day INTEGER NOT NULL DEFAULT 1 CHECK (weigh_day BETWEEN 1 AND 7);
ALTER TABLE settings ADD COLUMN weigh_time TEXT NOT NULL DEFAULT '07:00';
