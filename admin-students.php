<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/admin_password.php';
header('Cache-Control: no-store');

$error = '';
$notice = $_SESSION['students_notice'] ?? '';
unset($_SESSION['students_notice']);
$students = $classes = [];
$selectedStudent = null;
$studentResults = [];
$section = ($_GET['section'] ?? '') === 'merge' ? 'merge' : 'students';
$filterClass = max(-1, (int) ($_GET['class'] ?? 0));
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'name';
$orders = ['name' => 's.full_name ASC, s.login ASC', 'reverse' => 's.full_name DESC, s.login ASC',
    'class' => 'c.id IS NULL, c.grade, c.section, s.full_name, s.login'];
if (!isset($orders[$sort])) {
    $sort = 'name';
}
$listUrl = 'admin-students.php?' . http_build_query(['class' => $filterClass, 'sort' => $sort]);
$studentId = max(0, (int) ($_GET['student'] ?? 0));
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
        redirect($section === 'merge' ? 'admin-students.php?section=merge' : $listUrl);
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
        $classes = query('SELECT id, grade, section FROM classes ORDER BY grade, section')->fetchAll();
        $where = '';
        $params = [];
        if ($section === 'students' && $filterClass === -1) {
            $where = 'WHERE s.class_id IS NULL';
        } elseif ($section === 'students' && $filterClass > 0) {
            $where = 'WHERE s.class_id = ?';
            $params[] = $filterClass;
        }
        $students = query("SELECT s.id, s.login, s.full_name, s.class_id, c.grade, c.section, r.phone, r.birth_year,
            (SELECT a.grade FROM attempts a WHERE a.student_id = s.id AND a.status = 'graded'
                ORDER BY a.submitted_at DESC, a.id DESC LIMIT 1) AS latest_grade,
            (SELECT COUNT(*) FROM attempts a WHERE a.student_id = s.id) AS attempts,
            (SELECT COUNT(*) FROM attempts a WHERE a.student_id = s.id AND a.status IN ('pending_review','graded')) AS completed
            FROM students s LEFT JOIN classes c ON c.id = s.class_id
            LEFT JOIN telegram_registrations r ON r.student_id = s.id AND r.stage = 'done'
            $where ORDER BY {$orders[$sort]}", $params)->fetchAll();
        if ($section === 'students' && $studentId) {
            $selectedStudent = query("SELECT s.id, s.login, s.full_name, s.class_id, r.phone, r.birth_year,
                (SELECT COUNT(*) FROM attempts a WHERE a.student_id = s.id) AS attempts
                FROM students s LEFT JOIN telegram_registrations r ON r.student_id = s.id AND r.stage = 'done' WHERE s.id = ?",
                [$studentId])->fetch() ?: null;
            if ($selectedStudent) {
                $studentResults = query("SELECT t.title, c.grade AS class_grade, c.section, a.status,
                    a.submitted_at, a.earned_points, a.max_points, a.grade
                    FROM attempts a JOIN tests t ON t.id = a.test_id JOIN classes c ON c.id = a.class_id
                    WHERE a.student_id = ? AND a.submitted_at IS NOT NULL
                    ORDER BY a.submitted_at DESC, a.id DESC", [$studentId])->fetchAll();
            }
        }
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
            case 'class':
                $classId = filter_var($_POST['class_id'] ?? '', FILTER_VALIDATE_INT);
                $class = $classId ? query('SELECT id, grade, section FROM classes WHERE id = ?', [$classId])->fetch() : null;
                if (!$class) {
                    throw new DomainException('Оберіть клас зі списку.');
                }
                query('UPDATE students SET class_id = ? WHERE id = ?', [$classId, $source]);
                query('UPDATE telegram_registrations SET class_id = ? WHERE student_id = ?', [$classId, $source]);
                $notice = 'Клас учня змінено на ' . className($class) . '.';
                break;
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
                query('UPDATE students main JOIN students duplicate ON duplicate.id = ?
                    SET main.class_id = COALESCE(main.class_id, duplicate.class_id) WHERE main.id = ?', [$source, $target]);
                $moved = query('UPDATE attempts SET student_id = ? WHERE student_id = ?', [$target, $source])->rowCount();
                query('INSERT INTO retake_permissions (student_id, test_id)
                    SELECT ?, original.test_id FROM retake_permissions original
                    LEFT JOIN retake_permissions existing ON existing.student_id = ? AND existing.test_id = original.test_id
                    WHERE original.student_id = ? AND existing.student_id IS NULL', [$target, $target, $source]);
                query('DELETE FROM retake_permissions WHERE student_id = ?', [$source]);
                if (!query('SELECT telegram_id FROM telegram_registrations WHERE student_id = ?', [$target])->fetchColumn()) {
                    query('UPDATE telegram_registrations SET student_id = ? WHERE student_id = ?', [$target, $source]);
                }
                query('UPDATE telegram_registrations r JOIN students s ON s.id = r.student_id
                    SET r.class_id = s.class_id WHERE s.id = ?', [$target]);
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

pageStart('Керування учнями', 'Суперадміністратор', adminSection: $authorized ? $section : null);
?>
<main class="teacher teacher-wide">
<section class="hero"><div><h1>Керування учнями</h1></div></section>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="alert success" role="status"><?= e($notice) ?></p><?php endif ?>
<?php if ($authorized): ?>
<?php if ($section === 'students'): ?>
<section class="panel question-editor">
<h2>Учні <span class="badge"><?= count($students) ?></span></h2>
<form method="get" class="teacher-test-filters" action="admin-students.php">
<label>Клас<select name="class">
<option value="0">Усі класи</option><option value="-1" <?= $filterClass === -1 ? 'selected' : '' ?>>Без класу</option>
<?php foreach ($classes as $class): ?><option value="<?= e($class['id']) ?>" <?= $filterClass === (int) $class['id'] ? 'selected' : '' ?>><?= e(className($class)) ?></option><?php endforeach ?>
</select></label>
<label>Сортування<select name="sort">
<option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>За алфавітом (А–Я)</option>
<option value="reverse" <?= $sort === 'reverse' ? 'selected' : '' ?>>За алфавітом (Я–А)</option>
<option value="class" <?= $sort === 'class' ? 'selected' : '' ?>>За класом</option>
</select></label>
<button>Показати</button><a class="import-template" href="admin-students.php">Скинути</a>
</form>
<?php if (!$students): ?><p><?= $filterClass ? 'У вибраному класі немає учнів.' : 'Ще немає зареєстрованих учнів.' ?></p>
<?php else: ?>
<div class="results-scroll" tabindex="0" aria-label="Таблиця учнів">
<table class="results-table"><thead><tr><th scope="col">Учень</th><th scope="col">Клас</th><th scope="col">Логін</th><th scope="col">Телефон</th><th scope="col">Рік народження</th><th scope="col">Спроб</th><th scope="col">Завершено</th><th scope="col">Остання оцінка / 12</th><th scope="col">Дії</th></tr></thead><tbody>
<?php foreach ($students as $student): ?>
<tr><th scope="row"><?= e($student['full_name']) ?></th><td><?= $student['class_id'] ? e(className($student)) : 'Без класу' ?></td>
<td><?= e($student['login']) ?></td><td><?= e($student['phone'] ?? '—') ?></td><td><?= e($student['birth_year'] ?? '—') ?></td>
<td><?= e($student['attempts']) ?></td><td><?= e($student['completed']) ?></td>
<td><strong><?= e($student['latest_grade'] ?? '—') ?></strong></td>
<td><div class="attempt-actions"><a class="attempt-button" href="<?= e($listUrl . '&student=' . $student['id'] . '#student-settings') ?>">Керувати</a></div></td></tr>
<?php endforeach ?>
</tbody></table>
</div>
<?php endif ?>
</section>
<?php if ($selectedStudent): $student = $selectedStudent; ?>
<section class="panel question-editor" id="student-settings">
    <h2><?= e($student['full_name']) ?></h2>
    <p>Телефон <strong><?= e($student['phone'] ?? '—') ?></strong> · Рік народження <strong><?= e($student['birth_year'] ?? '—') ?></strong></p>
    <?php if ($studentResults): ?>
    <h3>Результати тестів</h3>
    <div class="results-scroll" tabindex="0" aria-label="Результати тестів учня">
    <table class="results-table">
        <thead><tr><th scope="col">Тест</th><th scope="col">Клас</th><th scope="col">Дата</th><th scope="col">Бали</th><th scope="col">Оцінка / 12</th><th scope="col">Стан</th></tr></thead>
        <tbody>
        <?php foreach ($studentResults as $result): ?>
        <tr><th scope="row"><?= e($result['title']) ?></th>
        <td><?= e(className(['grade' => $result['class_grade'], 'section' => $result['section']])) ?></td>
        <td><?= e($result['submitted_at']) ?></td>
        <td><?= e(testPoints($result)) ?></td>
        <td><strong><?= e($result['grade'] ?? '—') ?></strong></td>
        <td><?= match ($result['status']) {
            'stopped' => 'Зупинено',
            'pending_review' => 'Очікує перевірки',
            default => 'Оцінено',
        } ?></td></tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
    <form method="post" class="teacher-test-filters">
        <?= csrf() ?><input type="hidden" name="student_id" value="<?= e($student['id']) ?>">
        <label>Клас<select name="class_id" required>
        <option value="">Оберіть клас</option>
        <?php foreach ($classes as $class): ?><option value="<?= e($class['id']) ?>" <?= (int) $student['class_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= e(className($class)) ?></option><?php endforeach ?>
        </select></label>
        <button name="action" value="class">Зберегти клас</button>
    </form>
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
</section>
<?php endif ?>
<?php else: ?>
<section class="panel question-editor">
<h2>Об’єднати дублікати</h2>
<?php if (count($students) < 2): ?><p>Для об’єднання потрібні два облікові записи.</p>
<?php else: ?>
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
</form>
<?php endif ?>
</section>
<?php endif ?>
<?php endif ?>
</main>
</body></html>
