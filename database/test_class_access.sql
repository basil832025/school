SET @access_migration = IF(
 EXISTS (SELECT 1 FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_classes' AND COLUMN_NAME = 'is_active'),
 'SELECT 1',
 'ALTER TABLE test_classes ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1'
);
PREPARE access_statement FROM @access_migration;
EXECUTE access_statement;
DEALLOCATE PREPARE access_statement;
