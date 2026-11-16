<?php
declare(strict_types=1);

$teacherId = $currentTeacher['id'];
$classFilter = max(0, (int) ($_GET['class'] ?? 0));
$subjectFilter = max(0, (int) ($_GET['subject'] ?? 0));
$studentId = max(0, (int) ($_GET['student'] ?? 0));
$studentSort = is_string($_GET['order'] ?? null) ? $_GET['order'] : '';
$order = match ($studentSort) {
    'class' => 'c.grade IS NULL, c.grade, c.section, s.full_name, s.id',
    'latest' => 'activity.last_attempt IS NULL, activity.last_attempt DESC, s.full_name, s.id',
    default => 's.full_name, s.id',
};
$oldest = ($_GET['date'] ?? '') === 'oldest';
$listUrl = 'teacher.php?' . http_build_query(['section' => 'students', 'class' => $classFilter,
    'subject' => $subjectFilter, 'order' => $studentSort]);
$students = $history = [];
$profile = null;
try {
    if ($studentId) {
        $profile = query("SELECT s.full_name FROM students s WHERE s.id = ? AND (
            EXISTS (SELECT 1 FROM test_classes tc JOIN tests t ON t.id = tc.test_id
                WHERE t.teacher_id = ? AND tc.class_id = s.class_id)
            OR EXISTS (SELECT 1 FROM attempts a JOIN tests t ON t.id = a.test_id
                WHERE t.teacher_id = ? AND a.student_id = s.id))", [$studentId, $teacherId, $teacherId])->fetch();
        if ($profile) {
            $direction = $oldest ? 'ASC' : 'DESC';
            $history = query("SELECT a.*, t.title, subj.name AS subject_name, c.grade AS class_grade, c.section,
                EXISTS (SELECT 1 FROM attempt_answers aa JOIN questions q ON q.id = aa.question_id
                    WHERE aa.attempt_id = a.id AND q.type = 'written' AND q.max_points = 3) AS quick_review
                FROM attempts a JOIN tests t ON t.id = a.test_id
                JOIN subjects subj ON subj.id = t.subject_id JOIN classes c ON c.id = a.class_id
                WHERE a.student_id = ? AND t.teacher_id = ?
                ORDER BY COALESCE(a.submitted_at, a.started_at) $direction, a.id $direction",
                [$studentId, $teacherId])->fetchAll();
        }
    } else {
        $students = query("SELECT s.id, s.full_name, c.grade, c.section, activity.total, activity.last_attempt
            FROM students s LEFT JOIN classes c ON c.id = s.class_id
            LEFT JOIN (
                SELECT a.student_id, COUNT(*) AS total, MAX(COALESCE(a.submitted_at, a.started_at)) AS last_attempt
                FROM attempts a JOIN tests t ON t.id = a.test_id
                WHERE t.teacher_id = ? AND (? = 0 OR t.subject_id = ?) GROUP BY a.student_id
            ) activity ON activity.student_id = s.id
            WHERE (? = 0 OR s.class_id = ?) AND (
                activity.total IS NOT NULL OR EXISTS (
                    SELECT 1 FROM test_classes tc JOIN tests t ON t.id = tc.test_id
                    WHERE tc.class_id = s.class_id AND t.teacher_id = ? AND (? = 0 OR t.subject_id = ?)
                )
            ) ORDER BY $order", [$teacherId, $subjectFilter, $subjectFilter, $classFilter, $classFilter,
                $teacherId, $subjectFilter, $subjectFilter])->fetchAll();
    }
} catch (PDOException $exception) {
    error_log((string) $exception);
    echo '<p class="alert error" role="alert">Не вдалося завантажити список учнів або історію тестів.</p>';
    return;
}
?>
<section class="panel question-editor">
<?php if (!$studentId): ?>
<h2>Учні</h2>
<form action="teacher.php" method="get" class="teacher-test-filters">
<input type="hidden" name="section" value="students">
<label>Клас<select name="class"><option value="0">Усі класи</option>
<?php foreach ($classes as $class): ?>
<option value="<?= e($class['id']) ?>" <?= $classFilter === (int) $class['id'] ? 'selected' : '' ?>><?= e(className($class)) ?></option>
<?php endforeach ?>
</select></label>
<label>Предмет<select name="subject"><option value="0">Усі предмети</option>
<?php foreach ($subjects as $subject): ?>
<option value="<?= e($subject['id']) ?>" <?= $subjectFilter === (int) $subject['id'] ? 'selected' : '' ?>><?= e($subject['name']) ?></option>
<?php endforeach ?>
</select></label>
<label>Сортування<select name="order">
<option value="">За алфавітом</option>
<option value="class" <?= $studentSort === 'class' ? 'selected' : '' ?>>За класом</option>
<option value="latest" <?= $studentSort === 'latest' ? 'selected' : '' ?>>За останньою спробою</option>
</select></label>
<button>Показати</button>
</form>
<?php if (!$students): ?><p>За цими фільтрами учнів немає.</p><?php else: ?>
<div class="results-scroll" tabindex="0" aria-label="Таблиця учнів">
<table class="results-table" data-sortable="teacher-students"><thead><tr><th>Учень</th><th>Клас</th><th>Спроб</th><th>Остання спроба</th></tr></thead><tbody>
<?php foreach ($students as $student): ?>
<tr><th scope="row"><a href="<?= e($listUrl . '&student=' . $student['id']) ?>"><?= e($student['full_name']) ?></a></th>
<td><?= $student['grade'] === null ? 'Без класу' : e(className($student)) ?></td>
<td><?= e($student['total'] ?? 0) ?></td><td><?= e($student['last_attempt'] ?? '—') ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php else: ?>
<p><a class="import-template" href="<?= e($listUrl) ?>">← До списку учнів</a></p>
<?php if (!$profile): ?><p>Учень недоступний.</p><?php else: ?>
<h2><?= e($profile['full_name']) ?> · Історія тестів</h2>
<p><a class="import-template" href="<?= e($listUrl . '&student=' . $studentId . '&date=' . ($oldest ? 'newest' : 'oldest')) ?>">
<?= $oldest ? 'Дата ↑ · показати нові спочатку' : 'Дата ↓ · показати старі спочатку' ?></a></p>
<?php if (!$history): ?><p>Учень ще не проходив ваших тестів.</p><?php else: ?>
<div class="results-scroll" tabindex="0" aria-label="Історія тестів учня">
<table class="results-table teacher-tests-table" data-sortable="teacher-history"><thead><tr><th>Тест</th><th>Предмет</th><th>Клас</th><th>Початок</th><th>Завершення</th><th>Бали</th><th>Оцінка / 12</th><th>Стан</th></tr></thead><tbody>
<?php foreach ($history as $item):
    $details = 'results.php?test=' . $item['test_id'] . '&attempt=' . $item['id'];
?>
<tr><th scope="row"><div class="result-student-name">
<?php if ($item['quick_review'] && in_array($item['status'], ['pending_review', 'graded'], true)): ?>
<a class="quick-review-link" data-quick-link href="<?= e($details . '&quick=1') ?>" aria-label="Швидка перевірка: <?= e($item['title']) ?>" title="Швидка перевірка">✎</a>
<?php endif ?>
<a href="<?= e($details) ?>"><?= e($item['title']) ?></a></div></th>
<td><?= e($item['subject_name']) ?></td><td><?= e(className(['grade' => $item['class_grade'], 'section' => $item['section']])) ?></td>
<td><?= e($item['started_at']) ?></td><td><?= e($item['submitted_at'] ?? '—') ?></td>
<td><?= e(testPoints($item)) ?></td><td><?= e($item['grade'] ?? '—') ?></td>
<td><?= match ($item['status']) {
    'started' => 'Триває',
    'stopped' => 'Зупинено',
    'pending_review' => 'Очікує перевірки',
    default => 'Оцінено',
} ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php require __DIR__ . '/quick_review_dialog.php'; ?>
<script src="assets/teacher-students.js" defer></script>
<?php endif ?>
<?php endif ?>
<?php endif ?>
</section>
