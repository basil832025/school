<?php
declare(strict_types=1);

function testQuestions(int $testId): array
{
    $questions = query('SELECT * FROM questions WHERE test_id = ? ORDER BY position', [$testId])->fetchAll();
    foreach ($questions as &$question) {
        $question['options'] = query('SELECT * FROM question_options WHERE question_id = ? ORDER BY position',
            [$question['id']])->fetchAll();
    }
    return $questions;
}

function finalizeAttempt(int $id): void
{
    query("UPDATE attempts a JOIN (
        SELECT attempt_id, SUM(awarded_points) AS earned, COUNT(*) - COUNT(awarded_points) AS pending
        FROM attempt_answers WHERE attempt_id = ? GROUP BY attempt_id
    ) totals ON totals.attempt_id = a.id
    SET a.earned_points = COALESCE(totals.earned, 0),
        a.grade = IF(totals.pending, NULL, GREATEST(1, ROUND(12 * COALESCE(totals.earned, 0) / a.max_points))),
        a.status = IF(totals.pending, 'pending_review', 'graded'),
        a.graded_at = IF(totals.pending, NULL, NOW())", [$id]);
}

function studentName(): string
{
    $name = textField('student_name', 150);
    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    $word = '\p{L}+(?:[’ʼ\x{0027}-]\p{L}+)*';
    if (!$parts || count($parts) < 2) {
        throw new DomainException('Введи прізвище та ім’я через пробіл.');
    }
    foreach ($parts as $index => $part) {
        $minimum = $index === 0 ? 3 : 2;
        if (!preg_match('/^' . $word . '$/uD', $part) || preg_match_all('/\p{L}/u', $part) < $minimum) {
            throw new DomainException('Прізвище має містити щонайменше 3 літери, ім’я та по батькові — щонайменше 2 літери кожне.');
        }
    }
    return implode(' ', $parts);
}

function currentStudent(): ?array
{
    if (empty($_SESSION['student_id'])) {
        return null;
    }
    $student = query('SELECT id, login, full_name, password_hash, class_id FROM students WHERE id = ?', [$_SESSION['student_id']])->fetch();
    if (!$student || !hash_equals(hash('sha256', $student['password_hash']), $_SESSION['student_password_version'] ?? '')) {
        unset($_SESSION['student_id'], $_SESSION['student_password_version']);
        return null;
    }
    unset($student['password_hash']);
    return $student;
}

function startTest(int $testId, int $classId, array $student): never
{
    db()->beginTransaction();
    try {
        $classAssignment = query('SELECT class_id FROM students WHERE id = ? FOR UPDATE', [$student['id']])->fetch();
        if (!$classAssignment || ($classAssignment['class_id'] && (int) $classAssignment['class_id'] !== $classId)) {
            throw new DomainException('Цей тест недоступний для твого класу.');
        }
        $maximum = query("SELECT SUM(q.max_points) FROM tests t JOIN test_classes tc ON tc.test_id = t.id
            JOIN questions q ON q.test_id = t.id WHERE t.id = ? AND tc.class_id = ? AND t.status = 'published'",
            [$testId, $classId])->fetchColumn();
        if (!$maximum) {
            throw new DomainException('Цей тест зараз недоступний.');
        }
        $latest = query("SELECT id, class_id, status FROM attempts WHERE student_id = ? AND test_id = ?
            ORDER BY (status = 'started') DESC, id DESC LIMIT 1", [$student['id'], $testId])->fetch();
        if ($latest && $latest['status'] === 'started') {
            $attemptId = $latest['id'];
            $classId = $latest['class_id'];
        } else {
            if ($latest && !query('DELETE FROM retake_permissions WHERE student_id = ? AND test_id = ?',
                [$student['id'], $testId])->rowCount()) {
                throw new DomainException('Ти вже пройшов цей тест. Повторну спробу може дозволити вчитель.');
            }
            query('INSERT INTO attempts (student_id, test_id, class_id, student_name, max_points) VALUES (?, ?, ?, ?, ?)',
                [$student['id'], $testId, $classId, $student['full_name'], $maximum]);
            $attemptId = (int) db()->lastInsertId();
        }
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
    redirect('index.php?test=' . $testId . '&class=' . $classId . '&attempt=' . $attemptId . '#quiz');
}

function saveTestAnswers(int $testId, int $classId, int $attemptId, int $studentId, bool $final): void
{
    $token = $_POST['attempt_token'] ?? '';
    if (!isset($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        throw new DomainException('Цю форму вже надіслано або вона застаріла.');
    }
    db()->beginTransaction();
    try {
        $attempt = query("SELECT a.status FROM attempts a JOIN tests t ON t.id = a.test_id
            WHERE a.id = ? AND a.test_id = ? AND a.class_id = ? AND a.student_id = ?
            AND t.status = 'published' FOR UPDATE", [$attemptId, $testId, $classId, $studentId])->fetch();
        if (($attempt['status'] ?? '') === 'stopped') {
            throw new DomainException('Вчитель зупинив цю спробу. Перевір свій кабінет.');
        }
        $questions = ($attempt['status'] ?? '') === 'started' ? testQuestions($testId) : [];
        if (!$questions) {
            throw new DomainException('Цей тест зараз недоступний.');
        }
        foreach ($questions as $index => $question) {
            $id = $question['id'];
            if (!$final && (string) $id !== ($_POST['question_id'] ?? null)) {
                continue;
            }
            $answer = $_POST['answers'][$id] ?? null;
            $existing = query('SELECT id FROM attempt_answers WHERE attempt_id = ? AND question_id = ?',
                [$attemptId, $id])->fetchColumn();
            if ($existing) {
                query('DELETE FROM attempt_answer_options WHERE answer_id = ?', [$existing]);
                query('DELETE FROM attempt_answers WHERE id = ?', [$existing]);
            }
            if (!$final && ($answer === null || (is_string($answer) && trim($answer) === ''))) {
                continue;
            }
            $written = null;
            $selected = [];
            $points = null;
            if ($question['type'] === 'written') {
                if (!is_string($answer) || trim($answer) === '' || mb_strlen($answer) > 10000) {
                    throw new DomainException('Заповни відповідь на запитання ' . ($index + 1) . ' (до 10000 символів).');
                }
                $written = trim($answer);
            } else {
                $selected = $question['type'] === 'single' ? [$answer] : $answer;
                $allowed = array_map('strval', array_column($question['options'], 'id'));
                if (!is_array($selected) || !$selected || array_filter($selected, 'is_string') !== $selected
                    || array_diff($selected, $allowed) || count(array_unique($selected)) !== count($selected)) {
                    throw new DomainException('Обери відповідь на запитання ' . ($index + 1) . '.');
                }
                $correct = array_map('strval', array_column(array_filter($question['options'],
                    static fn(array $option): bool => (bool) $option['is_correct']), 'id'));
                $points = !array_diff($selected, $correct) && !array_diff($correct, $selected) ? $question['max_points'] : 0;
            }
            query('INSERT INTO attempt_answers (attempt_id, question_id, written_answer, awarded_points) VALUES (?, ?, ?, ?)',
                [$attemptId, $id, $written, $points]);
            $answerId = (int) db()->lastInsertId();
            foreach ($selected as $option) {
                query('INSERT INTO attempt_answer_options (answer_id, option_id) VALUES (?, ?)', [$answerId, $option]);
            }
        }
        if ($final) {
            query('UPDATE attempts SET submitted_at = NOW() WHERE id = ?', [$attemptId]);
            finalizeAttempt($attemptId);
        }
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
    if ($final) {
        redirect('student-account.php#history');
    }
}
