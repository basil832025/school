USE school;

CREATE TABLE IF NOT EXISTS telegram_registrations (
 telegram_id BIGINT UNSIGNED PRIMARY KEY,
 last_update BIGINT UNSIGNED NOT NULL,
 stage VARCHAR(20) NOT NULL,
 phone VARCHAR(20) NULL,
 surname VARCHAR(75) NULL,
 first_name VARCHAR(75) NULL,
 class_id INT UNSIGNED NULL,
 birth_year SMALLINT UNSIGNED NULL,
 student_id INT UNSIGNED NULL UNIQUE,
 FOREIGN KEY (class_id) REFERENCES classes(id),
 FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS telegram_codes (
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 student_id INT UNSIGNED NOT NULL,
 purpose ENUM('signin','link','keyboard','keyboard_device') NOT NULL,
 expires_at DATETIME NOT NULL,
 INDEX (student_id, purpose),
 FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;
