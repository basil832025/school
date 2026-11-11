<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/admin_password.php';
header('Cache-Control: no-store');

$error = '';
$notice = $_SESSION['admin_notice'] ?? '';
unset($_SESSION['admin_notice']);
$account = null;
$teachers = [];
try {
    $account = teacher();
    if (!$account) {
        redirect('teacher.php');
    }
    if ($account['role'] !== 'super_admin') {
        http_response_code(403);
        throw new DomainException('Ця сторінка доступна лише суперадміністратору.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null)
            || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            http_response_code(403);
            throw new DomainException('Форма застаріла. Оновіть сторінку та повторіть дію.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirect('teacher.php');
        }
        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $currentHash = query('SELECT password_hash FROM teachers WHERE id = ?', [$account['id']])->fetchColumn();
            if (!is_string($currentPassword) || !$currentHash
                || !hash_equals($_SESSION['admin_password_version'], hash('sha256', $currentHash))
                || !password_verify($currentPassword, $currentHash)) {
                throw new DomainException('Поточний пароль неправильний.');
            }
            $hash = adminPasswordHash();
            $updated = query('UPDATE teachers SET password_hash = ?
                WHERE id = ? AND role = ? AND is_active = TRUE AND BINARY password_hash = ?',
                [$hash, $account['id'], 'super_admin', $currentHash])->rowCount();
            if (!$updated) {
                $_SESSION = [];
                redirect('teacher.php');
            }
            session_regenerate_id(true);
            unset($_SESSION['csrf']);
            $_SESSION['admin_password_version'] = hash('sha256', $hash);
            $_SESSION['admin_notice'] = 'Пароль суперадміністратора змінено.';
            redirect('admin.php');
        }
        if ($action === 'create_teacher') {
            $login = textField('login', 80);
            if (!preg_match('/^[a-zA-Z0-9._-]{3,80}$/D', $login)) {
                throw new DomainException('Логін має містити від 3 до 80 латинських літер, цифр, крапок, дефісів або підкреслень.');
            }
            query("INSERT INTO teachers (login, password_hash, display_name, role) VALUES (?, ?, ?, 'teacher')",
                [$login, adminPasswordHash(), textField('display_name', 120)]);
            $_SESSION['admin_notice'] = 'Обліковий запис учителя створено.';
            redirect('admin.php');
        }
        if ($action === 'reset_teacher_password') {
            $teacherId = filter_var($_POST['teacher_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$teacherId || $teacherId < 1) {
                throw new DomainException('Оберіть учителя.');
            }
            $updated = query("UPDATE teachers SET password_hash = ? WHERE id = ? AND role = 'teacher'",
                [adminPasswordHash(), $teacherId])->rowCount();
            if (!$updated) {
                throw new DomainException('Учителя не знайдено.');
            }
            $_SESSION['admin_notice'] = 'Пароль учителя змінено.';
            redirect('admin.php');
        }
        throw new DomainException('Оберіть доступну дію.');
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log((string) $exception);
    $error = $exception->getCode() === '23000'
        ? 'Цей логін уже зайнято.'
        : 'Не вдалося виконати дію з базою.';
}

$isAdmin = ($account['role'] ?? '') === 'super_admin';
if ($isAdmin) {
    try {
        $teachers = query("SELECT id, login, display_name, is_active FROM teachers WHERE role = 'teacher' ORDER BY display_name, login")->fetchAll();
    } catch (PDOException $exception) {
        error_log((string) $exception);
        $error = 'Не вдалося завантажити список учителів.';
    }
}

pageStart('Суперадміністратор', 'Суперадміністратор');
?>
<main class="teacher">
<section class="hero"><div><h1>Кабінет суперадміністратора</h1></div></section>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="alert success" role="status"><?= e($notice) ?></p><?php endif ?>
<?php if ($isAdmin): ?>
<div class="toolbar">
    <strong><?= e($account['display_name']) ?></strong>
    <a href="admin-students.php">Керування учнями</a>
    <form method="post"><?= csrf() ?><button class="secondary" name="action" value="logout">Вийти</button></form>
</div>
<div class="workspace">
    <section class="panel">
        <h2>Учителі</h2>
        <?php if (!$teachers): ?><p class="hint">Додайте першого вчителя.</p><?php endif ?>
        <?php foreach ($teachers as $teacher): ?>
        <div class="test-row">
            <div><h3><?= e($teacher['display_name']) ?></h3><p class="hint"><?= e($teacher['login']) ?></p></div>
            <span class="badge"><?= $teacher['is_active'] ? 'Активний' : 'Неактивний' ?></span>
            <details style="width: 100%" <?= ($_POST['action'] ?? '') === 'reset_teacher_password'
                && e($_POST['teacher_id'] ?? '') === (string) $teacher['id'] ? 'open' : '' ?>>
                <summary>Змінити пароль учителя</summary>
                <form method="post" class="stack">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="reset_teacher_password">
                    <input type="hidden" name="teacher_id" value="<?= e($teacher['id']) ?>">
                    <label>Новий пароль<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
                    <label>Повторіть новий пароль<input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></label>
                    <button>Зберегти пароль</button>
                </form>
            </details>
        </div>
        <?php endforeach ?>
    </section>
    <section class="panel">
        <h2>Додати вчителя</h2>
        <form method="post" class="stack">
            <?= csrf() ?>
            <input type="hidden" name="action" value="create_teacher">
            <label>Ім’я вчителя<input name="display_name" maxlength="120" required value="<?= e($_POST['display_name'] ?? '') ?>"></label>
            <label>Логін<input name="login" autocomplete="off" minlength="3" maxlength="80" required value="<?= e($_POST['login'] ?? '') ?>"></label>
            <label>Пароль<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
            <label>Повторіть пароль<input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></label>
            <button>Створити обліковий запис</button>
        </form>
    </section>
</div>
<details class="panel login-panel" <?= ($_POST['action'] ?? '') === 'change_password' ? 'open' : '' ?>>
    <summary>Змінити пароль суперадміністратора</summary>
    <form method="post" class="stack">
        <?= csrf() ?>
        <input type="hidden" name="action" value="change_password">
        <label>Поточний пароль<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>Новий пароль<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
        <label>Повторіть новий пароль<input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></label>
        <button>Зберегти пароль</button>
    </form>
</details>
<?php endif ?>
</main>
</body></html>
