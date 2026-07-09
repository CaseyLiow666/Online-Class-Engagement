-- Classroom Engagement System — MySQL schema
-- Create database: CREATE DATABASE classroom_engagement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE classroom_engagement;
-- Then run this file.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','teacher','student') NOT NULL,
    full_name VARCHAR(128) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quizzes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    classroom_id INT UNSIGNED DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    room_code VARCHAR(16) NOT NULL UNIQUE,
    status ENUM('draft','live','finished') NOT NULL DEFAULT 'draft',
    current_question SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    reveal_question_id INT UNSIGNED DEFAULT NULL,
    reveal_ends_at DATETIME DEFAULT NULL,
    rank_reset_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_quizzes_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_quizzes_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE SET NULL,
    INDEX idx_quizzes_classroom (classroom_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quiz_questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    opt_a VARCHAR(255) NOT NULL,
    opt_b VARCHAR(255) NOT NULL,
    opt_c VARCHAR(255) NOT NULL,
    opt_d VARCHAR(255) NOT NULL,
    correct CHAR(1) NOT NULL,
    points SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_qq_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    CONSTRAINT chk_correct CHECK (correct IN ('a','b','c','d'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quiz_answers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    quiz_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    chosen CHAR(1) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    points_earned SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_question (student_id, question_id),
    CONSTRAINT fk_qa_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_qa_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    CONSTRAINT fk_qa_question FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
    CONSTRAINT chk_chosen CHECK (chosen IN ('a','b','c','d'))
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS classrooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    join_code CHAR(6) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Open, 0=Closed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_classrooms_join (join_code),
    CONSTRAINT fk_classrooms_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_members (
    classroom_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (classroom_id, student_id),
    CONSTRAINT fk_cm_class FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS channels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT UNSIGNED NOT NULL,
    name VARCHAR(128) NOT NULL DEFAULT 'General',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_channels_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    INDEX idx_channels_classroom (classroom_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT UNSIGNED NOT NULL,
    channel_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    message_type ENUM('text', 'announcement', 'notification') NOT NULL DEFAULT 'text',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_class FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE SET NULL,
    CONSTRAINT fk_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_msg_class_id (classroom_id, id),
    INDEX idx_msg_channel (channel_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    due_date DATETIME DEFAULT NULL,
    total_marks DECIMAL(5,2) DEFAULT NULL COMMENT 'Maximum academic marks for this assignment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assign_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_assign_classroom (classroom_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assignment_completions (
    student_id INT UNSIGNED NOT NULL,
    assignment_id INT UNSIGNED NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_file_path VARCHAR(512) DEFAULT NULL,
    grade DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL = not graded yet',
    feedback TEXT DEFAULT NULL,
    PRIMARY KEY (student_id, assignment_id),
    CONSTRAINT fk_ac_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ac_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS point_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    points INT NOT NULL,
    source_type ENUM('quiz','assignment') NOT NULL,
    source_ref VARCHAR(255) DEFAULT NULL,
    detail VARCHAR(512) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_pl_user_time (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS total_scores (
    user_id INT UNSIGNED PRIMARY KEY,
    total_points INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Present, 0=Absent',
    date DATE NOT NULL COMMENT 'Session date (manual / backdatable)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was saved',
    CONSTRAINT fk_att_class FOREIGN KEY (class_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_att_class_user_date (class_id, user_id, date),
    INDEX idx_att_class_date (class_id, date),
    INDEX idx_att_user (user_id)
) ENGINE=InnoDB;

-- Default admin — username: admin  password: password  (change after first login via DB or future profile feature)
INSERT INTO users (username, password_hash, role, full_name)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    'System Administrator'
);

INSERT INTO total_scores (user_id, total_points)
SELECT id, 0 FROM users WHERE username = 'admin';