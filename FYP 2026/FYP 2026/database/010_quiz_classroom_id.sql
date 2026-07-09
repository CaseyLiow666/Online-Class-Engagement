-- Anchor each quiz to a single classroom (prevents cross-class grade duplication).
ALTER TABLE quizzes
    ADD COLUMN classroom_id INT UNSIGNED NULL DEFAULT NULL AFTER teacher_id,
    ADD CONSTRAINT fk_quizzes_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE SET NULL,
    ADD INDEX idx_quizzes_classroom (classroom_id);
