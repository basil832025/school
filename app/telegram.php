<?php
declare(strict_types=1);

function telegramConfig(): array
{
    return require __DIR__ . '/telegram_config.local.php';
}

function newTelegramCode(int $studentId, string $purpose): string
{
    $code = bin2hex(random_bytes($purpose === 'link' ? 8 : 32));
    query('DELETE FROM telegram_codes WHERE student_id = ? AND (expires_at <= NOW() OR purpose = ?)', [$studentId, $purpose]);
    $minutes = $purpose === 'keyboard' ? 1440 : 5;
    query('INSERT INTO telegram_codes VALUES (?, ?, ?, TIMESTAMPADD(MINUTE, ?, NOW()))',
        [hash('sha256', $code), $studentId, $purpose, $minutes]);
    return $code;
}

function telegramMenu(string $text, int $studentId): array
{
    $code = newTelegramCode($studentId, 'keyboard');
    $url = telegramConfig()['url'] . '/telegram-login.php?mini_app=1&next=tests&keyboard=1&student=' . $studentId . '#launch=' . $code;
    return [
        'text' => $text,
        'reply_markup' => [
            'keyboard' => [
                [['text' => 'Тести', 'web_app' => ['url' => $url]]],
                [['text' => 'Кабінет учня'], ['text' => 'Мої результати']],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ],
    ];
}

function telegramSendRegistrationSuccess(int $telegramId, int $studentId): bool
{
    $config = telegramConfig();
    $request = curl_init('https://api.telegram.org/bot' . $config['token'] . '/sendMessage');
    $payload = ['chat_id' => $telegramId] + telegramMenu('Реєстрацію успішно завершено! Обери розділ у меню нижче.', $studentId);
    curl_setopt_array($request, [
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $reply = curl_exec($request);
    $result = is_string($reply) ? json_decode($reply, true) : null;
    return ($result['ok'] ?? false) === true;
}

function telegramStudentLink(string $destination): array
{
    $url = telegramConfig()['url'] . '/telegram-login.php?mini_app=1&next=' . $destination;
    $label = match ($destination) {
        'tests' => 'Відкрити тести',
        'results' => 'Переглянути результати',
        default => 'Відкрити кабінет учня',
    };
    return [
        'text' => 'Натисни кнопку, щоб відкрити розділ у Telegram.',
        'reply_markup' => ['inline_keyboard' => [[['text' => $label, 'web_app' => ['url' => $url]]]]],
    ];
}

function telegramClasses(): array
{
    return query('SELECT id, grade, section FROM classes ORDER BY grade, section')->fetchAll();
}

function telegramRegistration(array $profile, array $message): array
{
    if ($profile['student_id']) {
        $destination = match ($message['text'] ?? '') {
            'Тести', '/tests', '/start tests' => 'tests',
            'Кабінет учня', '/account' => 'account',
            'Мої результати', '/results' => 'results',
            default => null,
        };
        return $destination ? telegramStudentLink($destination)
            : telegramMenu('Я чат-бот Школи №165. Натисни потрібну кнопку в меню нижче.', (int) $profile['student_id']);
    }
    $id = $profile['telegram_id'];
    if (($message['text'] ?? '') === '/cancel') {
        query("UPDATE telegram_registrations SET stage = 'phone', phone = NULL, surname = NULL, first_name = NULL,
            class_id = NULL, birth_year = NULL WHERE telegram_id = ?", [$id]);
        return ['text' => 'Реєстрацію скасовано. Щоб почати знову, надішли /start.', 'reply_markup' => ['remove_keyboard' => true]];
    }
    if (!$profile['phone']) {
        $contact = $message['contact'] ?? [];
        $phone = preg_replace('/[\s().-]/', '', (string) ($contact['phone_number'] ?? ''));
        if ((string) ($contact['user_id'] ?? '') !== (string) $id || !preg_match('/^\+?[0-9]{7,15}$/D', $phone)) {
            return ['text' => 'Підтвердь свій номер, щоб відкрити форму реєстрації учня.',
                'reply_markup' => ['keyboard' => [[['text' => 'Поділитися своїм номером', 'request_contact' => true]]],
                    'resize_keyboard' => true, 'one_time_keyboard' => true]];
        }
        query("UPDATE telegram_registrations SET phone = ?, stage = 'web' WHERE telegram_id = ?",
            ['+' . ltrim($phone, '+'), $id]);
    }
    return [
        'text' => 'Номер підтверджено. Заповни коротку форму реєстрації.',
        'reply_markup' => ['inline_keyboard' => [[['text' => 'Відкрити форму реєстрації',
            'web_app' => ['url' => telegramConfig()['url'] . '/telegram-registration.php']]]]],
    ];
}

function telegramWebUser(string $initData): int
{
    if ($initData === '' || strlen($initData) > 16384) {
        throw new DomainException('Відкрий форму кнопкою в Telegram-боті.');
    }
    $fields = [];
    foreach (explode('&', $initData) as $pair) {
        $parts = explode('=', $pair, 2);
        $key = urldecode($parts[0]);
        if ($key === '' || isset($fields[$key]) || count($parts) !== 2) {
            throw new DomainException('Некоректні дані Telegram.');
        }
        $fields[$key] = urldecode($parts[1]);
    }
    $hash = $fields['hash'] ?? '';
    unset($fields['hash']);
    ksort($fields, SORT_STRING);
    $lines = [];
    foreach ($fields as $key => $value) {
        $lines[] = $key . '=' . $value;
    }
    $secret = hash_hmac('sha256', telegramConfig()['token'], 'WebAppData', true);
    if (!preg_match('/^[a-f0-9]{64}$/D', $hash)
        || !hash_equals(hash_hmac('sha256', implode("\n", $lines), $secret), $hash)) {
        throw new DomainException('Не вдалося перевірити підпис Telegram.');
    }
    $authDate = ctype_digit($fields['auth_date'] ?? '') ? (int) $fields['auth_date'] : 0;
    if ($authDate < time() - 3600 || $authDate > time() + 30) {
        throw new DomainException('Сеанс завершився. Відкрий форму з бота знову.');
    }
    $user = json_decode($fields['user'] ?? '', true);
    if (!is_int($user['id'] ?? null) || $user['id'] <= 0 || !empty($user['is_bot'])) {
        throw new DomainException('Некоректний користувач Telegram.');
    }
    return $user['id'];
}

function telegramCompleteRegistration(int $telegramId): array
{
    $profile = query('SELECT * FROM telegram_registrations WHERE telegram_id = ? FOR UPDATE', [$telegramId])->fetch();
    if (!$profile || !$profile['phone']) {
        throw new DomainException('Спочатку підтвердь свій номер у боті.');
    }
    if (!$profile['student_id']) {
        $surname = textField('surname', 75);
        $firstName = textField('first_name', 75);
        $word = '/^\p{L}+(?:[’ʼ\x{0027}-]\p{L}+)*$/uD';
        if (!preg_match($word, $surname) || preg_match_all('/\p{L}/u', $surname) < 3
            || !preg_match($word, $firstName) || preg_match_all('/\p{L}/u', $firstName) < 2) {
            throw new DomainException('Введи прізвище від 3 літер та ім’я від 2 літер.');
        }
        $classId = filter_var($_POST['class_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$classId || !query('SELECT id FROM classes WHERE id = ?', [$classId])->fetchColumn()) {
            throw new DomainException('Обери клас зі списку.');
        }
        $year = filter_var($_POST['birth_year'] ?? '', FILTER_VALIDATE_INT);
        if (!$year || $year < 1900 || $year > (int) date('Y')) {
            throw new DomainException('Введи рік народження чотирма цифрами.');
        }
        $code = $_POST['link_code'] ?? '';
        if (!is_string($code)) {
            throw new DomainException('Некоректний код прив’язки.');
        }
        $code = strtolower(trim($code));
        if ($code !== '') {
            if (!preg_match('/^[a-f0-9]{16}$/D', $code)) {
                throw new DomainException('Введи лише 16 символів коду прив’язки з кабінету учня.');
            }
            $hash = hash('sha256', $code);
            $studentId = query("SELECT student_id FROM telegram_codes WHERE code_hash = ? AND purpose = 'link'
                AND expires_at > NOW() FOR UPDATE", [$hash])->fetchColumn();
            if (!$studentId || query('SELECT telegram_id FROM telegram_registrations WHERE student_id = ?', [$studentId])->fetchColumn()) {
                throw new DomainException('Код неправильний, прострочений або кабінет уже прив’язано.');
            }
            query('DELETE FROM telegram_codes WHERE code_hash = ?', [$hash]);
        } else {
            query('INSERT INTO students (login, password_hash, full_name) VALUES (?, ?, ?)',
                ['tg_' . bin2hex(random_bytes(10)), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $surname . ' ' . $firstName]);
            $studentId = (int) db()->lastInsertId();
        }
        query("UPDATE telegram_registrations SET surname = ?, first_name = ?, class_id = ?, birth_year = ?,
            student_id = ?, stage = 'done' WHERE telegram_id = ?", [$surname, $firstName, $classId, $year, $studentId, $telegramId]);
    } else {
        $studentId = $profile['student_id'];
    }
    return query('SELECT id, password_hash FROM students WHERE id = ?', [$studentId])->fetch();
}
