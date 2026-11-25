-- Jokers communs aux pompes et aux séances : une séance sautée avec un joker est une ligne
-- workout_session au statut « skipped » avec joker = 1. Le quota du mois (settings.pushups_jokers_per_month)
-- compte les journées de pompes « joker » et ces séances.
ALTER TABLE workout_session ADD COLUMN joker INTEGER NOT NULL DEFAULT 0 CHECK (joker IN (0, 1));
