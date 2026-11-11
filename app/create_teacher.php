<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

if ($argc !== 3 || !preg_match('/^[a-zA-Z0-9._-]{3,80}$/D', $argv[1])
    || trim($argv[2]) === '' || mb_strlen(trim($argv[2])) > 120) {
    fwrite(STDERR, "Usage: php app/create_teacher.php <login> <display-name>\n");
    exit(1);
}

$password = bin2hex(random_bytes(12));
try {
    query('INSERT INTO teachers (login, password_hash, display_name) VALUES (?, ?, ?)',
        [$argv[1], password_hash($password, PASSWORD_DEFAULT), trim($argv[2])]);
} catch (PDOException $exception) {
    fwrite(STDERR, $exception->getCode() === '23000'
        ? "This login already exists.\n"
        : "Could not create the teacher. Check the database settings and schema.\n");
    exit(1);
}
echo "Login: {$argv[1]}\nPassword: $password\n";
