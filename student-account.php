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
$classes = [];
$error = '';
$miniApp = !empty($_SESSION['telegram_mini_app']);
$telegram = null;
$linkCode = $_SESSION['telegram_link_code'] ?? null;
unset($_SESSION['telegram_link_code']);
try {
    $student = currentStudent();
    if (!$student && !$miniApp) {
        $classes = query('SELECT id, grade, section FROM classes ORDER BY grade, section')->fetchAll();
    }
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
        if ($student && $action === 'complete_profile') {
            [$profileClass, $birthYear] = webStudentProfile();
            query('UPDATE students SET class_id = ?, birth_year = COALESCE(birth_year, ?) WHERE id = ? AND class_id IS NULL',
                [$profileClass, $birthYear, $student['id']]);
            redirect('index.php#tests');
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
            [$profileClass, $birthYear] = webStudentProfile();
            if (query('SELECT id FROM students WHERE login = ? LIMIT 1', [$login])->fetchColumn()) {
                throw new DomainException('Цей логін уже використовується для входу.');
            }
            if (!is_string($password) || mb_strlen($password) < 8 || strlen($password) > 72
                || trim($password) === '' || str_contains($password, "\0")) {
                throw new DomainException('Введи пароль від 8 символів, до 72 байтів.');
            }
            if ($password !== ($_POST['confirm_password'] ?? null)) {
                throw new DomainException('Паролі мають збігатися.');
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            query('INSERT INTO students (login, password_hash, full_name, class_id, birth_year) VALUES (?, ?, ?, ?, ?)',
                [$login, $passwordHash, implode(' ', studentNameParts()), $profileClass, $birthYear]);
            $id = (int) db()->lastInsertId();
        } else {
            $account = query('SELECT id, password_hash FROM students WHERE login = ?', [$login])->fetch();
            $phone = studentPhoneLogin($login);
            if (!$account && $phone !== null && $login !== $phone) {
                $account = query('SELECT id, password_hash FROM students WHERE login = ?', [$phone])->fetch();
            }
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
        redirect($testLink ?? 'index.php#tests');
    }
    if ($student) {
        if (!$student['class_id'] && !$miniApp) {
            $classes = query('SELECT id, grade, section FROM classes ORDER BY grade, section')->fetchAll();
        }
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
function webStudentProfile(): array
{
    $classId = filter_var($_POST['profile_class'] ?? '', FILTER_VALIDATE_INT);
    $year = $_POST['birth_year'] ?? '';
    if (!$classId || !query('SELECT id FROM classes WHERE id = ?', [$classId])->fetchColumn()) {
        throw new DomainException('Обери клас зі списку.');
    }
    if (!is_string($year) || !preg_match('/^[0-9]{4}$/D', $year) || (int) $year < 1900 || (int) $year > (int) date('Y')) {
        throw new DomainException('Введи дійсний рік народження чотирма цифрами.');
    }
    return [$classId, (int) $year];
}

function profileFields(array $classes): void
{
?>
<label>Клас<select name="profile_class" required><option value="">Обери клас</option>
<?php foreach ($classes as $class): ?>
<option value="<?= e($class['id']) ?>" <?= e($_POST['profile_class'] ?? '') === (string) $class['id'] ? 'selected' : '' ?>><?= e(className($class)) ?></option>
<?php endforeach ?>
</select></label>
<label>Рік народження<input name="birth_year" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required value="<?= e($_POST['birth_year'] ?? '') ?>"></label>
<?php
}

pageStart('Кабінет учня', 'Кабінет учня', !$miniApp);
?>
<main class="teacher">
<section class="hero"><div><h1>Кабінет учня</h1><?php if ($student): ?><p><?= e($student['full_name']) ?></p><?php endif ?></div></section>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<div class="toolbar"><?php if ($student && $student['class_id']): ?><a href="<?= e($testLink ?? 'index.php#tests') ?>"><?= $testLink ? 'До вибраного тесту' : 'Доступні тести' ?></a><?php endif ?>
<?php if ($student && !$miniApp): ?><form method="post"><?= csrf() ?><button class="secondary" name="action" value="logout">Вийти</button></form><?php endif ?>
</div>
<?php if (!$student && $miniApp): ?>
<p>Відкрий кабінет із меню Telegram-бота.</p>
<?php elseif (!$student): ?>
<div class="workspace">
<section class="panel"><h2>Вхід</h2>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="login">
<label>Логін або телефон<input name="login" autocomplete="username" required maxlength="80"></label>
<label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>
<button>Увійти</button></form></section>
<section class="panel"><h2>Реєстрація</h2>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="register">
<label>Прізвище<input name="surname" autocomplete="family-name" minlength="3" maxlength="75" required
    placeholder="Шевченко" value="<?= e($_POST['surname'] ?? '') ?>"></label>
<label>Ім’я<input name="first_name" autocomplete="given-name" minlength="2" maxlength="75" required
    placeholder="Олена" value="<?= e($_POST['first_name'] ?? '') ?>"></label>
<?php profileFields($classes); ?>
<label>Логін або телефон<input type="text" name="login" autocomplete="username" maxlength="80" required
    value="<?= ($_POST['action'] ?? '') === 'register' ? e($_POST['login'] ?? '') : '' ?>"></label>
<label>Пароль<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
<label>Повтори пароль<input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></label>
<button>Створити кабінет</button></form></section>
</div>
<?php else:
    $completed = array_filter($history, static fn(array $row): bool => in_array($row['status'], ['pending_review', 'graded'], true));
?>
<?php if (!$student['class_id'] && !$miniApp): ?>
<section class="panel question-editor"><h2>Доповни профіль для доступу до тестів</h2>
<form method="post" class="stack"><?= csrf() ?><?php profileFields($classes); ?>
<button name="action" value="complete_profile">Зберегти й відкрити тести</button></form></section>
<?php endif ?>
<section class="panel question-editor" id="history">
<h2>Історія тестів</h2>
<p>Пройдено тестів: <?= count(array_unique(array_column($completed, 'test_id'))) ?> · завершено спроб: <?= count($completed) ?></p>
<?php if (!$history): ?><p>Твої спроби з’являться тут після початку тесту.</p><?php endif ?>
<?php $seenTests = []; foreach ($history as $row):
    $latest = !isset($seenTests[$row['test_id']]);
    $seenTests[$row['test_id']] = true;
    $link = 'index.php?test=' . $row['test_id'] . '&class=' . $row['class_id'];
    $canRetakeInClass = empty($student['class_id']) ? !$miniApp : (int) $row['class_id'] === (int) $student['class_id'];
?>
<article class="question-preview">
<h3><?= e($row['title']) ?></h3>
<p class="hint"><?= e(className(['grade' => $row['class_grade'], 'section' => $row['section']])) ?> · Початок <?= e($row['started_at']) ?>
<?php if ($row['submitted_at']): ?> · Завершення <?= e($row['submitted_at']) ?><?php endif ?></p>
<p><?= match ($row['status']) {
    'started' => 'Розпочато',
    'stopped' => 'Зупинено вчителем' . ($row['grade'] !== null ? ' · Оцінка ' . e($row['grade']) . ' із 12' : ''),
    'pending_review' => 'Очікує перевірки вчителем',
    default => 'Оцінка ' . e($row['grade']) . ' із 12',
} ?></p>
<?php if ($row['test_status'] === 'published' && $row['status'] === 'started'): ?>
<a class="quiz-link" href="<?= e($link . '&attempt=' . $row['id'] . '#quiz') ?>">Продовжити тест</a>
<?php elseif ($row['test_status'] === 'published' && $canRetakeInClass && $latest && $row['retake_allowed']): ?>
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
