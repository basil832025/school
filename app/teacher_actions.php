<?php
declare(strict_types=1);

function selectedClasses(): array
{
    $values = $_POST['classes'] ?? [];
    if (!is_array($values) || array_filter($values, 'is_string') !== $values) {
        throw new DomainException('Оберіть класи зі списку.');
    }
    $ids = array_values(array_unique(array_map('intval', $values)));
    if (!$ids) {
        throw new DomainException('Оберіть щонайменше один клас.');
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    if ((int) query("SELECT COUNT(*) FROM classes WHERE id IN ($marks)", $ids)->fetchColumn() !== count($ids)) {
        throw new DomainException('Оберіть наявні класи.');
    }
    return $ids;
}

function saveTestClasses(int $testId, array $classes): void
{
    query('DELETE FROM test_classes WHERE test_id = ?', [$testId]);
    foreach ($classes as $classId) {
        query('INSERT INTO test_classes (test_id, class_id) VALUES (?, ?)', [$testId, $classId]);
    }
}

function imageData(mixed $url, mixed $size): array
{
    if (!is_string($url) || !is_string($size) || !in_array($size, ['small', 'medium', 'large'], true)) {
        throw new DomainException('Невірні налаштування зображення.');
    }
    $url = trim($url);
    $local = preg_match('~^uploads/question-images/[a-f0-9]{32}\.(png|jpg|webp|gif)$~D', $url)
        && is_file(dirname(__DIR__) . '/' . $url);
    if ($url !== '' && !$local && (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        || parse_url($url, PHP_URL_USER) !== null)) {
        throw new DomainException('Вкажіть пряме HTTPS-посилання або завантажте зображення з пристрою.');
    }
    return [$url === '' ? null : $url, $size];
}

function uploadQuestionImage(mixed $error, mixed $temporary, array &$uploadedPaths): ?string
{
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_string($temporary) || !is_uploaded_file($temporary)) {
        throw new DomainException('Не вдалося завантажити файл. Перевірте розмір і обмеження хостингу.');
    }
    if (filesize($temporary) > 5 * 1024 * 1024) {
        throw new DomainException('Розмір зображення має бути до 5 МБ.');
    }
    $image = @getimagesize($temporary);
    $extensions = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$image || !isset($extensions[$image[2]]) || $image[0] < 1 || $image[1] < 1
        || $image[0] * $image[1] > 30_000_000) {
        throw new DomainException('Виберіть зображення PNG, JPEG, WebP або GIF до 30 мегапікселів.');
    }
    $directory = dirname(__DIR__) . '/uploads/question-images';
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new DomainException('Не вдалося створити папку для зображень. Перевірте права на запис.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$image[2]];
    $path = $directory . '/' . $filename;
    if (!@move_uploaded_file($temporary, $path)) {
        throw new DomainException('Не вдалося зберегти зображення. Перевірте права на запис.');
    }
    $uploadedPaths[] = $path;
    @chmod($path, 0644);
    return 'uploads/question-images/' . $filename;
}

function questionFormData(array &$uploadedPaths): array
{
    $fields = $_POST;
    $questionFile = $_FILES['question_image_file'] ?? [];
    $url = uploadQuestionImage($questionFile['error'] ?? UPLOAD_ERR_NO_FILE, $questionFile['tmp_name'] ?? null, $uploadedPaths);
    if ($url !== null) {
        $fields['image_url'] = $url;
    }
    if (($fields['type'] ?? '') === 'written') {
        return $fields;
    }
    $optionFiles = $_FILES['option_image_file'] ?? [];
    $errors = $optionFiles['error'] ?? [];
    $temporaryNames = $optionFiles['tmp_name'] ?? [];
    $labels = $fields['options'] ?? [];
    $urls = $fields['option_image_url'] ?? [];
    if (!is_array($errors) || count($errors) > 30 || !is_array($temporaryNames) || !is_array($labels) || !is_array($urls)) {
        throw new DomainException('Некоректні файли варіантів відповіді.');
    }
    foreach ($errors as $index => $error) {
        if (!array_key_exists($index, $labels)) {
            throw new DomainException('Для зображення не знайдено варіант відповіді.');
        }
        $url = uploadQuestionImage($error, $temporaryNames[$index] ?? null, $uploadedPaths);
        if ($url !== null) {
            $urls[$index] = $url;
        }
    }
    $fields['option_image_url'] = $urls;
    return $fields;
}

function questionData(array $fields): array
{
    $prompt = $fields['prompt'] ?? '';
    if (!is_string($prompt) || trim($prompt) === '' || mb_strlen($prompt) > 10000) {
        throw new DomainException('Введіть текст запитання до 10000 символів.');
    }
    $type = $fields['type'] ?? '';
    if (!in_array($type, ['single', 'multiple', 'written'], true)) {
        throw new DomainException('Оберіть тип запитання.');
    }
    $points = $fields['max_points'] ?? '';
    if (!is_string($points) || !preg_match('/^\d{1,6}(\.\d{1,2})?$/D', $points) || (float) $points <= 0) {
        throw new DomainException('Бали мають бути більшими за 0, до 999999,99, з точністю до сотих.');
    }
    $guidance = $fields['grading_guidance'] ?? '';
    if (!is_string($guidance) || mb_strlen($guidance) > 10000) {
        throw new DomainException('Скоротіть підказку для перевірки до 10000 символів.');
    }
    $options = [];
    [$imageUrl, $imageSize] = imageData($fields['image_url'] ?? '', $fields['image_size'] ?? 'medium');
    if ($type !== 'written') {
        $labels = $fields['options'] ?? [];
        $correct = $fields['correct'] ?? [];
        $optionImages = $fields['option_image_url'] ?? [];
        $optionSizes = $fields['option_image_size'] ?? [];
        if (!is_array($labels) || !is_array($correct) || !is_array($optionImages) || !is_array($optionSizes) || count($labels) > 30) {
            throw new DomainException('Дозволено від 2 до 30 варіантів.');
        }
        foreach ($correct as $index) {
            if (!is_string($index) || !array_key_exists($index, $labels)) {
                throw new DomainException('Оберіть правильний варіант зі списку.');
            }
        }
        foreach ($labels as $index => $label) {
            if (!is_string($label) || mb_strlen($label) > 2000) {
                throw new DomainException('Варіант відповіді має містити до 2000 символів.');
            }
            [$optionUrl, $optionSize] = imageData($optionImages[$index] ?? '', $optionSizes[$index] ?? 'medium');
            $label = trim($label);
            $isCorrect = in_array((string) $index, $correct, true);
            if ($label === '' && $optionUrl === null) {
                if ($isCorrect) {
                    throw new DomainException('Правильний варіант має містити текст або зображення.');
                }
                continue;
            }
            $options[] = [$label !== '' ? $label : 'Варіант ' . (count($options) + 1), (int) $isCorrect, $optionUrl, $optionSize];
        }
        $rightCount = array_sum(array_column($options, 1));
        if (count($options) < 2 || $rightCount < 1 || ($type === 'single' && $rightCount !== 1)) {
            throw new DomainException('Додайте щонайменше 2 варіанти. Для одиничного вибору позначте одну правильну відповідь, для множинного — щонайменше одну.');
        }
    }
    return [trim($prompt), $type, $points, trim($guidance), $options, $imageUrl, $imageSize];
}

function saveQuestionOptions(int $questionId, array $options): void
{
    foreach ($options as $index => [$label, $correct, $imageUrl, $imageSize]) {
        query('INSERT INTO question_options (question_id, label, is_correct, position, image_url, image_size) VALUES (?, ?, ?, ?, ?, ?)',
            [$questionId, $label, $correct, $index + 1, $imageUrl, $imageSize]);
    }
}

function insertQuestion(int $testId, int $position, array $data): void
{
    [$prompt, $type, $points, $guidance, $options, $imageUrl, $imageSize] = $data;
    query('INSERT INTO questions (test_id, prompt, type, max_points, grading_guidance, position, image_url, image_size)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$testId, $prompt, $type, $points, $guidance, $position, $imageUrl, $imageSize]);
    saveQuestionOptions((int) db()->lastInsertId(), $options);
}

function deleteTestQuestions(int $testId): void
{
    query('DELETE o FROM question_options o JOIN questions q ON q.id = o.question_id WHERE q.test_id = ?', [$testId]);
    query('DELETE FROM questions WHERE test_id = ?', [$testId]);
}

function teacherAction(array $teacher): never
{
    $action = $_POST['action'] ?? '';
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect('teacher.php');
    }
    if ($action === 'subject') {
        query('INSERT INTO subjects (name) VALUES (?)', [textField('name', 120)]);
        $_SESSION['notice'] = 'Предмет додано.';
        redirect('teacher.php?section=subjects');
    }
    if ($action === 'class') {
        $grade = filter_var($_POST['grade'] ?? '', FILTER_VALIDATE_INT);
        $section = $_POST['section'] ?? '';
        if (!$grade || $grade < 1 || $grade > 12 || !is_string($section) || mb_strlen(trim($section)) > 8) {
            throw new DomainException('Вкажіть клас від 1 до 12 та літеру до 8 символів.');
        }
        query('INSERT INTO classes (grade, section) VALUES (?, ?)', [$grade, mb_strtoupper(trim($section))]);
        $_SESSION['notice'] = 'Клас додано.';
        redirect('teacher.php?section=classes');
    }
    $uploadedPaths = [];
    db()->beginTransaction();
    try {
        if ($action === 'create') {
            $title = textField('title', 180);
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            if (!query('SELECT id FROM subjects WHERE id = ?', [$subjectId])->fetchColumn()) {
                throw new DomainException('Оберіть предмет.');
            }
            $classes = selectedClasses();
            query('INSERT INTO tests (subject_id, teacher_id, title) VALUES (?, ?, ?)', [$subjectId, $teacher['id'], $title]);
            $testId = (int) db()->lastInsertId();
            saveTestClasses($testId, $classes);
        } else {
            $testId = (int) ($_POST['test_id'] ?? 0);
            $test = query('SELECT * FROM tests WHERE id = ? AND teacher_id = ? FOR UPDATE',
                [$testId, $teacher['id']])->fetch();
            if (!$test) {
                throw new DomainException('Тест не знайдено або доступ відсутній.');
            }
            if ($action === 'archive' && $test['status'] === 'published') {
                query("UPDATE tests SET status = 'archived' WHERE id = ?", [$testId]);
            } else {
                if ($test['status'] !== 'draft') {
                    throw new DomainException('Редагувати можна лише чернетки.');
                }
                switch ($action) {
                    case 'save_test':
                        $subjectId = (int) ($_POST['subject_id'] ?? 0);
                        if (!query('SELECT id FROM subjects WHERE id = ?', [$subjectId])->fetchColumn()) {
                            throw new DomainException('Оберіть предмет.');
                        }
                        $title = textField('title', 180);
                        $classes = selectedClasses();
                        query('UPDATE tests SET title = ?, subject_id = ? WHERE id = ?', [$title, $subjectId, $testId]);
                        saveTestClasses($testId, $classes);
                        break;
                    case 'save_question':
                        $data = questionData(questionFormData($uploadedPaths));
                        [$prompt, $type, $points, $guidance, $options, $imageUrl, $imageSize] = $data;
                        $questionId = (int) ($_POST['question_id'] ?? 0);
                        if ($questionId) {
                            if (!query('SELECT id FROM questions WHERE id = ? AND test_id = ?', [$questionId, $testId])->fetchColumn()) {
                                throw new DomainException('Запитання не знайдено.');
                            }
                            query('UPDATE questions SET prompt = ?, type = ?, max_points = ?, grading_guidance = ?, image_url = ?, image_size = ? WHERE id = ?',
                                [$prompt, $type, $points, $guidance, $imageUrl, $imageSize, $questionId]);
                            query('DELETE FROM question_options WHERE question_id = ?', [$questionId]);
                            saveQuestionOptions($questionId, $options);
                        } else {
                            $position = (int) query('SELECT COALESCE(MAX(position), 0) + 1 FROM questions WHERE test_id = ?', [$testId])->fetchColumn();
                            insertQuestion($testId, $position, $data);
                        }
                        break;
                    case 'import_questions':
                    case 'replace_questions':
                        if ($action === 'replace_questions' && ($_POST['confirm_replace'] ?? '') !== '1') {
                            throw new DomainException('Підтвердьте заміну всіх запитань у чернетці.');
                        }
                        require_once __DIR__ . '/question_import.php';
                        $imported = readQuestionImport();
                        if ($action === 'replace_questions') {
                            deleteTestQuestions($testId);
                        }
                        $lastPosition = (int) query('SELECT COALESCE(MAX(position), 0) FROM questions WHERE test_id = ?', [$testId])->fetchColumn();
                        if ($lastPosition + count($imported) > 65535) {
                            throw new DomainException('У тесті недостатньо місця для цих запитань.');
                        }
                        foreach ($imported as $index => $data) {
                            insertQuestion($testId, $lastPosition + $index + 1, $data);
                        }
                        break;
                    case 'delete_question':
                        $questionId = (int) ($_POST['question_id'] ?? 0);
                        if (!query('SELECT id FROM questions WHERE id = ? AND test_id = ?', [$questionId, $testId])->fetchColumn()) {
                            throw new DomainException('Запитання не знайдено.');
                        }
                        query('DELETE FROM question_options WHERE question_id = ?', [$questionId]);
                        query('DELETE FROM questions WHERE id = ?', [$questionId]);
                        break;
                    case 'delete_test':
                        if (($_POST['confirm'] ?? '') !== '1') {
                            throw new DomainException('Підтвердьте видалення тесту.');
                        }
                        if (query('SELECT id FROM attempts WHERE test_id = ? LIMIT 1', [$testId])->fetchColumn()
                            || query('SELECT id FROM tests WHERE previous_test_id = ? LIMIT 1', [$testId])->fetchColumn()) {
                            throw new DomainException('Чернетку не можна видалити, бо вона має спроби або пов’язані версії.');
                        }
                        deleteTestQuestions($testId);
                        query('DELETE FROM test_classes WHERE test_id = ?', [$testId]);
                        query('DELETE FROM tests WHERE id = ?', [$testId]);
                        break;
                    case 'publish':
                        $questions = query('SELECT * FROM questions WHERE test_id = ?', [$testId])->fetchAll();
                        if (!$questions || !query('SELECT class_id FROM test_classes WHERE test_id = ?', [$testId])->fetchColumn()) {
                            throw new DomainException('Для публікації додайте запитання й оберіть класи.');
                        }
                        foreach ($questions as $question) {
                            $options = query('SELECT is_correct FROM question_options WHERE question_id = ?', [$question['id']])->fetchAll();
                            $rightCount = array_sum(array_column($options, 'is_correct'));
                            if ((float) $question['max_points'] <= 0 || ($question['type'] !== 'written'
                                && (count($options) < 2 || $rightCount < 1 || ($question['type'] === 'single' && $rightCount !== 1)))) {
                                throw new DomainException('Перевірте бали та правильні відповіді всіх запитань.');
                            }
                        }
                        query("UPDATE tests SET status = 'published', published_at = NOW() WHERE id = ?", [$testId]);
                        break;
                    default:
                        throw new DomainException('Невідома дія.');
                }
            }
        }
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        foreach ($uploadedPaths as $path) {
            @unlink($path);
        }
        throw $error;
    }
    $_SESSION['notice'] = match ($action) {
        'publish' => 'Тест опубліковано. Запитання та правильні відповіді зафіксовано.',
        'archive' => 'Тест перенесено до архіву.',
        'delete_question' => 'Запитання видалено.',
        'delete_test' => 'Чернетку тесту видалено.',
        'import_questions' => 'Імпортовано запитань: ' . count($imported) . '.',
        'replace_questions' => 'Запитання замінено. Імпортовано: ' . count($imported) . '.',
        default => 'Зміни збережено.',
    };
    redirect($action === 'delete_test' ? 'teacher.php' : 'teacher.php?test=' . $testId);
}
