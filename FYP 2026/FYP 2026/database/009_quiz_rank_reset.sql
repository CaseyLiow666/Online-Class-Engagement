-- Timestamp bumped when teacher resets live quiz leaderboard / answer data.
ALTER TABLE quizzes
    ADD COLUMN rank_reset_at TIMESTAMP NULL DEFAULT NULL AFTER reveal_ends_at;
