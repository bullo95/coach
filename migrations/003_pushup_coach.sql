-- Coach des pompes : l'utilisateur fixe son programme (pompes par série, séries) et son objectif ;
-- l'app recueille son ressenti quand la journée est bouclée et propose d'ajuster.

ALTER TABLE settings ADD COLUMN pushups_goal TEXT NOT NULL DEFAULT 'equilibre'
    CHECK (pushups_goal IN ('endurance', 'volume', 'equilibre'));

-- Ressenti d'une journée bouclée : 1 = facile, 2 = correct, 3 = difficile ; douleur éventuelle.
CREATE TABLE pushup_feedback (
    id          INTEGER PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    day         TEXT    NOT NULL,
    effort      INTEGER NOT NULL CHECK (effort BETWEEN 1 AND 3),
    pain_area   TEXT    CHECK (pain_area IN ('epaule', 'coude', 'poignet', 'dos', 'autre')),
    created_at  TEXT    NOT NULL,
    UNIQUE (user_id, day)
);

-- Historique du programme : chaque réglage (par l'utilisateur ou sur conseil du coach) et chaque conseil refusé.
CREATE TABLE pushup_plan_change (
    id            INTEGER PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    changed_at    TEXT    NOT NULL,
    day           TEXT    NOT NULL,
    reps_per_set  INTEGER NOT NULL,
    sets          INTEGER NOT NULL,
    kind          TEXT    NOT NULL CHECK (kind IN ('manual', 'coach_reps', 'coach_sets', 'coach_decrease', 'declined'))
);
CREATE INDEX idx_pushup_plan_change ON pushup_plan_change (user_id, day);
