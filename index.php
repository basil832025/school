<?php
declare(strict_types=1);
define('STUDENT_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/student.php';
header('Cache-Control: no-store');

$testId = (int) ($_GET['test'] ?? 0);
$classId = (int) ($_GET['class'] ?? 0);
$filterClass = (int) ($_GET['filter_class'] ?? 0);
$filterSubject = (int) ($_GET['filter_subject'] ?? 0);
$catalog = $classes = $subjects = $questions = [];
$test = null;
$error = '';
$student = $attempt = null;
$attemptId = (int) ($_GET['attempt'] ?? 0);
$savedAnswers = [];
$autosave = ($_POST['action'] ?? '') === 'save_progress';
$miniApp = !empty($_SESSION['telegram_mini_app']);
$registeredClass = null;
try {
    $student = currentStudent();
    if (!empty($student['class_id'])) {
        $registeredClass = query('SELECT id, grade, section FROM classes WHERE id = ?', [$student['class_id']])->fetch();
    }
    $restrictedClassId = (int) ($registeredClass['id'] ?? ($miniApp ? -1 : 0));
    if ($restrictedClassId) {
        $filterClass = $restrictedClassId;
    }
    if ($attemptId && $student) {
        $attempt = query("SELECT student_name FROM attempts WHERE id = ? AND test_id = ? AND class_id = ?
            AND student_id = ? AND status = 'started'", [$attemptId, $testId, $classId, $student['id']])->fetch();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!$student) {
                if ($autosave) {
                    http_response_code(401);
                    exit;
                }
                redirect('student-account.php');
            }
            if ($restrictedClassId && $classId !== $restrictedClassId
                && (!$attempt || ($_POST['action'] ?? '') === 'start')) {
                throw new DomainException('Цей тест недоступний для твого класу.');
            }
            if (($_POST['action'] ?? '') === 'start') {
                if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null)
                    || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
                    throw new DomainException('Онови сторінку та повтори спробу.');
                }
                startTest($testId, $classId, $student);
            } else {
                saveTestAnswers($testId, $classId, $attemptId, (int) $student['id'], !$autosave);
                http_response_code(204);
                exit;
            }
        } catch (DomainException $exception) {
            if ($autosave) {
                http_response_code(409);
                exit;
            }
            $error = $exception->getMessage();
        }
    }
    $catalog = query("SELECT t.id, t.title, s.id AS subject_id, s.name AS subject_name,
        c.id AS class_id, c.grade, c.section,
        a.status AS attempt_status, a.grade AS attempt_grade, p.test_id IS NOT NULL AS retake_allowed,
        (SELECT COUNT(*) FROM questions q WHERE q.test_id = t.id) AS question_count
        FROM tests t JOIN subjects s ON s.id = t.subject_id
        JOIN test_classes tc ON tc.test_id = t.id JOIN classes c ON c.id = tc.class_id
        LEFT JOIN attempts a ON a.id = (
            SELECT recent.id FROM attempts recent WHERE recent.student_id = ? AND recent.test_id = t.id
            ORDER BY (recent.status = 'started') DESC, recent.id DESC LIMIT 1
        )
        LEFT JOIN retake_permissions p ON p.student_id = ? AND p.test_id = t.id
        WHERE t.status = 'published' AND (? = 0 OR c.id = ? OR (t.id = ? AND c.id = ?))
        ORDER BY c.grade, c.section, t.published_at DESC",
        [$student['id'] ?? null, $student['id'] ?? null, $restrictedClassId, $restrictedClassId,
            $attempt ? $testId : 0, $attempt ? $classId : 0])->fetchAll();
    foreach ($catalog as $item) {
        if (!$restrictedClassId || (int) $item['class_id'] === $restrictedClassId) {
            $classes[$item['class_id']] = className($item);
            $subjects[$item['subject_id']] = $item['subject_name'];
        }
        if ((int) $item['id'] === $testId && (int) $item['class_id'] === $classId) {
            $test = $item;
        }
    }
    if ($miniApp) {
        if (count($subjects) === 1) {
            $filterSubject = (int) array_key_first($subjects);
        } elseif (!isset($subjects[$filterSubject])) {
            $filterSubject = 0;
        }
    }
    if ($test && $attempt) {
        $questions = testQuestions($testId);
        foreach (query('SELECT aa.question_id, aa.written_answer, ao.option_id
            FROM attempt_answers aa LEFT JOIN attempt_answer_options ao ON ao.answer_id = aa.id
            WHERE aa.attempt_id = ?', [$attemptId]) as $row) {
            if ($row['option_id'] !== null) {
                $savedAnswers[$row['question_id']][] = (string) $row['option_id'];
            } else {
                $savedAnswers[$row['question_id']] = $row['written_answer'];
            }
        }
    } elseif (!$test && $testId && !$error) {
        $error = 'Цей тест зараз недоступний.';
    }
} catch (PDOException $exception) {
    error_log((string) $exception);
    if ($autosave) {
        http_response_code(500);
        exit;
    }
    $error = 'Не вдалося завантажити дані. Спробуй пізніше.';
}
function testNeedsRetakePermission(array $test): bool
{
    return in_array($test['attempt_status'], ['graded', 'pending_review', 'stopped'], true) && !$test['retake_allowed'];
}

function testActionLabel(array $test): string
{
    if ($test['attempt_status'] === 'started') {
        return 'Продовжити тест';
    }
    return $test['retake_allowed'] ? 'Почати повторну спробу' : 'Пройти тест';
}

function testResultLabel(array $test): string
{
    return match ($test['attempt_status']) {
        'graded' => 'Оцінка ' . $test['attempt_grade'] . ' із 12',
        'pending_review' => 'Очікує перевірки вчителем',
        'stopped' => 'Спробу зупинено вчителем',
        default => '',
    };
}

pageStart('Онлайн-тестування', 'Тестування учнів', !$miniApp);
?>
<?php if (!$miniApp): ?>
<div class="shell">
<nav class="side" aria-label="Навігація учня">
<a class="active" href="index.php">⌂　Головна</a>
<a href="#tests">▤　Доступні тести</a>
<a href="student-account.php">▥　Кабінет учня</a>
<?php if (telegramBotUrl() !== ''): ?><a href="<?= e(telegramBotUrl()) ?>">↗　Telegram-бот</a><?php endif ?>
<a href="#help">ⓘ　Про систему</a>
</nav>
<?php endif ?>
<main<?= $miniApp ? ' class="telegram-tests"' : '' ?>>
<?php if (!$miniApp): ?>
<section class="hero"><div class="hero-icon" aria-hidden="true">☑</div><div><p class="eyebrow">ШКІЛЬНА ПЛАТФОРМА</p><h1>Онлайн-тестування для учнів Школи №165</h1><p>Обирай предмет, перевіряй свої знання та отримуй оцінку від 1 до 12 балів.</p></div></section>
<?php endif ?>
<?php if (!$miniApp || !$testId): ?>
<div class="catalog">
<section id="tests">
<?php if ($miniApp): ?>
<h1>Тести<?= $registeredClass ? ' · ' . e(className($registeredClass)) : '' ?></h1>
<div class="toolbar telegram-catalog-nav"><a href="student-account.php#history">Кабінет учня та результати</a></div>
<?php if (count($subjects) > 1): ?>
<?php if (!$filterSubject): ?><p>Обери предмет.</p><?php endif ?>
<div class="subject-choices">
<?php foreach ($subjects as $id => $label): ?>
<a class="quiz-link<?= $filterSubject === (int) $id ? ' selected-subject' : '' ?>" href="index.php?filter_subject=<?= e($id) ?>#tests"
<?= $filterSubject === (int) $id ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
</div>
<?php endif ?>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php else: ?>
<h2>Доступні тести</h2>
<form class="filters" action="index.php#tests">
<label>Клас <select name="filter_class"><option value="">Усі класи</option>
<?php foreach ($classes as $id => $label): ?><option value="<?= $id ?>" <?= $filterClass === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
</select></label>
<label>Предмет <select name="filter_subject"><option value="">Усі предмети</option>
<?php foreach ($subjects as $id => $label): ?><option value="<?= $id ?>" <?= $filterSubject === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
</select></label><button>Показати</button>
</form>
<?php endif ?>
<?php if (!$miniApp || $filterSubject || !$subjects): ?>
<div class="cards">
<?php $shown = false; foreach ($catalog as $item):
    if (($filterClass && $filterClass !== (int) $item['class_id']) || ($filterSubject && $filterSubject !== (int) $item['subject_id'])) { continue; }
    $shown = true;
?>
<article class="card">
<?php if (!$miniApp): ?><span class="tile" aria-hidden="true">▣</span><?php endif ?>
<h3><?= e($item['title']) ?></h3><p><?php if (!$miniApp): ?><?= e(className($item)) ?> · <?php endif ?><?= e($item['subject_name']) ?> · запитань: <?= e($item['question_count']) ?></p>
<?php if (testNeedsRetakePermission($item)): ?>
<p><strong><?= e(testResultLabel($item)) ?></strong></p>
<p>Для повторної спроби потрібен дозвіл учителя.</p>
<?php else: ?>
<a class="quiz-link" href="index.php?test=<?= e($item['id']) ?>&amp;class=<?= e($item['class_id']) ?>#quiz"><?= e(testActionLabel($item)) ?> →</a>
<?php endif ?>
</article>
<?php endforeach ?>
</div>
<?php if (!$shown): ?><p><?= $miniApp && !$registeredClass ? 'Відкрий тести з меню Telegram-бота.' : ($catalog ? 'За цими фільтрами тестів немає.' : 'Поки що немає опублікованих тестів.') ?></p><?php endif ?>
<?php endif ?>
</section>
<?php if (!$miniApp): ?>
<aside class="panel help" id="help"><h2>Як це працює?</h2><ol><li>Обери клас і предмет.</li><li>Дай відповіді на запитання.</li><li>Переглянь результат. Розгорнуті відповіді перевіряє вчитель.</li></ol></aside>
<?php endif ?>
</div>
<?php endif ?>
<?php if (!$miniApp || $testId): ?>
<?php if ($miniApp): ?><div class="toolbar"><a href="index.php<?= $test ? '?filter_subject=' . e($test['subject_id']) : '' ?>#tests">← До тестів</a></div><?php endif ?>
<div class="workspace">
<section class="panel" id="quiz"><h2><?= e($test['title'] ?? 'Проходження тесту') ?></h2>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($questions): ?>
<form method="post" class="stack" id="live-quiz">
<?php csrf(); ?>
<input type="hidden" name="attempt_token" value="<?= e($_SESSION['csrf']) ?>">
<p>Учень: <?= e($attempt['student_name']) ?></p>
<p class="hint" id="progress-status" role="status"></p>
<?php foreach ($questions as $index => $question):
    $id = $question['id'];
    $answer = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['answers'][$id] ?? null) : ($savedAnswers[$id] ?? null);
?>
<fieldset data-question="<?= e($id) ?>"><legend><?= $index + 1 ?>. <?= e($question['prompt']) ?></legend>
<?php questionImage($question, 'Ілюстрація до запитання'); ?>
<?php if ($question['type'] === 'written'): ?>
<textarea name="answers[<?= e($id) ?>]" aria-label="Розгорнута відповідь" required maxlength="10000"><?= e($answer) ?></textarea>
<?php else: $multiple = $question['type'] === 'multiple'; ?>
<?php if ($multiple): ?><p class="hint">Познач усі правильні варіанти.</p><?php endif ?>
<?php foreach ($question['options'] as $option): ?>
<label><input type="<?= $multiple ? 'checkbox' : 'radio' ?>" name="answers[<?= e($id) ?>]<?= $multiple ? '[]' : '' ?>" value="<?= e($option['id']) ?>"
<?= !$multiple ? 'required' : '' ?> <?= in_array((string) $option['id'], (array) $answer, true) ? 'checked' : '' ?>>
<span><?= e($option['label']) ?><?php questionImage($option, 'Зображення варіанта відповіді'); ?></span></label>
<?php endforeach ?>
<?php endif ?>
</fieldset>
<?php endforeach ?>
<button>Завершити тест →</button>
</form>
<?php elseif ($test && $student): ?>
<?php if (testNeedsRetakePermission($test)): ?>
<p><strong><?= e(testResultLabel($test)) ?></strong></p>
<p>Для повторної спроби потрібен дозвіл учителя.</p>
<?php else: ?>
<form method="post" class="stack">
<?= csrf() ?>
<input type="hidden" name="action" value="start">
<p>Учень: <?= e($student['full_name']) ?></p>
<button><?= e(testActionLabel($test)) ?> →</button>
</form>
<?php endif ?>
<?php elseif ($test): ?>
<p>Увійди або зареєструйся, щоб почати тест і зберігати прогрес.</p>
<a class="quiz-link" href="student-account.php?test=<?= $testId ?>&amp;class=<?= $classId ?>">До кабінету учня →</a>
<?php else: ?><p>Обери тест у каталозі, щоб почати.</p><?php endif ?>
</section>
<?php if (!$miniApp): ?>
<section class="panel" id="result"><h2>Мої результати</h2>
<a class="quiz-link" href="student-account.php#history">Історія тестів →</a></section>
<?php endif ?>
</div>
<?php endif ?>
</main>
<?php if (!$miniApp): ?></div><?php endif ?>
<script src="assets/student-progress.js" defer></script>
</body></html>
