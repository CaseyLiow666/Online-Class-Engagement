-- Migration 006: channels, message types, assignment grading, attendance date column
-- Run after migrations 001–005 on an existing classroom_engagement database.
--
--   USE classroom_engagement;
--   SOURCE migrations/006_channels_messages_grades_attendance_date.sql;

USE classroom_engagement;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) channels — chat/announcement channels per classroom
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS channels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT UNSIGNED NOT NULL,
    name VARCHAR(128) NOT NULL DEFAULT 'General',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_channels_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    INDEX idx_channels_classroom (classroom_id)
) ENGINE=InnoDB;

-- Seed a default "General" channel for every existing classroom (safe to re-run: skip duplicates).
INSERT INTO channels (classroom_id, name)
SELECT c.id, 'General'
FROM classrooms c
WHERE NOT EXISTS (
    SELECT 1 FROM channels ch WHERE ch.classroom_id = c.id AND ch.name = 'General'
);

-- ---------------------------------------------------------------------------
-- 2) classroom_messages — channel link + message type
-- ---------------------------------------------------------------------------
ALTER TABLE classroom_messages
    ADD COLUMN channel_id INT UNSIGNED NULL DEFAULT NULL AFTER classroom_id,
    ADD COLUMN message_type ENUM('text', 'announcement', 'notification') NOT NULL DEFAULT 'text' AFTER body;

ALTER TABLE classroom_messages
    ADD CONSTRAINT fk_msg_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE SET NULL;

ALTER TABLE classroom_messages
    ADD INDEX idx_msg_channel (channel_id);

-- Point legacy messages at each classroom's General channel where possible.
UPDATE classroom_messages m
INNER JOIN channels ch ON ch.classroom_id = m.classroom_id AND ch.name = 'General'
SET m.channel_id = ch.id
WHERE m.channel_id IS NULL;

-- ---------------------------------------------------------------------------
-- 3) assignment_completions — teacher grade and feedback (NULL = ungraded)
-- ---------------------------------------------------------------------------
ALTER TABLE assignment_completions
    ADD COLUMN grade DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'NULL = not graded yet' AFTER submitted_file_path,
    ADD COLUMN feedback TEXT NULL DEFAULT NULL AFTER grade;

-- ---------------------------------------------------------------------------
-- 4) attendance — explicit DATE column for manual / backdated roll calls
--    (keeps created_at as audit timestamp of when the record was saved)
-- ---------------------------------------------------------------------------

-- Remove duplicate rows for the same class + student + calendar day before adding uniqueness.
DELETE a1 FROM attendance a1
INNER JOIN attendance a2
    ON a1.class_id = a2.class_id
   AND a1.user_id = a2.user_id
   AND DATE(a1.created_at) = DATE(a2.created_at)
   AND a1.id > a2.id;

ALTER TABLE attendance
    ADD COLUMN date DATE NULL DEFAULT NULL AFTER status;

UPDATE attendance
SET date = DATE(created_at)
WHERE date IS NULL;

ALTER TABLE attendance
    MODIFY date DATE NOT NULL;

ALTER TABLE attendance
    DROP INDEX idx_att_class_date;

ALTER TABLE attendance
    ADD INDEX idx_att_class_date (class_id, date);

ALTER TABLE attendance
    ADD UNIQUE KEY uq_att_class_user_date (class_id, user_id, date);
