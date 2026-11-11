ALTER TABLE questions
 ADD COLUMN image_url VARCHAR(2048) NULL,
 ADD COLUMN image_size ENUM('small','medium','large') NOT NULL DEFAULT 'medium';

ALTER TABLE question_options
 ADD COLUMN image_url VARCHAR(2048) NULL,
 ADD COLUMN image_size ENUM('small','medium','large') NOT NULL DEFAULT 'medium';
