<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('NO_SESSION')) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $tunnelHttps = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        && ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '') === 'braeden-inkiest-insistingly.ngrok-free.dev';
    session_name(defined('STUDENT_SESSION') ? 'school_student' : 'school_teacher');
    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $https || $tunnelHttps,
    ]);
}

function db(): PDO
{
    static $connection;
    if (!$connection) {
        $connection = new PDO(
            getenv('SCHOOL_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=school;charset=utf8mb4',
            getenv('SCHOOL_DB_USER') ?: 'root',
            getenv('SCHOOL_DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $connection;
}

function e(mixed $value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function teacher(): ?array
{
    if (empty($_SESSION['teacher_id'])) {
        return null;
    }
    $account = query('SELECT id, login, display_name, role, password_hash
        FROM teachers WHERE id = ? AND is_active = 1', [$_SESSION['teacher_id']])->fetch();
    if (!$account || ($account['role'] === 'super_admin'
        && !hash_equals(hash('sha256', $account['password_hash']), $_SESSION['admin_password_version'] ?? ''))) {
        return null;
    }
    unset($account['password_hash']);
    return $account;
}

function textField(string $name, int $limit): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $limit) {
        throw new DomainException('Заповніть поле коректно. Максимальна довжина — ' . $limit . ' символів.');
    }
    return trim($value);
}

function className(array $class): string
{
    return $class['grade'] . ($class['section'] !== '' ? '-' . $class['section'] : '') . ' клас';
}
