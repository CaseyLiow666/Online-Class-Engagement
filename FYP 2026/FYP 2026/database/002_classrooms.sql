-- Class-based architecture: run on existing DB after 001 if needed.

CREATE TABLE IF NOT EXISTS classrooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    join_code CHAR(6) NOT NULL,
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

CREATE TABLE IF NOT EXISTS classroom_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_class FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_msg_class_id (classroom_id, id)
) ENGINE=InnoDB;

-- Add classroom scope to assignments (nullable for legacy rows; new posts require a class).
ALTER TABLE assignments ADD COLUMN classroom_id INT UNSIGNED NULL AFTER id;
ALTER TABLE assignments ADD CONSTRAINT fk_assign_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE;
