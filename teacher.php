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
$sections = teacherSections();
$section = is_string($_GET['section'] ?? null) && isset($sections[$_GET['section']]) ? $_GET['section'] : 'tests';
$filterClass = $filterSubject = 0;
$sort = 'newest';
$totalTests = 0;
$pageCount = $page = 1;
if ($currentTeacher) {
    try {
        $subjects = query('SELECT * FROM subjects ORDER BY name')->fetchAll();
        $classes = query('SELECT * FROM classes ORDER BY grade, section')->fetchAll();
        if (!$testId && $section === 'tests') {
            if (($_GET['reset_filters'] ?? '') === '1') {
                unset($_SESSION['teacher_test_list']);
                redirect('teacher.php');
            }
            $filters = $_SESSION['teacher_test_list'] ?? [];
            if (isset($_GET['class']) || isset($_GET['subject']) || isset($_GET['sort'])) {
                $filters = [
                    'class' => max(0, (int) ($_GET['class'] ?? 0)),
                    'subject' => max(0, (int) ($_GET['subject'] ?? 0)),
                    'sort' => ($_GET['sort'] ?? '') === 'oldest' ? 'oldest' : 'newest',
                ];
            }
            $filterClass = $filters['class'] ?? 0;
            $filterSubject = $filters['subject'] ?? 0;
            $sort = $filters['sort'] ?? 'newest';
            $where = 't.teacher_id = ?';
            $params = [$currentTeacher['id']];
            if ($filterClass) {
                $where .= ' AND EXISTS (SELECT 1 FROM test_classes tc WHERE tc.test_id = t.id AND tc.class_id = ?)';
                $params[] = $filterClass;
            }
            if ($filterSubject) {
                $where .= ' AND t.subject_id = ?';
                $params[] = $filterSubject;
            }
            $totalTests = (int) query("SELECT COUNT(*) FROM tests t WHERE $where", $params)->fetchColumn();
            $pageSize = 25;
            $pageCount = max(1, (int) ceil($totalTests / $pageSize));
            $page = min($pageCount, max(1, (int) ($_GET['page'] ?? $filters['page'] ?? 1)));
            $offset = ($page - 1) * $pageSize;
            $direction = $sort === 'oldest' ? 'ASC' : 'DESC';
            $tests = query("SELECT t.id, t.title, t.status, t.created_at, s.name AS subject_name,
                (SELECT COUNT(*) FROM questions q WHERE q.test_id = t.id) AS question_count,
                (SELECT GROUP_CONCAT(CONCAT(c.grade, IF(c.section = '', '', CONCAT('-', c.section)))
                    ORDER BY c.grade, c.section SEPARATOR ', ')
                    FROM test_classes tc JOIN classes c ON c.id = tc.class_id WHERE tc.test_id = t.id) AS class_names
                FROM tests t JOIN subjects s ON s.id = t.subject_id WHERE $where
                ORDER BY t.created_at $direction, t.id $direction LIMIT $pageSize OFFSET $offset", $params)->fetchAll();
            $_SESSION['teacher_test_list'] = ['class' => $filterClass, 'subject' => $filterSubject, 'sort' => $sort, 'page' => $page];
        }
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

pageStart('Кабінет учителя', 'Кабінет учителя', true, $currentTeacher ? ($test ? 'tests' : $section) : null);
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
<?php if (!$test): ?>
<?php if ($section === 'tests'): ?>
<section class="panel question-editor"><h2>Мої тести <span class="badge"><?= $totalTests ?></span></h2>
<form method="get" action="teacher.php" class="teacher-test-filters">
<label>Клас<select name="class"><option value="0">Усі класи</option>
<?php foreach ($classes as $class): ?><option value="<?= e($class['id']) ?>" <?= $filterClass === (int) $class['id'] ? 'selected' : '' ?>><?= e(className($class)) ?></option><?php endforeach ?>
</select></label>
<label>Предмет<select name="subject"><option value="0">Усі предмети</option>
<?php foreach ($subjects as $subject): ?><option value="<?= e($subject['id']) ?>" <?= $filterSubject === (int) $subject['id'] ? 'selected' : '' ?>><?= e($subject['name']) ?></option><?php endforeach ?>
</select></label>
<label>Дата додавання<select name="sort">
<option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Спочатку нові</option>
<option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Спочатку старі</option>
</select></label>
<button>Показати</button><a class="import-template" href="teacher.php?reset_filters=1">Скинути</a>
</form>
<?php if (!$tests): ?>
<p><?= $filterClass || $filterSubject ? 'За вибраними фільтрами тестів немає.' : 'Тестів ще немає.' ?></p>
<?php else: ?>
<div class="results-scroll" tabindex="0" aria-label="Таблиця тестів">
<table class="results-table teacher-tests-table">
<thead><tr><th scope="col">Назва</th><th scope="col">Клас</th><th scope="col">Предмет</th><th scope="col">Запитань</th><th scope="col">Стан</th><th scope="col">Додано</th><th scope="col">Дії</th></tr></thead>
<tbody>
<?php foreach ($tests as $item): ?>
<tr>
<th scope="row"><a href="teacher.php?test=<?= e($item['id']) ?>"><?= e($item['title']) ?></a></th>
<td><?= e($item['class_names'] ?? '—') ?></td>
<td><?= e($item['subject_name']) ?></td>
<td><?= e($item['question_count']) ?></td>
<td><span class="badge"><?= e($statuses[$item['status']]) ?></span></td>
<td><?= $item['created_at'] ? e(date('d.m.Y H:i', strtotime($item['created_at']))) : '—' ?></td>
<td><?php if ($item['status'] !== 'draft'): ?><a href="results.php?test=<?= e($item['id']) ?>">Результати</a><?php else: ?><a href="teacher.php?test=<?= e($item['id']) ?>">Редагувати</a><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table>
</div>
<?php if ($pageCount > 1):
    $pagination = 'teacher.php?' . http_build_query(['class' => $filterClass, 'subject' => $filterSubject, 'sort' => $sort]);
?>
<nav class="toolbar" aria-label="Сторінки списку тестів">
<?php if ($page > 1): ?><a href="<?= e($pagination . '&page=' . ($page - 1)) ?>">← Попередня</a><?php endif ?>
<span>Сторінка <?= $page ?> з <?= $pageCount ?></span>
<?php if ($page < $pageCount): ?><a href="<?= e($pagination . '&page=' . ($page + 1)) ?>">Наступна →</a><?php endif ?>
</nav>
<?php endif ?>
<?php endif ?>
</section>
<?php elseif ($section === 'new'): ?>
<section class="panel question-editor"><h2>Новий тест</h2>
<?php if (!$subjects || !$classes): ?><p class="hint">Спочатку додайте предмет і клас у відповідних вкладках.</p><?php endif ?>
<form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="create">
<?php testFields($subjects, $classes, null, []); ?>
<button>Створити чернетку →</button></form></section>
<?php elseif ($section === 'subjects'): ?>
<div class="workspace">
<section class="panel"><h2>Предмети</h2>
<?php if ($subjects): ?><ul><?php foreach ($subjects as $subject): ?><li><?= e($subject['name']) ?></li><?php endforeach ?></ul>
<?php else: ?><p>Предметів ще немає.</p><?php endif ?></section>
<section class="panel"><h2>Додати предмет</h2><form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="subject"><label>Назва предмета<input name="name" required maxlength="120" placeholder="Інформатика"></label><button>Додати предмет</button></form></section>
</div>
<?php elseif ($section === 'classes'): ?>
<div class="workspace">
<section class="panel"><h2>Класи</h2>
<?php if ($classes): ?><ul><?php foreach ($classes as $class): ?><li><?= e(className($class)) ?></li><?php endforeach ?></ul>
<?php else: ?><p>Класів ще немає.</p><?php endif ?></section>
<section class="panel"><h2>Додати клас</h2><form method="post" class="stack"><?= csrf() ?><input type="hidden" name="action" value="class"><label>Паралель<input type="number" name="grade" required min="1" max="12" value="8"></label><label>Літера класу<input name="section" maxlength="8" placeholder="А"></label><button>Додати клас</button></form></section>
</div>
<?php endif ?>
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
<hr>
<details>
    <summary>Видалити тест</summary>
    <form method="post" class="stack">
        <?= csrf() ?><input type="hidden" name="test_id" value="<?= $testId ?>">
        <p class="hint">Чернетку та всі її запитання й варіанти відповідей буде видалено остаточно.</p>
        <label><input type="checkbox" name="confirm" value="1" required> Підтверджую видалення тесту</label>
        <button class="attempt-button-delete" name="action" value="delete_test">Видалити тест</button>
    </form>
</details>
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
<form method="post" enctype="multipart/form-data" class="stack"><?= csrf() ?><input type="hidden" name="action" value="save_question"><input type="hidden" name="test_id" value="<?= $testId ?>"><input type="hidden" name="question_id" value="<?= (int) ($formQuestion['id'] ?? $formQuestion['question_id'] ?? 0) ?>">
<label>Текст запитання<textarea name="prompt" required maxlength="10000"><?= e($formQuestion['prompt'] ?? '') ?></textarea></label>
<details <?= !empty($formQuestion['image_url']) ? 'open' : '' ?>><summary>Зображення до запитання</summary>
<?php imageFields('question_image_file', 'image_url', 'image_size', $formQuestion['image_url'] ?? '', $formQuestion['image_size'] ?? 'medium'); ?>
</details>
<div class="form-grid"><label>Тип запитання<select name="type" id="question-type"><?php foreach ($types as $type => $label): ?><option value="<?= $type ?>" <?= ($formQuestion['type'] ?? 'single') === $type ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
<label>Максимум балів<input type="number" name="max_points" min="0.01" max="999999.99" step="0.01" required value="<?= e($formQuestion['max_points'] ?? '1') ?>"></label></div>
<div id="answer-options"><p class="hint">Додайте щонайменше два варіанти з текстом або зображенням і позначте правильні.</p><div id="option-rows">
<?php for ($i = 0; $i < min(30, max(4, count($formOptions))); $i++): ?>
<div class="option-row"><input type="text" name="options[<?= $i ?>]" maxlength="2000" aria-label="Варіант <?= $i + 1 ?>" placeholder="Варіант <?= $i + 1 ?>" value="<?= e($formOptions[$i] ?? '') ?>"><label><input type="checkbox" name="correct[]" value="<?= $i ?>" <?= in_array((string) $i, $formCorrect, true) ? 'checked' : '' ?>> Правильна</label>
<details class="option-image-fields" <?= !empty($formImages[$i]) ? 'open' : '' ?>><summary>Зображення до варіанта</summary>
<?php imageFields("option_image_file[$i]", "option_image_url[$i]", "option_image_size[$i]", $formImages[$i] ?? '', $formSizes[$i] ?? 'medium'); ?>
</details></div>
<?php endfor ?>
</div><button type="button" class="secondary" id="add-option">+ Додати варіант</button></div>
<label>Орієнтир для перевірки вчителем<textarea name="grading_guidance" maxlength="10000" placeholder="Очікувана відповідь або критерії оцінювання"><?= e($formQuestion['grading_guidance'] ?? '') ?></textarea></label>
<div class="toolbar"><button>Зберегти запитання</button><a href="teacher.php?test=<?= $testId ?>#question-editor">Нове запитання</a></div>
</form></section>
<section class="panel question-editor" id="question-import">
    <h2>Імпорт запитань з XML</h2>
    <p>Передайте шаблон ШІ та попросіть згенерувати запитання й відповіді в такій самій структурі. Збережіть файл у UTF-8.</p>
    <p class="hint">До 200 запитань і 1 МБ на файл. Можна додати запитання в кінець тесту або замінити всі запитання в чернетці.</p>
    <p><a class="import-template" href="assets/questions-template.xml" download>Завантажити XML-шаблон</a></p>
    <form method="post" enctype="multipart/form-data" class="stack">
        <?= csrf() ?>
        <input type="hidden" name="test_id" value="<?= $testId ?>">
        <label>XML-файл<input type="file" name="questions_xml" accept=".xml,text/xml,application/xml" required></label>
        <button name="action" value="import_questions">Додати запитання</button>
        <?php if ($questions): ?>
        <details <?= ($_POST['action'] ?? '') === 'replace_questions' ? 'open' : '' ?>>
            <summary>Замінити всі запитання</summary>
            <p class="hint">Після перевірки XML усі попередні запитання й варіанти відповідей буде замінено. Назва, предмет і класи залишаться.</p>
            <label><input type="checkbox" name="confirm_replace" value="1"> Підтверджую заміну всіх запитань</label>
            <button class="attempt-button-delete" name="action" value="replace_questions">Імпортувати із заміною</button>
        </details>
        <?php endif ?>
    </form>
</section>
<?php endif ?>
<?php endif ?>
<?php endif ?>
</main><script src="assets/teacher.js" defer></script></body></html>

<?php
function imageFields(string $fileName, string $urlName, string $sizeName, mixed $url, mixed $size): void
{
    ?>
    <div class="form-grid">
    <label>Завантажити зображення
        <input type="file" name="<?= e($fileName) ?>" accept="image/png,image/jpeg,image/webp,image/gif">
    </label>
    <label>Або посилання на зображення
        <input type="text" data-image-url name="<?= e($urlName) ?>" maxlength="2048" placeholder="https://…" value="<?= e($url) ?>">
    </label>
    <label>Розмір<select name="<?= e($sizeName) ?>">
        <?php foreach (['small' => 'Малий', 'medium' => 'Середній', 'large' => 'Великий'] as $value => $label): ?>
        <option value="<?= $value ?>" <?= $size === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach ?>
    </select></label>
    </div>
    <p class="hint">PNG, JPEG, WebP або GIF до 5 МБ. Вибраний файл замінить попереднє зображення. Щоб прибрати зображення, очистьте поле посилання.</p>
    <?php
}
