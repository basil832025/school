<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

try {
    $admin = schoolConfig()['admin'];
    $columns = query('SHOW COLUMNS FROM teachers')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('role', $columns, true)) {
        query("ALTER TABLE teachers ADD COLUMN role ENUM('teacher','super_admin') NOT NULL DEFAULT 'teacher'");
    }
    $existing = query('SELECT role FROM teachers WHERE login = ?', [$admin['login']])->fetchColumn();
    if ($existing === 'super_admin') {
        echo "Super administrator already exists.\n";
        exit;
    }
    if ($existing !== false) {
        fwrite(STDERR, "The configured admin login already belongs to a teacher.\n");
        exit(1);
    }
    if (strlen($admin['initial_password']) < 8 || strlen($admin['initial_password']) > 72) {
        fwrite(STDERR, "Set admin.initial_password in config.php before creating the first administrator.\n");
        exit(1);
    }
    query("INSERT INTO teachers (login, password_hash, display_name, role)
        VALUES (?, ?, ?, 'super_admin')",
        [$admin['login'], password_hash($admin['initial_password'], PASSWORD_DEFAULT), 'Суперадміністратор']);
    echo "Super administrator created.\n";
} catch (PDOException $exception) {
    fwrite(STDERR, "Setup failed: " . $exception->getMessage() . "\n");
    exit(1);
}
