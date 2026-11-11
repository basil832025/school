USE school;

CREATE TABLE IF NOT EXISTS students (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 login VARCHAR(80) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 full_name VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS retake_permissions (
 student_id INT UNSIGNED NOT NULL,
 test_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (student_id, test_id),
 FOREIGN KEY (student_id) REFERENCES students(id),
 FOREIGN KEY (test_id) REFERENCES tests(id)
) ENGINE=InnoDB;

ALTER TABLE attempts
 ADD COLUMN student_id INT UNSIGNED NULL,
 ADD INDEX (student_id, test_id, id),
 ADD FOREIGN KEY (student_id) REFERENCES students(id);
