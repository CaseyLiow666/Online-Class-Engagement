-- Academic quiz grades (separate from engagement participation points).
CREATE TABLE IF NOT EXISTS quiz_completions (
    student_id INT UNSIGNED NOT NULL,
    quiz_id INT UNSIGNED NOT NULL,
    score DECIMAL(7,2) NOT NULL DEFAULT 0 COMMENT 'Sum of points_earned on correct answers',
    max_score DECIMAL(7,2) NOT NULL DEFAULT 0 COMMENT 'Sum of question weights for this quiz',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, quiz_id),
    CONSTRAINT fk_qc_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_qc_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Backfill academic grades from existing quiz answers (safe to re-run).
INSERT INTO quiz_completions (student_id, quiz_id, score, max_score)
SELECT qa.student_id,
       qa.quiz_id,
       COALESCE(SUM(qa.points_earned), 0),
       COALESCE((SELECT SUM(qq.points) FROM quiz_questions qq WHERE qq.quiz_id = qa.quiz_id), 0)
FROM quiz_answers qa
GROUP BY qa.student_id, qa.quiz_id
ON DUPLICATE KEY UPDATE
    score = VALUES(score),
    max_score = VALUES(max_score),
    updated_at = CURRENT_TIMESTAMP;
