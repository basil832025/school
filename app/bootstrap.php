<?php
declare(strict_types=1);

function schoolConfig(): array
{
    static $config;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Missing config.php. Copy config.example.php to config.php and configure it.\n");
                exit(1);
            }
            http_response_code(503);
            exit('Налаштування сайту відсутні.');
        }
        $config = require $path;
    }
    return $config;
}

function telegramBotUrl(): string
{
    $username = schoolConfig()['telegram']['username'];
    return $username !== '' ? 'https://t.me/' . rawurlencode($username) : '';
}

if (PHP_SAPI !== 'cli' && !defined('NO_SESSION')) {
    $publicHost = strtolower((string) parse_url(schoolConfig()['telegram']['url'], PHP_URL_HOST));
    $host = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $forwardedHost = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''), PHP_URL_HOST));
    $throughTunnel = $publicHost !== '' && in_array($publicHost, [$host, $forwardedHost], true);
    $route = strtolower(basename($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($throughTunnel && in_array($route, ['admin.php', 'admin-students.php', 'teacher.php', 'results.php'], true)) {
        http_response_code(403);
        exit;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $tunnelHttps = $throughTunnel && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
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
        $database = schoolConfig()['database'];
        $connection = new PDO(
            $database['dsn'],
            $database['user'],
            $database['password'],
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
