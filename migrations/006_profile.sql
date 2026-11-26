-- Profil : sexe, date de naissance et taille. Servent à l'âge, à l'IMC et aux repères de tour de taille.
ALTER TABLE settings ADD COLUMN sex TEXT CHECK (sex IN ('homme', 'femme', 'autre'));
ALTER TABLE settings ADD COLUMN birth_date TEXT;
ALTER TABLE settings ADD COLUMN height_cm REAL CHECK (height_cm BETWEEN 100 AND 250);
