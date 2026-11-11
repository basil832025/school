<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/admin_password.php';
header('Cache-Control: no-store');

$error = '';
$notice = $_SESSION['students_notice'] ?? '';
unset($_SESSION['students_notice']);
$students = [];
$authorized = false;
try {
    $account = teacher();
    if (!$account) {
        redirect('teacher.php');
    }
    if ($account['role'] !== 'super_admin') {
        http_response_code(403);
        throw new DomainException('Керування учнями доступне суперадміністратору.');
    }
    $authorized = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null)
            || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            http_response_code(403);
            throw new DomainException('Оновіть сторінку та повторіть дію.');
        }
        $_SESSION['students_notice'] = manageStudent();
        redirect('admin-students.php');
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log((string) $exception);
    $error = $exception->getCode() === '23000'
        ? 'Логін уже зайнято або обліковий запис має пов’язані дані.'
        : 'Не вдалося зберегти зміни.';
}
if ($authorized) {
    try {
        $students = query("SELECT s.id, s.login, s.full_name, COUNT(a.id) AS attempts,
            COUNT(a.submitted_at) AS completed
            FROM students s LEFT JOIN attempts a ON a.student_id = s.id
            GROUP BY s.id, s.login, s.full_name ORDER BY s.full_name, s.login")->fetchAll();
    } catch (PDOException $exception) {
        error_log((string) $exception);
        $error = 'Не вдалося завантажити список учнів.';
    }
}

function manageStudent(): string
{
    $action = $_POST['action'] ?? '';
    $source = filter_var($_POST['student_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $target = filter_var($_POST['target_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$source || ($action === 'merge' && (!$target || $target === $source))) {
        throw new DomainException('Оберіть облікові записи учнів.');
    }
    if (in_array($action, ['delete', 'merge'], true) && ($_POST['confirm'] ?? '') !== '1') {
        throw new DomainException('Підтвердьте дію.');
    }
    db()->beginTransaction();
    try {
        $ids = $action === 'merge' ? [$source, $target] : [$source];
        $markers = implode(',', array_fill(0, count($ids), '?'));
        $locked = query("SELECT id FROM students WHERE id IN ($markers) ORDER BY id FOR UPDATE", $ids)->fetchAll();
        if (count($locked) !== count($ids)) {
            throw new DomainException('Обліковий запис учня не знайдено.');
        }
        switch ($action) {
            case 'login':
                $login = textField('login', 80);
                if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/D', $login)) {
                    throw new DomainException('Логін має містити від 3 символів. Дозволено латинські літери, цифри, крапку, дефіс і підкреслення.');
                }
                query('UPDATE students SET login = ? WHERE id = ?', [$login, $source]);
                $notice = 'Логін змінено.';
                break;
            case 'password':
                query('UPDATE students SET password_hash = ? WHERE id = ?', [adminPasswordHash(), $source]);
                query('DELETE FROM telegram_codes WHERE student_id = ?', [$source]);
                $notice = 'Пароль учня змінено.';
                break;
            case 'delete':
                if (query('SELECT id FROM attempts WHERE student_id = ? LIMIT 1 FOR UPDATE', [$source])->fetchColumn()) {
                    throw new DomainException('Цей акаунт має спроби. Об’єднайте його з основним акаунтом, щоб зберегти результати.');
                }
                query('DELETE FROM retake_permissions WHERE student_id = ?', [$source]);
                query('DELETE FROM students WHERE id = ?', [$source]);
                $notice = 'Обліковий запис без спроб видалено.';
                break;
            case 'merge':
                $moved = query('UPDATE attempts SET student_id = ? WHERE student_id = ?', [$target, $source])->rowCount();
                query('INSERT INTO retake_permissions (student_id, test_id)
                    SELECT ?, original.test_id FROM retake_permissions original
                    LEFT JOIN retake_permissions existing ON existing.student_id = ? AND existing.test_id = original.test_id
                    WHERE original.student_id = ? AND existing.student_id IS NULL', [$target, $target, $source]);
                query('DELETE FROM retake_permissions WHERE student_id = ?', [$source]);
                if (!query('SELECT telegram_id FROM telegram_registrations WHERE student_id = ?', [$target])->fetchColumn()) {
                    query('UPDATE telegram_registrations SET student_id = ? WHERE student_id = ?', [$target, $source]);
                }
                query('DELETE FROM students WHERE id = ?', [$source]);
                $notice = 'Акаунти об’єднано. Перенесено спроб: ' . $moved . '.';
                break;
            default:
                throw new DomainException('Оберіть доступну дію.');
        }
        db()->commit();
        return $notice;
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

pageStart('Керування учнями', 'Суперадміністратор');
?>
<main class="teacher">
<section class="hero"><div><h1>Керування учнями</h1></div></section>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="alert success" role="status"><?= e($notice) ?></p><?php endif ?>
<?php if ($authorized): ?>
<div class="toolbar"><a href="admin.php">До кабінету суперадміністратора</a></div>
<section class="panel question-editor">
<h2>Учні <span class="badge"><?= count($students) ?></span></h2>
<?php if (!$students): ?><p>Ще немає зареєстрованих учнів.</p><?php endif ?>
<?php foreach ($students as $student): ?>
<details class="question-preview">
    <summary><strong><?= e($student['full_name']) ?></strong> · <?= e($student['login']) ?> · спроб: <?= e($student['attempts']) ?>, завершено: <?= e($student['completed']) ?></summary>
    <div class="workspace">
        <form method="post" class="stack">
            <?= csrf() ?><input type="hidden" name="student_id" value="<?= e($student['id']) ?>">
            <label>Логін<input name="login" required minlength="3" maxlength="80" value="<?= e($student['login']) ?>"></label>
            <button name="action" value="login">Зберегти логін</button>
        </form>
        <form method="post" class="stack">
            <?= csrf() ?><input type="hidden" name="student_id" value="<?= e($student['id']) ?>">
            <label>Новий пароль<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
            <label>Повторіть пароль<input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></label>
            <button name="action" value="password">Змінити пароль</button>
        </form>
    </div>
    <?php if (!(int) $student['attempts']): ?>
    <hr>
    <form method="post" class="stack">
        <?= csrf() ?><input type="hidden" name="student_id" value="<?= e($student['id']) ?>">
        <label><input type="checkbox" name="confirm" value="1" required> Підтверджую видалення акаунта <?= e($student['login']) ?></label>
        <button class="secondary" name="action" value="delete">Видалити акаунт без спроб</button>
    </form>
    <?php endif ?>
</details>
<?php endforeach ?>
</section>
<?php if (count($students) >= 2): ?>
<section class="panel question-editor">
<h2>Об’єднати дублікати</h2>
<p>Усі спроби, відповіді, оцінки й дозволи на повторення перейдуть до основного акаунта. Після об’єднання учень входить з його логіном і паролем.</p>
<form method="post" class="stack">
    <?= csrf() ?>
    <div class="form-grid">
    <label>Дублікат, який буде видалено<select name="student_id" required>
        <option value="">Оберіть дублікат</option>
        <?php foreach ($students as $student): ?><option value="<?= e($student['id']) ?>"><?= e($student['full_name']) ?> (<?= e($student['login']) ?>)</option><?php endforeach ?>
    </select></label>
    <label>Основний акаунт, який залишиться<select name="target_id" required>
        <option value="">Оберіть основний акаунт</option>
        <?php foreach ($students as $student): ?><option value="<?= e($student['id']) ?>"><?= e($student['full_name']) ?> (<?= e($student['login']) ?>)</option><?php endforeach ?>
    </select></label>
    </div>
    <label><input type="checkbox" name="confirm" value="1" required> Підтверджую об’єднання</label>
    <button name="action" value="merge">Об’єднати акаунти</button>
</form></section>
<?php endif ?>
<?php endif ?>
</main>
</body></html>
