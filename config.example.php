<?php
declare(strict_types=1);

return [
    'database' => [
        'dsn' => getenv('SCHOOL_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=school;charset=utf8mb4',
        'user' => getenv('SCHOOL_DB_USER') ?: 'root',
        'password' => getenv('SCHOOL_DB_PASSWORD') ?: '',
    ],
    'telegram' => [
        'username' => '',
        'token' => '',
        'secret' => '',
        'url' => '',
    ],
    'admin' => [
        'login' => 'admin',
        'initial_password' => '',
    ],
];
