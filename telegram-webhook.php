<?php
declare(strict_types=1);
define('NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/telegram.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
if (!hash_equals(telegramConfig()['secret'], $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
    http_response_code(403);
    exit;
}
$update = json_decode(file_get_contents('php://input', false, null, 0, 65536), true);
$message = $update['message'] ?? [];
$id = $message['from']['id'] ?? null;
$updateId = $update['update_id'] ?? null;
if (!is_int($id) || $id <= 0 || !is_int($updateId) || $updateId < 0 || ($message['chat']['type'] ?? '') !== 'private'
    || ($message['chat']['id'] ?? null) !== $id) {
    http_response_code(204);
    exit;
}
try {
    db()->beginTransaction();
    query("INSERT INTO telegram_registrations (telegram_id, last_update, stage) VALUES (?, 0, 'phone')
        ON DUPLICATE KEY UPDATE telegram_id = telegram_registrations.telegram_id", [$id]);
    $profile = query('SELECT * FROM telegram_registrations WHERE telegram_id = ? FOR UPDATE', [$id])->fetch();
    if ((int) $profile['last_update'] === $updateId && $updateId !== 0) {
        db()->commit();
        http_response_code(204);
        exit;
    }
    $reply = telegramRegistration($profile, $message);
    query('UPDATE telegram_registrations SET last_update = ? WHERE telegram_id = ?', [$updateId, $id]);
    db()->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['method' => 'sendMessage', 'chat_id' => $id] + $reply, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log('Telegram webhook: ' . get_class($error) . ' ' . $error->getCode());
    http_response_code(500);
}
