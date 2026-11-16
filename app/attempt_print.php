<?php
declare(strict_types=1);

$duration = '—';
if ($attempt['duration'] !== null) {
    $seconds = max(0, (int) $attempt['duration']);
    $duration = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}
$statusLabel = match ($attempt['status']) {
    'started' => 'Триває',
    'stopped' => 'Зупинено вчителем',
    'pending_review' => 'Очікує перевірки',
    default => 'Оцінено',
};
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($attempt['student_name'] . ' · ' . $test['title']) ?></title>
<link rel="stylesheet" href="assets/print.css">
<link rel="stylesheet" href="assets/question-images.css">
</head>
<body class="attempt-report">
<nav aria-label="Дії зі звітом">
    <a href="results.php?test=<?= $testId ?>&amp;attempt=<?= $attemptId ?>">До спроби</a>
    <button type="button" onclick="window.print()">Друк / Зберегти як PDF</button>
</nav>
<main>
<p>Школа №165 · Звіт про проходження тесту</p>
<h1><?= e($test['title']) ?></h1>
<p><strong>Учень:</strong> <?= e($attempt['student_name']) ?> · <?= e(className(['grade' => $attempt['class_grade'], 'section' => $attempt['section']])) ?></p>
<?php foreach ($reportQuestions as $index => $question):
    $answer = $reportAnswers[$question['id']] ?? null;
    $written = $question['type'] === 'written';
    $awarded = $answer['awarded_points'] ?? null;
    $points = $answer ? $awarded : ($attempt['status'] === 'started' ? null : 0);
    $maximum = (float) $question['max_points'];
    $verdict = match (true) {
        !$answer => 'Без відповіді',
        $awarded === null => 'Очікує перевірки',
        (float) $awarded === $maximum => 'Правильно',
        (float) $awarded === 0.0 => 'Неправильно',
        default => 'Частково правильно',
    };
?>
<section class="attempt-report-question">
    <h2><?= $index + 1 ?>. <?= nl2br(e($question['prompt'])) ?></h2>
    <p><strong><?= e($verdict) ?></strong> · Бали <?= e($points === null ? '— / ' . $question['max_points'] : testPoints(['earned_points' => $points, 'max_points' => $maximum])) ?></p>
    <?php questionImage($question, 'Ілюстрація до запитання'); ?>
    <?php if ($written): ?>
        <p><strong>Відповідь учня:</strong> <?= $answer ? nl2br(e($answer['written_answer'])) : '—' ?></p>
        <?php if ($question['grading_guidance']): ?><p><strong>Орієнтир для перевірки:</strong> <?= nl2br(e($question['grading_guidance'])) ?></p><?php endif ?>
    <?php else: ?>
        <ol type="a">
        <?php foreach ($question['options'] as $option):
            $selected = isset($selectedOptions[$question['id']][(int) $option['id']]);
        ?>
            <li><?= e($option['label']) ?>
                <?php if ($option['is_correct']): ?><strong> · Правильний варіант</strong><?php endif ?>
                <?php if ($selected): ?> · Обрано учнем<?php endif ?>
                <?php questionImage($option, 'Зображення варіанта відповіді'); ?>
            </li>
        <?php endforeach ?>
        </ol>
    <?php endif ?>
</section>
<?php endforeach ?>
<section class="attempt-report-summary">
    <h2>Підсумок</h2>
    <p><strong>Стан:</strong> <?= e($statusLabel) ?></p>
    <p><strong>Набрано балів:</strong> <?= e($attempt['earned_points'] === null ? '— / ' . $attempt['max_points'] : testPoints($attempt)) ?></p>
    <p><strong>Оцінка:</strong> <?= $attempt['grade'] !== null ? e($attempt['grade'] . ' із 12') : 'Ще не виставлено' ?></p>
    <p><strong>Початок:</strong> <?= e($attempt['started_at']) ?></p>
    <p><strong>Завершення або зупинка:</strong> <?= e($attempt['submitted_at'] ?? '—') ?></p>
    <p><strong>Тривалість:</strong> <?= e($duration) ?></p>
</section>
</main>
</body>
</html>
