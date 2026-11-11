<?php
declare(strict_types=1);
define('STUDENT_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/student.php';
require __DIR__ . '/app/telegram.php';
header('Cache-Control: no-store');

$testId = (int) ($_GET['test'] ?? 0);
$classId = (int) ($_GET['class'] ?? 0);
$testLink = $testId > 0 && $classId > 0 ? 'index.php?test=' . $testId . '&class=' . $classId . '#quiz' : null;
$student = null;
$history = [];
$error = '';
$miniApp = !empty($_SESSION['telegram_mini_app']);
$telegram = null;
$linkCode = $_SESSION['telegram_link_code'] ?? null;
unset($_SESSION['telegram_link_code']);
try {
    $student = currentStudent();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            http_response_code(403);
            throw new DomainException('Онови сторінку та повтори спробу.');
        }
        $action = $_POST['action'] ?? '';
        if ($student && $action === 'telegram_link') {
            if (!query('SELECT telegram_id FROM telegram_registrations WHERE student_id = ?', [$student['id']])->fetchColumn()) {
                $_SESSION['telegram_link_code'] = newTelegramCode((int) $student['id'], 'link');
            }
            redirect('student-account.php#telegram');
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirect('student-account.php');
        }
        if ($student || !in_array($action, ['login', 'register'], true)) {
            redirect('student-account.php');
        }
        if (($_SESSION['student_retry_at'] ?? 0) > time()) {
            throw new DomainException('Забагато спроб входу. Спробуй через хвилину.');
        }
        $login = textField('login', 80);
        $password = $_POST['password'] ?? '';
        if ($action === 'register') {
            if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/D', $login)) {
                throw new DomainException('Логін має містити від 3 символів. Використовуй латинські літери, цифри, крапку, дефіс або підкреслення.');
            }
            if (!is_string($password) || mb_strlen($password) < 8 || strlen($password) > 72
                || trim($password) === '' || str_contains($password, "\0")) {
                throw new DomainException('Введи пароль від 8 символів, до 72 байтів.');
            }
            if ($password !== ($_POST['confirm_password'] ?? null)) {
                throw new DomainException('Паролі мають збігатися.');
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            query('INSERT INTO students (login, password_hash, full_name) VALUES (?, ?, ?)',
                [$login, $passwordHash, studentName()]);
            $id = (int) db()->lastInsertId();
        } else {
            $account = query('SELECT id, password_hash FROM students WHERE login = ?', [$login])->fetch();
            if (!is_string($password) || !$account || !password_verify($password, $account['password_hash'])) {
                $_SESSION['student_failures'] = ($_SESSION['student_failures'] ?? 0) + 1;
                if ($_SESSION['student_failures'] >= 5) {
                    $_SESSION['student_retry_at'] = time() + 60;
                    $_SESSION['student_failures'] = 0;
                }
                throw new DomainException('Неправильний логін або пароль.');
            }
            $id = (int) $account['id'];
            $passwordHash = $account['password_hash'];
        }
        session_regenerate_id(true);
        $_SESSION = ['student_id' => $id, 'student_password_version' => hash('sha256', $passwordHash)];
        redirect($testLink ?? 'student-account.php');
    }
    if ($student) {
        $telegram = query('SELECT c.id, c.grade, c.section FROM telegram_registrations r
            JOIN classes c ON c.id = r.class_id WHERE r.student_id = ?', [$student['id']])->fetch();
        $history = query("SELECT a.*, t.title, t.status AS test_status, c.grade AS class_grade, c.section,
            p.test_id IS NOT NULL AS retake_allowed
            FROM attempts a JOIN tests t ON t.id = a.test_id JOIN classes c ON c.id = a.class_id
            LEFT JOIN retake_permissions p ON p.student_id = a.student_id AND p.test_id = a.test_id
            WHERE a.student_id = ? ORDER BY a.id DESC", [$student['id']])->fetchAll();
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log((string) $exception);
    $error = $exception->getCode() === '23000' ? 'Цей логін уже зайнято.' : 'Не вдалося завантажити кабінет. Спробуй пізніше.';
}
pageStart('Кабінет учня', 'Кабінет учня', !$miniApp);
?>
<main class="teacher">
<section class="hero"><div><h1>Кабінет учня</h1><?php if ($student): ?><p><?= e($student['full_name']) ?></p><?php endif ?></div></section>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<div class="toolbar"><a href="<?= e($testLink ?? ($telegram ? 'index.php?filter_class=' . $telegram['id'] . '#tests' : 'index.php#tests')) ?>"><?= $testLink ? 'До вибраного тесту' : 'Доступні тести' ?></a>
<?php if ($student && !$miniApp): ?><form method="post"><?= csrf() ?><button class="secondary" name="action" value="logout">Вийти</button></form><?php endif ?>
</div>
<?php if (!$student && $miniApp): ?>
<p>Відкрий кабінет із меню Telegram-бота.</p>
<?php elseif (!$student): ?>
<div class="workspace">
<section class="panel"><h2>Вхід</h2>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="login">
<label>Логін<input name="login" autocomplete="username" required maxlength="80"></label>
<label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>
<button>Увійти</button></form></section>
<section class="panel"><h2>Реєстрація</h2>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="register">
<label>Прізвище, ім’я та по батькові<input name="student_name" autocomplete="name" maxlength="150" required
    placeholder="Шевченко Олена" value="<?= e($_POST['student_name'] ?? '') ?>"></label>
<p class="hint">Прізвище від 3 літер, ім’я від 2 літер. По батькові за бажанням.</p>
<label>Логін<input name="login" autocomplete="username" minlength="3" maxlength="80" required
    value="<?= ($_POST['action'] ?? '') === 'register' ? e($_POST['login'] ?? '') : '' ?>"></label>
<label>Пароль<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
<label>Повтори пароль<input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></label>
<button>Створити кабінет</button></form></section>
</div>
<?php else:
    $completed = array_filter($history, static fn(array $row): bool => in_array($row['status'], ['pending_review', 'graded'], true));
?>
<section class="panel question-editor" id="history">
<h2>Історія тестів</h2>
<p>Пройдено тестів: <?= count(array_unique(array_column($completed, 'test_id'))) ?> · завершено спроб: <?= count($completed) ?></p>
<?php if (!$history): ?><p>Твої спроби з’являться тут після початку тесту.</p><?php endif ?>
<?php $seenTests = []; foreach ($history as $row):
    $latest = !isset($seenTests[$row['test_id']]);
    $seenTests[$row['test_id']] = true;
    $link = 'index.php?test=' . $row['test_id'] . '&class=' . $row['class_id'];
    $canOpenTest = $row['test_status'] === 'published' && (!$miniApp || (int) $row['class_id'] === (int) ($telegram['id'] ?? 0));
?>
<article class="question-preview">
<h3><?= e($row['title']) ?></h3>
<p class="hint"><?= e(className(['grade' => $row['class_grade'], 'section' => $row['section']])) ?> · Початок <?= e($row['started_at']) ?>
<?php if ($row['submitted_at']): ?> · Завершення <?= e($row['submitted_at']) ?><?php endif ?></p>
<p><?= match ($row['status']) {
    'started' => 'Розпочато',
    'stopped' => 'Зупинено вчителем',
    'pending_review' => 'Очікує перевірки вчителем',
    default => 'Оцінка ' . e($row['grade']) . ' із 12',
} ?></p>
<?php if ($canOpenTest && $row['status'] === 'started'): ?>
<a class="quiz-link" href="<?= e($link . '&attempt=' . $row['id'] . '#quiz') ?>">Продовжити тест</a>
<?php elseif ($canOpenTest && $latest && $row['retake_allowed']): ?>
<a class="quiz-link" href="<?= e($link . '#quiz') ?>">Відкрити дозволену повторну спробу</a>
<?php endif ?>
</article>
<?php endforeach ?>
</section>
<?php if (!$miniApp && telegramBotUrl() !== ''): ?>
<section class="panel question-editor" id="telegram">
<h2>Telegram</h2>
<?php if ($telegram): ?><p>Кабінет прив’язано · <?= e(className($telegram)) ?></p>
<?php else: ?>
<?php if ($linkCode): ?><p>Введи <code><?= e($linkCode) ?></code> у полі «Код прив’язки» у формі реєстрації бота. Код діє 5 хвилин.</p><?php endif ?>
<form method="post" class="stack"><?= csrf() ?><button name="action" value="telegram_link">Створити код прив’язки</button></form>
<?php endif ?>
<p><a class="import-template" href="<?= e(telegramBotUrl()) ?>">Відкрити Telegram-бота</a></p>
</section>
<?php endif ?>
<?php endif ?>
</main>
</body></html>
