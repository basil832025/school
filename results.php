<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/student.php';
header('Cache-Control: no-store');

$testId = (int) ($_GET['test'] ?? 0);
$attemptId = (int) ($_GET['attempt'] ?? 0);
$quickReview = $attemptId > 0 && ($_GET['quick'] ?? '') === '1';
$confirmation = $_GET['confirm'] ?? '';
$error = '';
$notice = $_SESSION['results_notice'] ?? '';
unset($_SESSION['results_notice']);
$test = $attempt = null;
$attempts = $answers = [];
$canGrantRetake = false;
$retakeGranted = false;
$score = $_POST['points'] ?? [];
$sortColumns = [
    'student' => ['Учень', ['a.student_name'], 'asc'],
    'class' => ['Клас', ['c.grade', 'c.section'], 'asc'],
    'start' => ['Початок', ['a.started_at'], 'desc'],
    'end' => ['Кінець', ['a.submitted_at'], 'desc', 'a.submitted_at'],
    'duration' => ['Тривалість', ['duration'], 'desc'],
    'answered' => ['Відповіли', ['answered'], 'desc'],
    'correct' => ['Правильно', ['correct'], 'desc'],
    'incorrect' => ['Неправильно', ['incorrect'], 'desc'],
    'written' => ['Розгорнуті', ['written_count'], 'desc'],
    'remaining' => ['Залишилось', ['(total_questions - answered)'], 'desc'],
    'points' => ['Бали', ['a.earned_points'], 'desc', 'a.earned_points'],
    'grade' => ['Оцінка / 12', ['a.grade'], 'desc', 'a.grade'],
    'status' => ['Стан', ['a.status'], 'asc'],
];
$sort = is_string($_GET['sort'] ?? null) && isset($sortColumns[$_GET['sort']]) ? $_GET['sort'] : '';
$direction = $sort && in_array($_GET['direction'] ?? '', ['asc', 'desc'], true)
    ? $_GET['direction'] : ($sortColumns[$sort][2] ?? 'desc');
try {
    $account = teacher();
    if (!$account) {
        redirect('teacher.php');
    }
    $test = query('SELECT id, title, status FROM tests WHERE id = ? AND teacher_id = ?', [$testId, $account['id']])->fetch();
    if (!$test) {
        http_response_code(404);
        throw new DomainException('Тест не знайдено.');
    }
    if ($attemptId) {
        $attempt = query('SELECT * FROM attempts WHERE id = ? AND test_id = ?',
            [$attemptId, $testId])->fetch();
        if (!$attempt) {
            http_response_code(404);
            throw new DomainException('Спробу не знайдено.');
        }
        $answerFilter = $quickReview ? " AND q.type = 'written' AND q.max_points = 3" : '';
        $answers = query("SELECT a.*, q.prompt, q.max_points, q.grading_guidance, q.type, q.position, q.image_url, q.image_size
            FROM attempt_answers a JOIN questions q ON q.id = a.question_id
            WHERE a.attempt_id = ?$answerFilter ORDER BY q.position", [$attemptId])->fetchAll();
        if (!$quickReview && $attempt['student_id'] && $attempt['status'] !== 'started' && $test['status'] === 'published') {
            $latest = query("SELECT id FROM attempts WHERE student_id = ? AND test_id = ?
                ORDER BY (status = 'started') DESC, id DESC LIMIT 1",
                [$attempt['student_id'], $testId])->fetchColumn();
            $canGrantRetake = (int) $latest === $attemptId;
            $retakeGranted = (bool) query('SELECT 1 FROM retake_permissions WHERE student_id = ? AND test_id = ?',
                [$attempt['student_id'], $testId])->fetchColumn();
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null)
            || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            http_response_code(403);
            throw new DomainException('Оновіть сторінку та повторіть дію.');
        }
        $action = $_POST['action'] ?? '';
        if (in_array($action, ['stop', 'grant_retake', 'delete'], true)) {
            if (!$attempt || $confirmation !== $action) {
                throw new DomainException('Відкрийте дію для спроби та підтвердьте її.');
            }
            require_once __DIR__ . '/app/attempt_management.php';
            $_SESSION['results_notice'] = manageAttempt($attempt, $test, $action);
            redirect('results.php?test=' . $testId);
        }
        $written = array_filter($answers, static fn(array $answer): bool => $answer['type'] === 'written');
        if (!$attempt || !in_array($attempt['status'], ['pending_review', 'graded'], true) || !$written || !is_array($score)) {
            throw new DomainException('Немає розгорнутих відповідей для перевірки.');
        }
        db()->beginTransaction();
        try {
            $status = query('SELECT status FROM attempts WHERE id = ? AND test_id = ? FOR UPDATE', [$attemptId, $testId])->fetchColumn();
            if (!in_array($status, ['pending_review', 'graded'], true)) {
                throw new DomainException('Ця спроба більше недоступна для оцінювання.');
            }
            foreach ($written as $answer) {
                $points = $score[$answer['id']] ?? null;
                if (!is_string($points) || !preg_match('/^\d{1,6}(\.\d{1,2})?$/D', $points)
                    || (float) $points > (float) $answer['max_points']) {
                    throw new DomainException('Для кожної розгорнутої відповіді введіть бал від 0 до її максимуму.');
                }
                query('UPDATE attempt_answers SET awarded_points = ?, graded_by = ? WHERE id = ?',
                    [$points, $account['id'], $answer['id']]);
            }
            finalizeAttempt($attemptId);
            db()->commit();
        } catch (Throwable $exception) {
            db()->rollBack();
            throw $exception;
        }
        if ($quickReview) {
            if (($_POST['dialog'] ?? '') === '1') {
                http_response_code(204);
                exit;
            }
            $_SESSION['results_notice'] = 'Бали збережено.';
            redirect('results.php?test=' . $testId);
        }
        redirect('results.php?test=' . $testId . '&attempt=' . $attemptId);
    }
    if (!$attemptId) {
        $order = "(a.status = 'started') DESC, a.started_at DESC, a.id DESC";
        if ($sort !== '') {
            $column = $sortColumns[$sort];
            $sqlDirection = strtoupper($direction);
            $order = implode(', ', array_map(static fn(string $field): string => "$field $sqlDirection", $column[1]));
            if (isset($column[3])) {
                $order = $column[3] . ' IS NULL ASC, ' . $order;
            }
            $order .= ', a.id DESC';
        }
        $attempts = query("SELECT a.*, c.grade AS class_grade, c.section,
            TIMESTAMPDIFF(SECOND, a.started_at, COALESCE(a.submitted_at, NOW())) AS duration,
            (SELECT COUNT(*) FROM questions WHERE test_id = a.test_id) AS total_questions,
            COALESCE(progress.answered, 0) AS answered,
            COALESCE(progress.correct, 0) AS correct,
            COALESCE(progress.incorrect, 0) AS incorrect,
            COALESCE(progress.written_count, 0) AS written_count,
            COALESCE(progress.quick_count, 0) AS quick_count,
            a.id = (SELECT recent.id FROM attempts recent WHERE recent.student_id = a.student_id AND recent.test_id = a.test_id
                ORDER BY (recent.status = 'started') DESC, recent.id DESC LIMIT 1) AS latest,
            EXISTS(SELECT 1 FROM retake_permissions p WHERE p.student_id = a.student_id AND p.test_id = a.test_id) AS retake_allowed
            FROM attempts a JOIN classes c ON c.id = a.class_id
            LEFT JOIN (
                SELECT aa.attempt_id, COUNT(*) AS answered,
                    SUM(q.type <> 'written' AND aa.awarded_points > 0) AS correct,
                    SUM(q.type <> 'written' AND aa.awarded_points = 0) AS incorrect,
                    SUM(q.type = 'written') AS written_count,
                    SUM(q.type = 'written' AND q.max_points = 3) AS quick_count
                FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id
                WHERE q.test_id = ? GROUP BY aa.attempt_id
            ) progress ON progress.attempt_id = a.id
            WHERE a.test_id = ? ORDER BY $order",
            [$testId, $testId])->fetchAll();
    }
    if (!$quickReview) {
        foreach ($answers as &$answer) {
            $answer['selected'] = query('SELECT o.label FROM attempt_answer_options ao JOIN question_options o ON o.id = ao.option_id
                WHERE ao.answer_id = ? ORDER BY o.position', [$answer['id']])->fetchAll(PDO::FETCH_COLUMN);
        }
        unset($answer);
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log((string) $exception);
    $error = 'Не вдалося отримати або зберегти результати.';
}
pageStart('Результати учнів', 'Кабінет учителя', true, !empty($account) && $account['role'] === 'teacher' ? 'tests' : null);
?>
<main class="teacher <?= $attempt ? 'teacher-wide' : 'teacher-results-wide' ?>">
<section class="hero"><div><h1>Результати учнів</h1><p><?= e($test['title'] ?? '') ?></p></div></section>
<?php if ($error && !$quickReview): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="alert success" role="status"><?= e($notice) ?></p><?php endif ?>
<?php if ($test): ?>
<div class="toolbar"><a href="teacher.php?test=<?= $testId ?>">До тесту</a><a href="results.php?test=<?= $testId ?>">Усі спроби</a></div>
<?php if ($quickReview): ?>
<section class="panel question-editor" data-quick-review>
<h2><?= e($attempt['student_name'] ?? 'Швидка перевірка') ?></h2>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($answers && in_array($attempt['status'] ?? '', ['pending_review', 'graded'], true)): ?>
<form method="post" class="stack" data-quick-form action="results.php?test=<?= $testId ?>&amp;attempt=<?= $attemptId ?>&amp;quick=1">
<?= csrf() ?>
<?php foreach ($answers as $answer): ?>
<article class="question-preview">
<h3><?= e($answer['position']) ?>. <?= e($answer['prompt']) ?></h3>
<?php questionImage($answer, 'Ілюстрація до запитання'); ?>
<p class="written-review-answer"><?= nl2br(e($answer['written_answer'])) ?></p>
<?php if ($answer['grading_guidance']): ?><p class="hint"><?= nl2br(e($answer['grading_guidance'])) ?></p><?php endif ?>
<div class="quick-score">
    <label>Бал <input type="number" min="0" max="3" step="0.01" inputmode="numeric" required
        name="points[<?= e($answer['id']) ?>]" value="<?= e($score[$answer['id']] ?? $answer['awarded_points']) ?>"></label>
    <?php for ($points = 0; $points <= 3; $points++): ?>
    <button type="button" class="secondary" data-score="<?= $points ?>" aria-pressed="false"><?= $points ?></button>
    <?php endfor ?>
</div>
</article>
<?php endforeach ?>
<button>Зберегти бали</button>
</form>
<?php elseif (!$error): ?><p>У цій спробі немає розгорнутих відповідей на 3 бали для перевірки.</p><?php endif ?>
</section>
<script src="assets/quick-review.js" defer></script>
<?php elseif ($attempt): ?>
<section class="panel question-editor">
<h2><?= e($attempt['student_name']) ?></h2>
<p><?= match ($attempt['status']) {
    'started' => 'Триває',
    'stopped' => 'Зупинено вчителем',
    'pending_review' => 'Очікує перевірки',
    default => 'Оцінка ' . e($attempt['grade']) . ' із 12',
} ?></p>
<?php if ($attempt['earned_points'] !== null): ?><p>Бали за тест <strong><?= e(testPoints($attempt)) ?></strong></p><?php endif ?>
<?php
$detailsLink = 'results.php?test=' . $testId . '&attempt=' . $attemptId;
$availableActions = attemptActions($attempt['status'], $canGrantRetake && !$retakeGranted);
?>
<?php if (is_string($confirmation) && isset($availableActions[$confirmation])): ?>
<div class="alert <?= $confirmation === 'delete' ? 'error' : 'success' ?>">
<h3><?= e($availableActions[$confirmation]) ?>?</h3>
<p><?= match ($confirmation) {
    'stop' => 'Учень більше не зможе продовжити цю спробу. Введені відповіді збережуться без оцінки.',
    'grant_retake' => 'Учень отримає одну додаткову спробу. Попередні результати збережуться.',
    'delete' => 'Спробу, відповіді та оцінку буде видалено остаточно. Якщо це єдина спроба, учень зможе почати тест знову.',
} ?></p>
<form method="post" class="toolbar"><?= csrf() ?>
<button name="action" value="<?= e($confirmation) ?>">Підтвердити</button>
<a href="<?= e($detailsLink) ?>">Скасувати</a></form>
</div>
<?php else: ?>
<?php if ($retakeGranted): ?><p class="result">Дозволено одну повторну спробу.</p><?php endif ?>
<?php renderAttemptActions($detailsLink, $availableActions); ?>
<?php endif ?>
<form method="post" class="stack"><?= csrf() ?>
<?php $hasWritten = false; $canGrade = in_array($attempt['status'], ['pending_review', 'graded'], true);
foreach ($answers as $index => $answer): ?>
<article class="question-preview">
<h3><?= $index + 1 ?>. <?= e($answer['prompt']) ?></h3>
<?php if ($answer['type'] === 'written'): ?>
<p><?= nl2br(e($answer['written_answer'])) ?></p>
<?php if ($answer['grading_guidance']): ?><p class="hint">Орієнтир для перевірки — <?= nl2br(e($answer['grading_guidance'])) ?></p><?php endif ?>
<?php if ($canGrade): $hasWritten = true; ?><label>Бал (до <?= e($answer['max_points']) ?>)
<input type="number" min="0" max="<?= e($answer['max_points']) ?>" step="0.01" required name="points[<?= e($answer['id']) ?>]"
value="<?= e($score[$answer['id']] ?? $answer['awarded_points']) ?>"></label><?php endif ?>
<?php else: ?>
<p><?= e(implode(', ', $answer['selected'] ?? [])) ?></p>
<p class="hint"><?= e($answer['awarded_points']) ?> із <?= e($answer['max_points']) ?> балів</p>
<?php endif ?>
</article>
<?php endforeach ?>
<?php if ($hasWritten): ?><button>Зберегти оцінку</button><?php endif ?>
</form></section>
<?php else: ?>
<section class="panel question-editor">
<h2>Хід тестування</h2>
<p class="hint" id="live-refresh-status">Таблиця оновлюється кожні 5 секунд. Розгорнуті відповіді показано окремо від правильних і неправильних варіантів.</p>
<div id="live-results" class="results-scroll" tabindex="0" aria-label="Таблиця результатів учнів">
<?php if (!$attempts): ?><p>Учні ще не почали тест.</p><?php else: ?>
<table class="results-table">
<thead><tr>
<?php foreach ($sortColumns as $key => [$label, , $defaultDirection]):
    $active = $sort === $key;
    $nextDirection = $active ? ($direction === 'asc' ? 'desc' : 'asc') : $defaultDirection;
    $sortUrl = 'results.php?' . http_build_query(['test' => $testId, 'sort' => $key, 'direction' => $nextDirection]) . '#live-results';
?>
<th scope="col"<?= $active ? ' aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
    <a class="result-sort" href="<?= e($sortUrl) ?>"><?= e($label) ?><span aria-hidden="true"><?= $active ? ($direction === 'asc' ? '↑' : '↓') : '↕' ?></span></a>
</th>
<?php endforeach ?>
<th scope="col" class="actions-column">Дії</th>
</tr></thead>
<tbody>
<?php foreach ($attempts as $item):
    $seconds = max(0, (int) $item['duration']);
    $duration = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    $detailsLink = 'results.php?test=' . $testId . '&attempt=' . $item['id'];
    $canRetake = $item['latest'] && !$item['retake_allowed'] && $test['status'] === 'published';
?>
<tr>
<th scope="row">
<div class="result-student-name">
<?php if ($item['quick_count'] && in_array($item['status'], ['pending_review', 'graded'], true)): ?>
<a class="quick-review-link" data-quick-link href="<?= e($detailsLink . '&quick=1') ?>"
    aria-label="Швидка перевірка: <?= e($item['student_name']) ?>" title="Швидка перевірка">✎</a>
<?php endif ?>
<a href="<?= e($detailsLink) ?>"><?= e($item['student_name']) ?></a>
</div>
</th>
<td><?= e(className(['grade' => $item['class_grade'], 'section' => $item['section']])) ?></td>
<td><?php renderResultTime($item['started_at']); ?></td><td><?php renderResultTime($item['submitted_at']); ?></td>
<td><?= e($duration) ?></td><td><?= e($item['answered']) ?> / <?= e($item['total_questions']) ?></td>
<td class="correct-answer"><?= e($item['correct']) ?></td><td><?= e($item['incorrect']) ?></td>
<td><?= e($item['written_count']) ?></td><td><?= max(0, $item['total_questions'] - $item['answered']) ?></td>
<td><?= e(testPoints($item)) ?></td>
<td><strong><?= e($item['grade'] ?? '—') ?></strong></td>
<td><?= match ($item['status']) {
    'started' => 'Триває',
    'stopped' => 'Зупинено',
    'pending_review' => 'Очікує перевірки',
    default => 'Оцінено',
} ?><?php if ($item['retake_allowed'] && $item['latest']): ?><br>Повтор дозволено<?php endif ?></td>
<td class="actions-column"><?php renderAttemptActions($detailsLink, attemptActions($item['status'], $canRetake)); ?></td>
</tr>
<?php endforeach ?>
</tbody></table>
<?php endif ?>
</div></section>
<dialog id="quick-review-dialog" class="quick-review-dialog" aria-labelledby="quick-review-title">
<div class="quick-review-heading"><h2 id="quick-review-title">Швидка перевірка</h2>
<form method="dialog"><button class="secondary" aria-label="Закрити">✕</button></form></div>
<div id="quick-review-content"></div>
</dialog>
<script src="assets/quick-review.js" defer></script>
<script src="assets/live-results.js" defer></script>
<?php endif ?>
<?php endif ?>
<?php if (!$test && $quickReview): ?><p class="alert error" data-quick-review role="alert"><?= e($error) ?></p><?php endif ?>
</main>
</body></html>

<?php
function renderResultTime(?string $timestamp): void
{
    if ($timestamp === null) {
        echo '—';
        return;
    }
    [$date, $time] = explode(' ', $timestamp, 2);
    echo '<time datetime="' . e($date . 'T' . $time) . '"><span>' . e($date) . '</span> <span>' . e($time) . '</span></time>';
}

function attemptActions(string $status, bool $canRetake): array
{
    $actions = [];
    if ($status === 'started') {
        $actions['stop'] = 'Зупинити';
    } elseif ($canRetake) {
        $actions['grant_retake'] = 'Дозволити повтор';
    }
    return $actions + ['delete' => 'Видалити'];
}

function renderAttemptActions(string $link, array $actions): void
{
    echo '<div class="attempt-actions">';
    foreach ($actions as $action => $label) {
        echo '<a class="attempt-button attempt-button-' . e($action) . '" href="'
            . e($link . '&confirm=' . $action) . '">' . e($label) . '</a>';
    }
    echo '</div>';
}
