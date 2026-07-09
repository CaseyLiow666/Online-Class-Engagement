-- Sync existing database: add classrooms.status (1=open, 0=closed)
-- Run once in phpMyAdmin, MySQL Workbench, or: mysql -u root -p classroom_engagement < migrations/004_classroom_status.sql

USE classroom_engagement;

ALTER TABLE classrooms
    ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Open, 0=Closed' AFTER join_code;

-- Existing rows get status = 1 automatically via DEFAULT.
