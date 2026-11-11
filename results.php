<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/student.php';
header('Cache-Control: no-store');

$testId = (int) ($_GET['test'] ?? 0);
$attemptId = (int) ($_GET['attempt'] ?? 0);
$confirmation = $_GET['confirm'] ?? '';
$error = '';
$notice = $_SESSION['results_notice'] ?? '';
unset($_SESSION['results_notice']);
$test = $attempt = null;
$attempts = $answers = [];
$canGrantRetake = false;
$retakeGranted = false;
$score = $_POST['points'] ?? [];
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
        $answers = query("SELECT a.*, q.prompt, q.max_points, q.grading_guidance, q.type
            FROM attempt_answers a JOIN questions q ON q.id = a.question_id
            WHERE a.attempt_id = ? ORDER BY q.position", [$attemptId])->fetchAll();
        if ($attempt['student_id'] && $attempt['status'] !== 'started' && $test['status'] === 'published') {
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
        redirect('results.php?test=' . $testId . '&attempt=' . $attemptId);
    }
    if (!$attemptId) {
        $attempts = query("SELECT a.*, c.grade AS class_grade, c.section,
            TIMESTAMPDIFF(SECOND, a.started_at, COALESCE(a.submitted_at, NOW())) AS duration,
            (SELECT COUNT(*) FROM questions WHERE test_id = a.test_id) AS total_questions,
            COALESCE(progress.answered, 0) AS answered,
            COALESCE(progress.correct, 0) AS correct,
            COALESCE(progress.incorrect, 0) AS incorrect,
            COALESCE(progress.written_count, 0) AS written_count,
            a.id = (SELECT recent.id FROM attempts recent WHERE recent.student_id = a.student_id AND recent.test_id = a.test_id
                ORDER BY (recent.status = 'started') DESC, recent.id DESC LIMIT 1) AS latest,
            EXISTS(SELECT 1 FROM retake_permissions p WHERE p.student_id = a.student_id AND p.test_id = a.test_id) AS retake_allowed
            FROM attempts a JOIN classes c ON c.id = a.class_id
            LEFT JOIN (
                SELECT aa.attempt_id, COUNT(*) AS answered,
                    SUM(q.type <> 'written' AND aa.awarded_points > 0) AS correct,
                    SUM(q.type <> 'written' AND aa.awarded_points = 0) AS incorrect,
                    SUM(q.type = 'written') AS written_count
                FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id
                WHERE q.test_id = ? GROUP BY aa.attempt_id
            ) progress ON progress.attempt_id = a.id
            WHERE a.test_id = ? ORDER BY (a.status = 'started') DESC, a.started_at DESC, a.id DESC",
            [$testId, $testId])->fetchAll();
    }
    foreach ($answers as &$answer) {
        $answer['selected'] = query('SELECT o.label FROM attempt_answer_options ao JOIN question_options o ON o.id = ao.option_id
            WHERE ao.answer_id = ? ORDER BY o.position', [$answer['id']])->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($answer);
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log((string) $exception);
    $error = 'Не вдалося отримати або зберегти результати.';
}
pageStart('Результати учнів', 'Кабінет учителя', true, !empty($account) && $account['role'] === 'teacher' ? 'tests' : null);
?>
<main class="teacher teacher-wide">
<section class="hero"><div><h1>Результати учнів</h1><p><?= e($test['title'] ?? '') ?></p></div></section>
<?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="alert success" role="status"><?= e($notice) ?></p><?php endif ?>
<?php if ($test): ?>
<div class="toolbar"><a href="teacher.php?test=<?= $testId ?>">До тесту</a><a href="results.php?test=<?= $testId ?>">Усі спроби</a></div>
<?php if ($attempt): ?>
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
<div id="live-results" class="results-scroll">
<?php if (!$attempts): ?><p>Учні ще не почали тест.</p><?php else: ?>
<table class="results-table">
<thead><tr><th>Учень</th><th>Клас</th><th>Початок</th><th>Кінець</th><th>Тривалість</th><th>Відповіли</th><th>Правильно</th><th>Неправильно</th><th>Розгорнуті</th><th>Залишилось</th><th>Бали</th><th>Оцінка / 12</th><th>Стан</th><th class="actions-column">Дії</th></tr></thead>
<tbody>
<?php foreach ($attempts as $item):
    $seconds = max(0, (int) $item['duration']);
    $duration = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    $detailsLink = 'results.php?test=' . $testId . '&attempt=' . $item['id'];
    $canRetake = $item['latest'] && !$item['retake_allowed'] && $test['status'] === 'published';
?>
<tr>
<th scope="row"><a href="<?= e($detailsLink) ?>"><?= e($item['student_name']) ?></a></th>
<td><?= e(className(['grade' => $item['class_grade'], 'section' => $item['section']])) ?></td>
<td><?= e($item['started_at']) ?></td><td><?= e($item['submitted_at'] ?? '—') ?></td>
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
<script src="assets/live-results.js" defer></script>
<?php endif ?>
<?php endif ?>
</main>
</body></html>

<?php
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
