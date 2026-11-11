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

function teacherSections(): array
{
    return ['tests' => 'Мої тести', 'new' => 'Новий тест', 'classes' => 'Класи', 'subjects' => 'Предмети'];
}

function pageStart(string $title, string $section, bool $showHeader = true, ?string $teacherSection = null, ?string $adminSection = null): void
{
    $adminLinks = [
        'teachers' => ['admin.php', 'Вчителі'],
        'students' => ['admin-students.php', 'Учні'],
        'merge' => ['admin-students.php?section=merge', 'Дублікати'],
    ];
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
<?php if ($showHeader && $adminSection !== null): ?>
<header class="top teacher-top">
    <a class="brand" href="admin.php">▤ <strong>Школа №165</strong></a>
    <nav class="teacher-tabs" aria-label="Розділи суперадміністратора">
    <?php foreach ($adminLinks as $key => [$url, $label]): ?>
        <a href="<?= e($url) ?>" <?= $key === $adminSection ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
    </nav>
    <div class="teacher-top-actions">
        <a class="role" href="index.php">До сайту</a>
        <form method="post" action="admin.php"><?= csrf() ?><button class="role" name="action" value="logout">Вийти</button></form>
    </div>
</header>
<?php elseif ($showHeader && $teacherSection !== null): ?>
<header class="top teacher-top">
    <a class="brand" href="teacher.php">▤ <strong>Школа №165</strong></a>
    <nav class="teacher-tabs" aria-label="Розділи кабінету вчителя">
    <?php foreach (teacherSections() as $key => $label): ?>
        <a href="teacher.php<?= $key === 'tests' ? '' : '?section=' . $key ?>"
            <?= $key === $teacherSection ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
    </nav>
    <div class="teacher-top-actions">
        <a class="role" href="index.php">До сайту</a>
        <form method="post" action="teacher.php"><?= csrf() ?><button class="role" name="action" value="logout">Вийти</button></form>
    </div>
</header>
<?php elseif ($showHeader): ?>
<header class="top"><a class="brand" href="index.php">▤ <strong>Школа №165</strong></a><span><?= htmlspecialchars($section) ?></span><a class="role" href="<?= $section === 'Кабінет учителя' ? 'index.php' : 'teacher.php' ?>"><?= $section === 'Кабінет учителя' ? 'До тестів' : 'Кабінет учителя' ?></a></header>
<?php endif ?>
<?php
}
