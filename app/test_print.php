<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($test['title']) ?> · Школа №165</title>
<link rel="stylesheet" href="assets/print.css">
<link rel="stylesheet" href="assets/question-images.css">
</head>
<body>
<nav aria-label="Дії з документом">
    <a href="teacher.php?test=<?= $testId ?>">До тесту</a>
    <button type="button" onclick="window.print()">Друк / Зберегти як PDF</button>
    <p>У вікні друку виберіть «Зберегти як PDF».</p>
</nav>
<main>
    <p>Школа №165 · <?= $showAnswers ? 'Варіант для вчителя' : 'Варіант для учня' ?></p>
    <h1><?= e($test['title']) ?></h1>
    <?php if (!$showAnswers): ?><p>Ім’я та прізвище ________________________ Клас ______</p><?php endif ?>
    <?php foreach ($questions as $index => $question): ?>
    <section>
        <h2><?= $index + 1 ?>. <?= nl2br(e($question['prompt'])) ?></h2>
        <?php questionImage($question, 'Ілюстрація до запитання'); ?>
        <p><?= $types[$question['type']] ?> · <?= e($question['max_points']) ?> б.</p>
        <?php if ($question['options']): ?>
        <ol type="a">
            <?php foreach ($question['options'] as $option): ?>
            <li><?= e($option['label']) ?><?= $showAnswers && $option['is_correct'] ? ' — правильно' : '' ?>
            <?php questionImage($option, 'Зображення варіанта відповіді'); ?></li>
            <?php endforeach ?>
        </ol>
        <?php elseif (!$showAnswers && $question['type'] === 'written'): ?>
        <div class="answer-lines" aria-hidden="true"></div>
        <?php endif ?>
        <?php if ($showAnswers && $question['grading_guidance']): ?>
        <p><strong>Орієнтир для перевірки.</strong> <?= nl2br(e($question['grading_guidance'])) ?></p>
        <?php endif ?>
    </section>
    <?php endforeach ?>
</main>
</body>
</html>
