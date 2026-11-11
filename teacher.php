<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/teacher_actions.php';
header('Cache-Control: no-store');
$error = '';
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);
$currentTeacher = null;
try {
    $currentTeacher = teacher();
    if ($currentTeacher && $currentTeacher['role'] === 'super_admin') {
        redirect('admin.php');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            http_response_code(403);
            throw new DomainException('Форма застаріла. Оновіть сторінку та повторіть дію.');
        }
        if (($_POST['action'] ?? '') === 'login') {
            if (($_SESSION['login_retry_at'] ?? 0) > time()) {
                throw new DomainException('Забагато спроб входу. Спробуйте через хвилину.');
            }
            $login = textField('login', 80);
            $password = $_POST['password'] ?? '';
            $account = query('SELECT * FROM teachers WHERE login = ? AND is_active = 1', [$login])->fetch();
            if (!is_string($password) || !$account || !password_verify($password, $account['password_hash'])) {
                $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
                if ($_SESSION['login_failures'] >= 5) {
                    $_SESSION['login_retry_at'] = time() + 60;
                    $_SESSION['login_failures'] = 0;
                }
                throw new DomainException('Неправильний логін або пароль.');
            }
            session_regenerate_id(true);
            $_SESSION = ['teacher_id' => $account['id']];
            if ($account['role'] === 'super_admin') {
                $_SESSION['admin_password_version'] = hash('sha256', $account['password_hash']);
            }
            redirect($account['role'] === 'super_admin' ? 'admin.php' : 'teacher.php');
        }
        if (!$currentTeacher) {
            http_response_code(401);
            throw new DomainException('Увійдіть, щоб працювати з тестами.');
        }
        teacherAction($currentTeacher);
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log((string) $exception);
    $error = $exception->getCode() === '23000'
        ? 'Такий запис уже існує або має пов’язані дані. Перевірте введені значення.'
        : 'Не вдалося виконати дію з базою. Перевірте підключення та повторіть спробу.';
}

$test = null;
$questions = $subjects = $classes = $tests = $assigned = [];
$editingQuestion = null;
$testId = (int) ($_GET['test'] ?? $_POST['test_id'] ?? 0);
if ($currentTeacher) {
    try {
        $subjects = query('SELECT * FROM subjects ORDER BY name')->fetchAll();
        $classes = query('SELECT * FROM classes ORDER BY grade, section')->fetchAll();
        $tests = query('SELECT t.*, s.name AS subject_name,
            (SELECT COUNT(*) FROM questions q WHERE q.test_id = t.id) AS question_count
            FROM tests t JOIN subjects s ON s.id = t.subject_id WHERE t.teacher_id = ? ORDER BY t.id DESC',
            [$currentTeacher['id']])->fetchAll();
        if ($testId) {
            $test = query('SELECT * FROM tests WHERE id = ? AND teacher_id = ?', [$testId, $currentTeacher['id']])->fetch() ?: null;
            if (!$test) {
                http_response_code(404);
                $error = 'Тест не знайдено або доступ відсутній.';
            } else {
                $assigned = array_column(query('SELECT class_id FROM test_classes WHERE test_id = ?', [$testId])->fetchAll(), 'class_id');
                $questions = query('SELECT * FROM questions WHERE test_id = ? ORDER BY position', [$testId])->fetchAll();
                foreach ($questions as &$question) {
                    $question['options'] = query('SELECT * FROM question_options WHERE question_id = ? ORDER BY position', [$question['id']])->fetchAll();
                    if ((int) ($_GET['question'] ?? 0) === (int) $question['id']) {
                        $editingQuestion = $question;
                    }
                }
                unset($question);
            }
        }
    } catch (PDOException $exception) {
        error_log((string) $exception);
        $error = 'Не вдалося завантажити кабінет. Перевірте підключення до бази.';
    }
}
$statuses = ['draft' => 'Чернетка', 'published' => 'Опубліковано', 'archived' => 'В архіві'];
$types = ['single' => 'Одна правильна відповідь', 'multiple' => 'Кілька правильних відповідей', 'written' => 'Розгорнута відповідь'];

if ($test && in_array($_GET['print'] ?? '', ['student', 'teacher'], true)) {
    $showAnswers = $_GET['print'] === 'teacher';
    require __DIR__ . '/app/test_print.php';
    exit;
}

function testFields(array $subjects, array $classes, ?array $test, array $assigned): void
{
    $retry = $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create', 'save_test'], true);
    $title = $retry ? ($_POST['title'] ?? '') : ($test['title'] ?? '');
    $subjectId = $retry ? ($_POST['subject_id'] ?? '') : ($test['subject_id'] ?? '');
    $selected = $retry && is_array($_POST['classes'] ?? null) ? $_POST['classes'] : $assigned;
?>
<label>Назва тесту<input required maxlength="180" name="title" value="<?= e(is_scalar($title) ? $title : '') ?>" placeholder="Наприклад, Бази даних і СУБД"></label>
<label>Предмет<select required name="subject_id"><option value="">Оберіть предмет</option>
<?php foreach ($subjects as $subject): ?><option value="<?= $subject['id'] ?>" <?= is_scalar($subjectId) && (string) $subjectId === (string) $subject['id'] ? 'selected' : '' ?>><?= e($subject['name']) ?></option><?php endforeach ?>
</select></label>
<fieldset><legend>Доступно для класів</legend><div class="class-choices">
<?php foreach ($classes as $class): ?><label><input type="checkbox" name="classes[]" value="<?= $class['id'] ?>" <?= in_array($class['id'], $selected) ? 'checked' : '' ?>> <?= e(className($class)) ?></label><?php endforeach ?>
</div></fieldset>
<?php
}

pageStart('Кабінет учителя', 'Кабінет учителя');
?>
<main class="teacher teacher-wide">
<section class="hero"><div><p class="eyebrow">ШКОЛА №165</p><h1>Кабінет учителя</h1><p>Створюйте тести та перевіряйте знання своїх учнів.</p></div></section>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="alert success" role="status"><?= e($notice) ?></p><?php endif ?>
<?php if (!$currentTeacher): ?>
<section class="panel login-panel"><h2>Вхід для вчителя</h2>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="login">
<label>Логін<input name="login" required maxlength="80" autocomplete="username" value="<?= e(is_string($_POST['login'] ?? null) ? $_POST['login'] : '') ?>"></label>
<label>Пароль<input name="password" type="password" required autocomplete="current-password"></label>
<button>Увійти →</button></form></section>
<?php else: ?>
<div class="toolbar"><strong><?= e($currentTeacher['display_name']) ?></strong><a href="teacher.php">Усі тести</a>
<form method="post"><?= csrf() ?><button class="secondary" name="action" value="logout">Вийти</button></form></div>
<?php if (!$test): ?>
<div class="workspace">
<section class="panel"><h2>Мої тести <span class="badge"><?= count($tests) ?></span></h2>
<?php if (!$tests): ?><p class="hint">Створіть свій перший тест у формі поруч.</p><?php endif ?>
<div class="test-list">
<?php foreach ($tests as $item): ?>
<a class="test-row" href="teacher.php?test=<?= $item['id'] ?>"><div><h3><?= e($item['title']) ?></h3><p class="hint"><?= e($item['subject_name']) ?> · запитань <?= $item['question_count'] ?></p></div><span class="badge"><?= $statuses[$item['status']] ?></span></a>
<?php endforeach ?>
</div></section>
<section class="panel"><h2>Новий тест</h2>
<?php if (!$subjects || !$classes): ?><p class="hint">Спочатку додайте предмет і клас нижче.</p><?php endif ?>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="create">
<?php testFields($subjects, $classes, null, []); ?>
<button>Створити чернетку →</button></form></section>
</div>
<div class="workspace">
<section class="panel"><h2>Додати предмет</h2><form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="subject"><label>Назва предмета<input name="name" required maxlength="120" placeholder="Інформатика"></label><button>Додати предмет</button></form></section>
<section class="panel"><h2>Додати клас</h2><form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="class"><label>Паралель<input type="number" name="grade" required min="1" max="12" value="8"></label><label>Літера класу<input name="section" maxlength="8" placeholder="А"></label><button>Додати клас</button></form></section>
</div>
<?php else: ?>
<div class="toolbar"><h2><?= e($test['title']) ?></h2><span class="badge"><?= $statuses[$test['status']] ?></span></div>
<?php if ($test['status'] !== 'draft'): ?><div class="toolbar"><a href="results.php?test=<?= $testId ?>">Результати учнів</a></div><?php endif ?>
<?php if ($questions): ?>
<div class="toolbar">
    <a href="teacher.php?test=<?= $testId ?>&amp;print=student">PDF для учнів</a>
    <a href="teacher.php?test=<?= $testId ?>&amp;print=teacher">PDF із відповідями</a>
</div>
<?php endif ?>
<div class="workspace editor-layout">
<section class="panel"><h2>Налаштування тесту</h2>
<?php if ($test['status'] === 'draft'): ?>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="save_test"><input type="hidden" name="test_id" value="<?= $testId ?>">
<?php testFields($subjects, $classes, $test, $assigned); ?><button>Зберегти налаштування</button></form>
<hr><form method="post" class="stack"><?= csrf() ?><input type="hidden" name="test_id" value="<?= $testId ?>"><p class="hint">Після публікації запитання та правильні відповіді фіксуються.</p><button name="action" value="publish">Опублікувати тест →</button></form>
<?php else: ?>
<p class="hint">Запитання та правильні відповіді зафіксовано.</p>
<p>Класи
<?php foreach ($classes as $class) { if (in_array($class['id'], $assigned)) { echo e(className($class)) . ' '; } } ?></p>
<?php if ($test['status'] === 'published'): ?><form method="post"><?= csrf() ?><input type="hidden" name="test_id" value="<?= $testId ?>"><button name="action" value="archive" class="secondary">Перенести до архіву</button></form><?php endif ?>
<?php endif ?>
</section>
<section class="panel"><h2>Запитання <span class="badge"><?= count($questions) ?></span></h2>
<?php if (!$questions): ?><p class="hint">Додайте перше запитання нижче.</p><?php endif ?>
<?php foreach ($questions as $index => $question): ?>
<article class="question-preview"><h3><?= $index + 1 ?>. <?= nl2br(e($question['prompt'])) ?></h3><p class="hint"><?= $types[$question['type']] ?> · <?= e($question['max_points']) ?> балів</p>
<?php questionImage($question, 'Ілюстрація до запитання'); ?>
<?php foreach ($question['options'] as $option): ?><p class="<?= $option['is_correct'] ? 'correct-answer' : '' ?>"><?= $option['is_correct'] ? '✓ ' : '○ ' ?><?= e($option['label']) ?></p>
<?php questionImage($option, 'Зображення варіанта відповіді'); endforeach ?>
<?php if ($question['grading_guidance']): ?><p class="hint">Для перевірки — <?= nl2br(e($question['grading_guidance'])) ?></p><?php endif ?>
<?php if ($test['status'] === 'draft'): ?><div class="toolbar"><a class="text-link" href="teacher.php?test=<?= $testId ?>&question=<?= $question['id'] ?>#question-editor">Редагувати</a><form method="post"><?= csrf() ?><input type="hidden" name="test_id" value="<?= $testId ?>"><input type="hidden" name="question_id" value="<?= $question['id'] ?>"><button class="secondary" name="action" value="delete_question">Видалити</button></form></div><?php endif ?>
</article><?php endforeach ?>
</section></div>
<?php if ($test['status'] === 'draft'):
    $retry = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_question';
    $formQuestion = $retry ? $_POST : ($editingQuestion ?? []);
    $formOptions = $retry && is_array($_POST['options'] ?? null) ? $_POST['options'] : array_column($editingQuestion['options'] ?? [], 'label');
    $formImages = $retry && is_array($_POST['option_image_url'] ?? null)
        ? $_POST['option_image_url'] : array_column($editingQuestion['options'] ?? [], 'image_url');
    $formSizes = $retry && is_array($_POST['option_image_size'] ?? null)
        ? $_POST['option_image_size'] : array_column($editingQuestion['options'] ?? [], 'image_size');
    $formCorrect = $retry && is_array($_POST['correct'] ?? null)
        ? array_map('strval', array_filter($_POST['correct'], 'is_string')) : [];
    if (!$retry && $editingQuestion) {
        foreach ($editingQuestion['options'] as $index => $option) {
            if ($option['is_correct']) { $formCorrect[] = (string) $index; }
        }
    }
?>
<section class="panel question-editor" id="question-editor"><h2><?= ($formQuestion['id'] ?? $formQuestion['question_id'] ?? 0) ? 'Редагування запитання' : 'Нове запитання' ?></h2>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="save_question"><input type="hidden" name="test_id" value="<?= $testId ?>"><input type="hidden" name="question_id" value="<?= (int) ($formQuestion['id'] ?? $formQuestion['question_id'] ?? 0) ?>">
<label>Текст запитання<textarea name="prompt" required maxlength="10000"><?= e($formQuestion['prompt'] ?? '') ?></textarea></label>
<details <?= !empty($formQuestion['image_url']) ? 'open' : '' ?>><summary>Зображення до запитання</summary>
<?php imageFields('image_url', 'image_size', $formQuestion['image_url'] ?? '', $formQuestion['image_size'] ?? 'medium'); ?>
</details>
<div class="form-grid"><label>Тип запитання<select name="type" id="question-type"><?php foreach ($types as $type => $label): ?><option value="<?= $type ?>" <?= ($formQuestion['type'] ?? 'single') === $type ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
<label>Максимум балів<input type="number" name="max_points" min="0.01" max="999999.99" step="0.01" required value="<?= e($formQuestion['max_points'] ?? '1') ?>"></label></div>
<div id="answer-options"><p class="hint">Додайте щонайменше два варіанти з текстом або зображенням і позначте правильні.</p><div id="option-rows">
<?php for ($i = 0; $i < min(30, max(4, count($formOptions))); $i++): ?>
<div class="option-row"><input type="text" name="options[<?= $i ?>]" maxlength="2000" aria-label="Варіант <?= $i + 1 ?>" placeholder="Варіант <?= $i + 1 ?>" value="<?= e($formOptions[$i] ?? '') ?>"><label><input type="checkbox" name="correct[]" value="<?= $i ?>" <?= in_array((string) $i, $formCorrect, true) ? 'checked' : '' ?>> Правильна</label>
<details class="option-image-fields" <?= !empty($formImages[$i]) ? 'open' : '' ?>><summary>Зображення до варіанта</summary>
<?php imageFields("option_image_url[$i]", "option_image_size[$i]", $formImages[$i] ?? '', $formSizes[$i] ?? 'medium'); ?>
</details></div>
<?php endfor ?>
</div><button type="button" class="secondary" id="add-option">+ Додати варіант</button></div>
<label>Орієнтир для перевірки вчителем<textarea name="grading_guidance" maxlength="10000" placeholder="Очікувана відповідь або критерії оцінювання"><?= e($formQuestion['grading_guidance'] ?? '') ?></textarea></label>
<div class="toolbar"><button>Зберегти запитання</button><a href="teacher.php?test=<?= $testId ?>#question-editor">Нове запитання</a></div>
</form></section>
<section class="panel question-editor" id="question-import">
    <h2>Імпорт запитань з XML</h2>
    <p>Передайте шаблон ШІ та попросіть згенерувати запитання й відповіді в такій самій структурі. Збережіть файл у UTF-8.</p>
    <p class="hint">До 200 запитань і 1 МБ на файл. Імпорт додає запитання в кінець тесту. Повторне завантаження додасть їх ще раз.</p>
    <p><a class="import-template" href="assets/questions-template.xml" download>Завантажити XML-шаблон</a></p>
    <form method="post" enctype="multipart/form-data" class="stack">
        <?= csrf() ?>
        <input type="hidden" name="action" value="import_questions">
        <input type="hidden" name="test_id" value="<?= $testId ?>">
        <label>XML-файл<input type="file" name="questions_xml" accept=".xml,text/xml,application/xml" required></label>
        <button>Імпортувати запитання</button>
    </form>
</section>
<?php endif ?>
<?php endif ?>
<?php endif ?>
</main><script src="assets/teacher.js" defer></script></body></html>

<?php
function imageFields(string $urlName, string $sizeName, mixed $url, mixed $size): void
{
    ?>
    <div class="form-grid">
    <label>HTTPS-посилання на зображення
        <input type="url" name="<?= e($urlName) ?>" maxlength="2048" placeholder="https://…" value="<?= e($url) ?>">
    </label>
    <label>Розмір<select name="<?= e($sizeName) ?>">
        <?php foreach (['small' => 'Малий', 'medium' => 'Середній', 'large' => 'Великий'] as $value => $label): ?>
        <option value="<?= $value ?>" <?= $size === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach ?>
    </select></label>
    </div>
    <?php
}
