-- Rappels : journal des créneaux déjà envoyés, pour ne jamais envoyer deux fois le même.
-- slot : « type:jour[:numéro] », par exemple « pushups:2026-11-26:3 » ou « session:2026-11-27 ».
CREATE TABLE reminder_log (
    id       INTEGER PRIMARY KEY,
    user_id  INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    slot     TEXT    NOT NULL,
    sent_at  TEXT    NOT NULL,
    UNIQUE (user_id, slot)
);
