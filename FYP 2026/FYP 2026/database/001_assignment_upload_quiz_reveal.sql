-- Run against existing classroom_engagement DB (skip lines that error if columns exist).

ALTER TABLE quizzes
    ADD COLUMN reveal_question_id INT UNSIGNED NULL DEFAULT NULL AFTER current_question,
    ADD COLUMN reveal_ends_at DATETIME NULL DEFAULT NULL AFTER reveal_question_id;

ALTER TABLE assignment_completions
    ADD COLUMN submitted_file_path VARCHAR(512) NULL DEFAULT NULL AFTER completed_at;
