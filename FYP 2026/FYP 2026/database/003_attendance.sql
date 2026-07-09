-- Daily attendance per class (classroom_id = class_id).

CREATE TABLE IF NOT EXISTS attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Present, 0=Absent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_att_class FOREIGN KEY (class_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_att_class_date (class_id, created_at),
    INDEX idx_att_user (user_id)
) ENGINE=InnoDB;
