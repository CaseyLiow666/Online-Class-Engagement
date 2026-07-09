-- Add optional assignment due date. Run once on existing DBs.

USE classroom_engagement;

ALTER TABLE assignments
    ADD COLUMN due_date DATETIME NULL DEFAULT NULL AFTER description;
