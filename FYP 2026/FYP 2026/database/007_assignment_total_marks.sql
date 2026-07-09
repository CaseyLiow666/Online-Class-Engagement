-- Max academic marks per assignment (e.g. grade 8 out of 10).
ALTER TABLE assignments
    ADD COLUMN total_marks DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Maximum academic marks for this assignment' AFTER due_date;
