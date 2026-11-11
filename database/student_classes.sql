ALTER TABLE students ADD COLUMN class_id INT UNSIGNED NULL,
 ADD FOREIGN KEY (class_id) REFERENCES classes(id);

UPDATE students s JOIN telegram_registrations r ON r.student_id = s.id
 SET s.class_id = r.class_id
 WHERE r.stage = 'done' AND r.class_id IS NOT NULL AND s.class_id IS NULL;
