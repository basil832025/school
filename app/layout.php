<?php
declare(strict_types=1);

function questionImage(array $item, string $alt): void
{
    if (empty($item['image_url'])) {
        return;
    }
    $size = $item['image_size'] ?? 'medium';
    ?>
    <img class="question-image question-image-<?= e($size) ?>" src="<?= e($item['image_url']) ?>"
         alt="<?= e($alt) ?>" referrerpolicy="no-referrer">
    <?php
}

function pageStart(string $title, string $section, bool $showHeader = true): void
{
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> · Школа №165</title>
<link rel="stylesheet" href="assets/site.css">
<link rel="stylesheet" href="assets/question-images.css">
<script src="assets/form-validation-uk.js" defer></script>
</head>
<body>
<?php if ($showHeader): ?>
<header class="top"><a class="brand" href="index.php">▤ <strong>Школа №165</strong></a><span><?= htmlspecialchars($section) ?></span><a class="role" href="<?= $section === 'Кабінет учителя' ? 'index.php' : 'teacher.php' ?>"><?= $section === 'Кабінет учителя' ? 'До тестів' : 'Кабінет учителя' ?></a></header>
<?php endif ?>
<?php
}
