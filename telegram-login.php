<?php
declare(strict_types=1);
define('STUDENT_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/telegram.php';
require __DIR__ . '/app/student.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$error = '';
$miniApp = ($_GET['mini_app'] ?? '') === '1';
$keyboardLaunch = $miniApp && ($_GET['keyboard'] ?? '') === '1';
$keyboardStudent = $keyboardLaunch ? (int) ($_GET['student'] ?? 0) : 0;
$connection = null;
if ($keyboardLaunch && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $sessionStudent = !empty($_SESSION['telegram_mini_app']) ? currentStudent() : null;
        if ($sessionStudent && (!$keyboardStudent || (int) $sessionStudent['id'] === $keyboardStudent)) {
            redirect('index.php#tests');
        }
        $device = $_COOKIE['school_telegram_device'] ?? '';
        if ($keyboardStudent && is_string($device) && preg_match('/^[a-f0-9]{64}$/D', $device)) {
            $student = query("SELECT s.id, s.password_hash FROM telegram_codes c JOIN students s ON s.id = c.student_id
                JOIN telegram_registrations r ON r.student_id = s.id
                WHERE c.code_hash = ? AND c.student_id = ? AND c.purpose = 'keyboard_device'
                AND c.expires_at > NOW() AND r.stage = 'done'", [hash('sha256', $device), $keyboardStudent])->fetch();
            if ($student) {
                startTelegramStudentSession($student, true);
                redirect('index.php#tests');
            }
        }
    } catch (PDOException $exception) {
        error_log((string) $exception);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'] ?? '';
    try {
        if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            throw new DomainException('Посилання недійсне.');
        }
        if ($miniApp && (!$keyboardLaunch || !empty($_POST['init_data']))) {
            $initData = $_POST['init_data'] ?? '';
            if (!is_string($initData)) {
                throw new DomainException('Відкрий розділ через меню бота.');
            }
            $student = query("SELECT s.id, s.password_hash, r.class_id FROM telegram_registrations r
                JOIN students s ON s.id = r.student_id WHERE r.telegram_id = ? AND r.stage = 'done'",
                [telegramWebUser($initData)])->fetch();
            if (!$student) {
                throw new DomainException('Спочатку заверши реєстрацію в боті.');
            }
        } else {
            if (!is_string($code) || !preg_match('/^[a-f0-9]{64}$/D', $code)) {
                throw new DomainException('Посилання недійсне.');
            }
            $hash = hash('sha256', $code);
            $connection = db();
            $connection->beginTransaction();
            $purpose = $keyboardLaunch ? 'keyboard' : 'signin';
            $student = query("SELECT s.id, s.password_hash, r.class_id FROM telegram_codes c JOIN students s ON s.id = c.student_id
                LEFT JOIN telegram_registrations r ON r.student_id = s.id
                WHERE c.code_hash = ? AND c.purpose = ? AND c.expires_at > NOW() FOR UPDATE", [$hash, $purpose])->fetch();
            if (!$student || ($keyboardLaunch && ((int) $student['id'] !== $keyboardStudent || !$student['class_id']))) {
                throw new DomainException($keyboardLaunch ? 'Онови меню командою /start і натисни «Тести».' : 'Посилання вже використано або воно прострочене.');
            }
            query('DELETE FROM telegram_codes WHERE code_hash = ?', [$hash]);
            if ($keyboardLaunch) {
                $device = bin2hex(random_bytes(32));
                query("INSERT INTO telegram_codes VALUES (?, ?, 'keyboard_device', DATE_ADD(NOW(), INTERVAL 30 DAY))",
                    [hash('sha256', $device), $student['id']]);
            }
            $connection->commit();
            if ($keyboardLaunch) {
                setcookie('school_telegram_device', $device, [
                    'expires' => time() + 30 * 86400, 'path' => '/telegram-login.php',
                    'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
        }
        startTelegramStudentSession($student, $miniApp);
        redirect(match ($_GET['next'] ?? '') {
            'tests' => $student['class_id'] ? 'index.php?filter_class=' . $student['class_id'] . '#tests' : 'index.php#tests',
            'results' => 'student-account.php#history',
            default => 'student-account.php',
        });
    } catch (Throwable $exception) {
        if ($connection?->inTransaction()) {
            $connection->rollBack();
        }
        $error = $exception instanceof DomainException ? $exception->getMessage() : 'Не вдалося увійти.';
    }
}
function startTelegramStudentSession(array $student, bool $miniApp): void
{
    session_regenerate_id(true);
    $_SESSION = ['student_id' => $student['id'], 'student_password_version' => hash('sha256', $student['password_hash']),
        'telegram_mini_app' => $miniApp];
}

pageStart('Тести', 'Кабінет учня', !$miniApp);
?>
<main class="teacher">
<section class="panel login-panel">
<h1><?= $miniApp ? 'Тести' : 'Вхід через Telegram' ?></h1>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($keyboardLaunch && !$error): ?>
<p id="telegram-keyboard-help" hidden>Онови меню командою /start і натисни «Тести».</p>
<?php else: ?>
<p><?= $miniApp && !$error ? 'Відкриваємо розділ. Якщо він не завантажився, відкрий його кнопкою в меню бота.' : 'Щоб отримати нове посилання, натисни потрібну кнопку в меню бота.' ?></p>
<?php endif ?>
<?php if (!$error): ?>
<?php if ($miniApp): ?>
<form method="post" id="telegram-mini-signin" hidden><?= csrf() ?><input type="hidden" name="init_data"><input type="hidden" name="code"></form>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<?php else: ?>
<form method="post" id="telegram-signin" class="stack" hidden><?= csrf() ?>
<input type="hidden" name="code"><button>Увійти в кабінет</button></form>
<?php endif ?>
<script src="assets/telegram-signin.js" defer></script>
<?php endif ?>
</section></main>
</body></html>
