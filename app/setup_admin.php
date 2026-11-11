<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

try {
    $columns = query('SHOW COLUMNS FROM teachers')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('role', $columns, true)) {
        query("ALTER TABLE teachers ADD COLUMN role ENUM('teacher','super_admin') NOT NULL DEFAULT 'teacher'");
    }
    $existing = query('SELECT role FROM teachers WHERE login = ?', ['admin'])->fetchColumn();
    if ($existing === 'super_admin') {
        echo "Super administrator already exists.\n";
        exit;
    }
    if ($existing !== false) {
        fwrite(STDERR, "The login admin already belongs to a teacher.\n");
        exit(1);
    }
    query("INSERT INTO teachers (login, password_hash, display_name, role)
        VALUES (?, ?, ?, 'super_admin')",
        ['admin', password_hash('20112012', PASSWORD_DEFAULT), 'Суперадміністратор']);
    echo "Super administrator created.\n";
} catch (PDOException $exception) {
    fwrite(STDERR, "Setup failed: " . $exception->getMessage() . "\n");
    exit(1);
}
