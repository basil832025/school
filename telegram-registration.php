<?php
declare(strict_types=1);
define('STUDENT_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/telegram.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$error = '';
$registered = false;
$notified = false;
$classes = [];
$connection = null;
try {
    $classes = telegramClasses();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $initData = $_POST['init_data'] ?? '';
        if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null)
            || !hash_equals($_SESSION['csrf'], $_POST['csrf']) || !is_string($initData)) {
            throw new DomainException('Відкрий форму з Telegram-бота знову.');
        }
        $telegramId = telegramWebUser($initData);
        $connection = db();
        $connection->beginTransaction();
        $student = telegramCompleteRegistration($telegramId);
        $connection->commit();
        session_regenerate_id(true);
        $_SESSION = ['student_id' => $student['id'], 'student_password_version' => hash('sha256', $student['password_hash']),
            'telegram_mini_app' => true];
        $registered = true;
        $notified = telegramSendRegistrationSuccess($telegramId, (int) $student['id']);
    }
} catch (Throwable $exception) {
    if ($connection?->inTransaction()) {
        $connection->rollBack();
    }
    if (!$exception instanceof DomainException) {
        error_log('Telegram registration: ' . get_class($exception) . ' ' . $exception->getCode());
    }
    $error = $exception instanceof DomainException ? $exception->getMessage() : 'Не вдалося зберегти реєстрацію. Спробуй ще раз.';
}
pageStart('Реєстрація учня', 'Кабінет учня');
?>
<main class="teacher">
<section class="panel login-panel">
<h1>Реєстрація учня</h1>
<?php if ($registered): ?>
<p class="alert success" role="status" id="<?= $notified ? 'telegram-registration-complete' : 'telegram-registration-saved' ?>">Реєстрацію успішно завершено!</p>
<?php if (!$notified): ?><p>Повернись у чат і надішли /start, щоб відкрити меню.</p><?php endif ?>
<?php if (telegramBotUrl() !== ''): ?><a class="quiz-link" href="<?= e(telegramBotUrl()) ?>">Повернутися до бота</a><?php endif ?>
<?php else: ?>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<p id="telegram-registration-help"><?php if (telegramBotUrl() !== ''): ?>Відкрий цю форму кнопкою в <a class="import-template" href="<?= e(telegramBotUrl()) ?>">Telegram-боті</a>.<?php else: ?>Telegram-бот ще не налаштований.<?php endif ?></p>
<div id="telegram-registration" hidden>
<?php if (!$classes): ?><p>Класів ще немає. Звернись до вчителя.</p><?php else: ?>
<form method="post" class="stack">
<?= csrf() ?><input type="hidden" name="init_data">
<label>Прізвище<input name="surname" autocomplete="family-name" minlength="3" maxlength="75" required value="<?= e($_POST['surname'] ?? '') ?>"></label>
<label>Ім’я<input name="first_name" autocomplete="given-name" minlength="2" maxlength="75" required value="<?= e($_POST['first_name'] ?? '') ?>"></label>
<label>Клас<select name="class_id" required>
<option value="">Обери клас</option>
<?php foreach ($classes as $class): ?>
<option value="<?= e($class['id']) ?>" <?= e($_POST['class_id'] ?? '') === (string) $class['id'] ? 'selected' : '' ?>><?= e(className($class)) ?></option>
<?php endforeach ?>
</select></label>
<label>Рік народження<input name="birth_year" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="2014" required value="<?= e($_POST['birth_year'] ?? '') ?>"></label>
<details>
    <summary>У мене вже є кабінет учня</summary>
    <p class="hint">Створи код у розділі Telegram свого кабінету та введи його тут, щоб зберегти історію тестів.</p>
    <label>Код прив’язки<input name="link_code" autocomplete="off" maxlength="16"></label>
</details>
<button>Зберегти й відкрити кабінет</button>
</form>
<?php endif ?>
</div>
<?php endif ?>
</section></main>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script src="assets/telegram-registration.js"></script>
</body></html>
