-- Schéma initial : 18 tables (voir docs/cahier-des-charges.md, « Modèle de données »).
-- Dates : texte ISO 8601 (UTC pour les horodatages, AAAA-MM-JJ pour les jours locaux).
-- Champs JSON : texte, lu avec les fonctions JSON de SQLite.

-- Compte et réglages ---------------------------------------------------------

CREATE TABLE user (
    id             INTEGER PRIMARY KEY,
    email          TEXT    NOT NULL UNIQUE,
    password_hash  TEXT    NOT NULL,
    timezone       TEXT    NOT NULL DEFAULT 'Europe/Paris',
    created_at     TEXT    NOT NULL
);

CREATE TABLE settings (
    user_id                    INTEGER PRIMARY KEY REFERENCES user(id) ON DELETE CASCADE,
    session_days               TEXT    NOT NULL DEFAULT '[1,3,5]',   -- ISO : 1 = lundi … 7 = dimanche
    session_time               TEXT    NOT NULL DEFAULT '18:00',
    program_start_date         TEXT,
    pushups_reps_per_set       INTEGER NOT NULL DEFAULT 23 CHECK (pushups_reps_per_set BETWEEN 1 AND 200),  -- X
    pushups_sets               INTEGER NOT NULL DEFAULT 10 CHECK (pushups_sets BETWEEN 1 AND 30),
    pushups_light_day_enabled  INTEGER NOT NULL DEFAULT 1 CHECK (pushups_light_day_enabled IN (0, 1)),
    pushups_light_day_sets     INTEGER NOT NULL DEFAULT 5 CHECK (pushups_light_day_sets BETWEEN 1 AND 30),
    pushups_jokers_per_month   INTEGER NOT NULL DEFAULT 3 CHECK (pushups_jokers_per_month BETWEEN 0 AND 31),
    pushups_joker_auto         INTEGER NOT NULL DEFAULT 1 CHECK (pushups_joker_auto IN (0, 1)),
    pushups_last_x_offer_at    TEXT,
    pushups_window_start       TEXT    NOT NULL DEFAULT '08:00',
    pushups_window_end         TEXT    NOT NULL DEFAULT '21:00',
    protein_coef               REAL    NOT NULL DEFAULT 1.5 CHECK (protein_coef BETWEEN 0.83 AND 2.0),
    meals_per_day              INTEGER NOT NULL DEFAULT 3 CHECK (meals_per_day BETWEEN 1 AND 8),
    quiet_start                TEXT    NOT NULL DEFAULT '22:00',
    quiet_end                  TEXT    NOT NULL DEFAULT '07:30',
    sound_enabled              INTEGER NOT NULL DEFAULT 1 CHECK (sound_enabled IN (0, 1)),
    vibration_enabled          INTEGER NOT NULL DEFAULT 1 CHECK (vibration_enabled IN (0, 1)),
    circuit_transition_s       INTEGER NOT NULL DEFAULT 15 CHECK (circuit_transition_s BETWEEN 0 AND 120),
    interval_fast_kmh          REAL    NOT NULL DEFAULT 5.5,
    interval_slow_kmh          REAL    NOT NULL DEFAULT 3.5
);

-- Bibliothèque et programme (modèle) -----------------------------------------

CREATE TABLE exercise (
    id                   INTEGER PRIMARY KEY,
    slug                 TEXT    NOT NULL UNIQUE,
    name_fr              TEXT    NOT NULL,
    name_en              TEXT,
    muscles              TEXT,
    cues                 TEXT    NOT NULL DEFAULT '[]',             -- JSON : 3 consignes
    mistake              TEXT,
    easier_variant_id    INTEGER REFERENCES exercise(id) ON DELETE SET NULL,
    easier_variant_text  TEXT,                                      -- variante = simple réglage (sans poids, amplitude réduite…)
    media_type           TEXT    NOT NULL DEFAULT 'placeholder' CHECK (media_type IN ('placeholder', 'images', 'video')),
    media_paths          TEXT    NOT NULL DEFAULT '[]',             -- JSON
    source               TEXT,
    license              TEXT,
    attribution          TEXT
);

CREATE TABLE workout_template (
    id                INTEGER PRIMARY KEY,
    code              TEXT    NOT NULL UNIQUE,                      -- A, B, C
    name              TEXT    NOT NULL,
    est_duration_min  INTEGER NOT NULL
);

CREATE TABLE template_block (
    id                     INTEGER PRIMARY KEY,
    template_id            INTEGER NOT NULL REFERENCES workout_template(id) ON DELETE CASCADE,
    ref                    TEXT    NOT NULL UNIQUE,                 -- clé stable dans data/program.json
    position               INTEGER NOT NULL,
    name                   TEXT    NOT NULL,
    type                   TEXT    NOT NULL CHECK (type IN ('warmup', 'circuit', 'sets', 'interval', 'amrap', 'cooldown')),
    rounds                 INTEGER NOT NULL DEFAULT 1,
    rest_between_rounds_s  INTEGER,
    duration_s             INTEGER,
    active                 INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE template_item (
    id                  INTEGER PRIMARY KEY,
    block_id            INTEGER NOT NULL REFERENCES template_block(id) ON DELETE CASCADE,
    ref                 TEXT    NOT NULL UNIQUE,
    exercise_id         INTEGER NOT NULL REFERENCES exercise(id) ON DELETE RESTRICT,
    position            INTEGER NOT NULL,
    target_reps         INTEGER,
    target_reps_max     INTEGER,
    target_seconds      INTEGER,
    target_seconds_max  INTEGER,
    per_side            INTEGER NOT NULL DEFAULT 0 CHECK (per_side IN (0, 1)),
    load_kg             REAL,
    load_label          TEXT,
    rest_s              INTEGER,
    active              INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    CHECK (target_reps IS NOT NULL OR target_seconds IS NOT NULL)
);

CREATE TABLE user_item_load (
    id                INTEGER PRIMARY KEY,
    user_id           INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    template_item_id  INTEGER NOT NULL REFERENCES template_item(id) ON DELETE CASCADE,
    load_kg           REAL,
    target_reps       INTEGER,
    rest_s            INTEGER,
    validated_at      TEXT    NOT NULL
);
CREATE INDEX idx_user_item_load ON user_item_load (user_id, template_item_id, validated_at);

-- Journal des séances ----------------------------------------------------------

CREATE TABLE workout_session (
    id            INTEGER PRIMARY KEY,
    client_uuid   TEXT    NOT NULL UNIQUE,
    user_id       INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    template_id   INTEGER NOT NULL REFERENCES workout_template(id) ON DELETE RESTRICT,
    planned_date  TEXT    NOT NULL,
    started_at    TEXT,
    ended_at      TEXT,
    status        TEXT    NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'in_progress', 'done', 'skipped')),
    rounds_done   INTEGER,
    effort_1_5    INTEGER CHECK (effort_1_5 BETWEEN 1 AND 5),
    pain_flag     INTEGER NOT NULL DEFAULT 0 CHECK (pain_flag IN (0, 1)),
    note          TEXT
);
CREATE INDEX idx_workout_session_user_date ON workout_session (user_id, planned_date);

CREATE TABLE set_log (
    id                INTEGER PRIMARY KEY,
    client_uuid       TEXT    NOT NULL UNIQUE,
    session_id        INTEGER NOT NULL REFERENCES workout_session(id) ON DELETE CASCADE,
    template_item_id  INTEGER NOT NULL REFERENCES template_item(id) ON DELETE RESTRICT,
    round             INTEGER NOT NULL DEFAULT 1,
    reps_done         INTEGER,
    seconds_done      INTEGER,
    load_kg           REAL,
    logged_at         TEXT    NOT NULL
);
CREATE INDEX idx_set_log_item ON set_log (template_item_id, logged_at);

-- Pompes -----------------------------------------------------------------------

CREATE TABLE pushup_log (
    id           INTEGER PRIMARY KEY,
    client_uuid  TEXT    NOT NULL UNIQUE,
    user_id      INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    logged_at    TEXT    NOT NULL,
    count        INTEGER NOT NULL CHECK (count > 0)
);
CREATE INDEX idx_pushup_log_user ON pushup_log (user_id, logged_at);

CREATE TABLE pushup_day (
    id            INTEGER PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    day           TEXT    NOT NULL,                                 -- jour local AAAA-MM-JJ
    reps_per_set  INTEGER NOT NULL,                                 -- X figé ce jour-là
    sets_target   INTEGER NOT NULL,
    target        INTEGER NOT NULL,
    done          INTEGER NOT NULL DEFAULT 0,
    status        TEXT    NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress', 'reached', 'joker', 'missed')),
    joker_mode    TEXT    CHECK (joker_mode IN ('manual', 'auto')),
    closed_at     TEXT,
    UNIQUE (user_id, day)
);

CREATE TABLE pain_report (
    id             INTEGER PRIMARY KEY,
    user_id        INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    reported_at    TEXT    NOT NULL,
    context        TEXT    NOT NULL CHECK (context IN ('workout', 'pushups')),
    area           TEXT    NOT NULL CHECK (area IN ('epaule', 'coude', 'dos', 'genou', 'autre')),
    session_id     INTEGER REFERENCES workout_session(id) ON DELETE SET NULL,
    reduced_until  TEXT
);

-- Mesures ----------------------------------------------------------------------

CREATE TABLE body_measure (
    id           INTEGER PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    measured_at  TEXT    NOT NULL,
    weight_kg    REAL    CHECK (weight_kg BETWEEN 30 AND 300),
    waist_cm     REAL    CHECK (waist_cm BETWEEN 40 AND 250),
    CHECK (weight_kg IS NOT NULL OR waist_cm IS NOT NULL)
);
CREATE INDEX idx_body_measure_user ON body_measure (user_id, measured_at);

-- Protéines --------------------------------------------------------------------

CREATE TABLE food (
    id                  INTEGER PRIMARY KEY,
    ciqual_code         TEXT    NOT NULL UNIQUE,
    name                TEXT    NOT NULL,
    protein_g_per_100g  REAL    NOT NULL CHECK (protein_g_per_100g >= 0)
);

CREATE TABLE food_favorite (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    food_id    INTEGER NOT NULL REFERENCES food(id) ON DELETE CASCADE,
    label      TEXT    NOT NULL,
    portion_g  REAL    NOT NULL CHECK (portion_g > 0)
);

CREATE TABLE protein_log (
    id           INTEGER PRIMARY KEY,
    client_uuid  TEXT    NOT NULL UNIQUE,
    user_id      INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    logged_at    TEXT    NOT NULL,
    food_id      INTEGER REFERENCES food(id) ON DELETE SET NULL,
    label        TEXT,
    portion_g    REAL,
    protein_g    REAL    NOT NULL CHECK (protein_g >= 0),
    CHECK (food_id IS NOT NULL OR label IS NOT NULL)
);
CREATE INDEX idx_protein_log_user ON protein_log (user_id, logged_at);

-- Rappels ----------------------------------------------------------------------

CREATE TABLE push_subscription (
    id          INTEGER PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    endpoint    TEXT    NOT NULL UNIQUE,
    p256dh      TEXT    NOT NULL,
    auth        TEXT    NOT NULL,
    user_agent  TEXT,
    created_at  TEXT    NOT NULL
);

CREATE TABLE reminder (
    id            INTEGER PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    type          TEXT    NOT NULL CHECK (type IN ('session', 'session_missed', 'pushups', 'protein', 'weight', 'waist')),
    rule          TEXT    NOT NULL DEFAULT '{}',                    -- JSON
    enabled       INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    last_sent_at  TEXT,
    UNIQUE (user_id, type)
);
