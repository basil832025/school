<?php
declare(strict_types=1);

function manageAttempt(array $attempt, array $test, string $action): string
{
    db()->beginTransaction();
    try {
        $studentId = $attempt['student_id'];
        if ($studentId) {
            query('SELECT id FROM students WHERE id = ? FOR UPDATE', [$studentId])->fetch();
        }
        $current = query('SELECT status, student_id FROM attempts WHERE id = ? AND test_id = ? FOR UPDATE',
            [$attempt['id'], $test['id']])->fetch();
        if (!$current || $current['student_id'] !== $studentId || $current['status'] !== $attempt['status']) {
            throw new DomainException('Спроба змінилася. Відкрийте її з таблиці знову.');
        }
        switch ($action) {
            case 'stop':
                if ($current['status'] !== 'started') {
                    throw new DomainException('Зупинити можна лише активну спробу.');
                }
                query("UPDATE attempts SET status = 'stopped', submitted_at = NOW() WHERE id = ?", [$attempt['id']]);
                $notice = 'Спробу зупинено. Відповіді збережено.';
                break;
            case 'grant_retake':
                $published = query("SELECT id FROM tests WHERE id = ? AND status = 'published' FOR UPDATE", [$test['id']])->fetchColumn();
                $latest = $studentId ? query("SELECT id FROM attempts WHERE student_id = ? AND test_id = ?
                    ORDER BY (status = 'started') DESC, id DESC LIMIT 1", [$studentId, $test['id']])->fetchColumn() : null;
                if (!$published || !$studentId || $current['status'] === 'started' || (int) $latest !== (int) $attempt['id']) {
                    throw new DomainException('Дозвіл можна надати після останньої завершеної або зупиненої спроби опублікованого тесту.');
                }
                query('INSERT INTO retake_permissions (student_id, test_id) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE test_id = retake_permissions.test_id', [$studentId, $test['id']]);
                $notice = 'Дозволено одну повторну спробу.';
                break;
            case 'delete':
                query('DELETE ao FROM attempt_answer_options ao JOIN attempt_answers aa ON aa.id = ao.answer_id
                    WHERE aa.attempt_id = ?', [$attempt['id']]);
                query('DELETE FROM attempt_answers WHERE attempt_id = ?', [$attempt['id']]);
                query('DELETE FROM attempts WHERE id = ?', [$attempt['id']]);
                if ($studentId && !query('SELECT id FROM attempts WHERE student_id = ? AND test_id = ? LIMIT 1',
                    [$studentId, $test['id']])->fetchColumn()) {
                    query('DELETE FROM retake_permissions WHERE student_id = ? AND test_id = ?', [$studentId, $test['id']]);
                }
                $notice = 'Спробу та її відповіді видалено.';
                break;
            default:
                throw new DomainException('Невідома дія.');
        }
        db()->commit();
        return $notice;
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
}
