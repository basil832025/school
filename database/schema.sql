CREATE DATABASE IF NOT EXISTS school CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE school;

CREATE TABLE IF NOT EXISTS teachers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 login VARCHAR(80) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 display_name VARCHAR(120) NOT NULL,
 role ENUM('teacher','super_admin') NOT NULL DEFAULT 'teacher',
 is_active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 grade TINYINT UNSIGNED NOT NULL,
 section VARCHAR(8) NOT NULL DEFAULT '',
 UNIQUE (grade, section)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subjects (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tests (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subject_id INT UNSIGNED NOT NULL,
 teacher_id INT UNSIGNED NOT NULL,
 previous_test_id INT UNSIGNED NULL,
 title VARCHAR(180) NOT NULL,
 status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
 created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
 published_at DATETIME NULL,
 FOREIGN KEY (subject_id) REFERENCES subjects(id),
 FOREIGN KEY (teacher_id) REFERENCES teachers(id),
 FOREIGN KEY (previous_test_id) REFERENCES tests(id),
 INDEX (status, subject_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS test_classes (
 test_id INT UNSIGNED NOT NULL,
 class_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (test_id, class_id),
 FOREIGN KEY (test_id) REFERENCES tests(id),
 FOREIGN KEY (class_id) REFERENCES classes(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS questions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 test_id INT UNSIGNED NOT NULL,
 type ENUM('single','multiple','written') NOT NULL,
 prompt TEXT NOT NULL,
 max_points DECIMAL(8,2) UNSIGNED NOT NULL DEFAULT 1,
 position SMALLINT UNSIGNED NOT NULL,
 grading_guidance TEXT NULL,
 image_url VARCHAR(2048) NULL,
 image_size ENUM('small','medium','large') NOT NULL DEFAULT 'medium',
 UNIQUE (test_id, position),
 FOREIGN KEY (test_id) REFERENCES tests(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS question_options (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 question_id INT UNSIGNED NOT NULL,
 label TEXT NOT NULL,
 image_url VARCHAR(2048) NULL,
 image_size ENUM('small','medium','large') NOT NULL DEFAULT 'medium',
 is_correct BOOLEAN NOT NULL DEFAULT FALSE,
 position SMALLINT UNSIGNED NOT NULL,
 UNIQUE (question_id, position),
 FOREIGN KEY (question_id) REFERENCES questions(id)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS attempts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id INT UNSIGNED NULL,
 test_id INT UNSIGNED NOT NULL,
 class_id INT UNSIGNED NOT NULL,
 student_name VARCHAR(150) NOT NULL,
 status ENUM('started','pending_review','graded','stopped') NOT NULL DEFAULT 'started',
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 submitted_at DATETIME NULL,
 graded_at DATETIME NULL,
 earned_points DECIMAL(10,2) UNSIGNED NULL,
 max_points DECIMAL(10,2) UNSIGNED NOT NULL,
 grade TINYINT UNSIGNED NULL,
 INDEX (class_id, submitted_at),
 INDEX (status, submitted_at),
 INDEX (student_id, test_id, id),
 FOREIGN KEY (student_id) REFERENCES students(id),
 FOREIGN KEY (test_id, class_id) REFERENCES test_classes(test_id, class_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attempt_answers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 attempt_id INT UNSIGNED NOT NULL,
 question_id INT UNSIGNED NOT NULL,
 written_answer TEXT NULL,
 awarded_points DECIMAL(8,2) UNSIGNED NULL,
 graded_by INT UNSIGNED NULL,
 UNIQUE (attempt_id, question_id),
 FOREIGN KEY (attempt_id) REFERENCES attempts(id),
 FOREIGN KEY (question_id) REFERENCES questions(id),
 FOREIGN KEY (graded_by) REFERENCES teachers(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attempt_answer_options (
 answer_id INT UNSIGNED NOT NULL,
 option_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (answer_id, option_id),
 FOREIGN KEY (answer_id) REFERENCES attempt_answers(id),
 FOREIGN KEY (option_id) REFERENCES question_options(id)
) ENGINE=InnoDB;

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
