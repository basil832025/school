SET @profile_migration = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'birth_year'),
    'SELECT 1',
    'ALTER TABLE students ADD COLUMN birth_year SMALLINT UNSIGNED NULL'
);
PREPARE profile_statement FROM @profile_migration;
EXECUTE profile_statement;
DEALLOCATE PREPARE profile_statement;

UPDATE students s
JOIN telegram_registrations r ON r.student_id = s.id AND r.stage = 'done'
SET s.birth_year = r.birth_year
WHERE s.birth_year IS NULL AND r.birth_year IS NOT NULL;
