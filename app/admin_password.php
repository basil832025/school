<?php
declare(strict_types=1);

function adminPasswordHash(): string
{
    $password = $_POST['password'] ?? '';
    if (!is_string($password) || mb_strlen($password) < 8 || strlen($password) > 72
        || str_contains($password, "\0") || trim($password) === '') {
        throw new DomainException('Введіть новий пароль від 8 символів, до 72 байтів.');
    }
    if ($password !== ($_POST['confirm_password'] ?? null)) {
        throw new DomainException('Паролі мають збігатися.');
    }
    return password_hash($password, PASSWORD_DEFAULT);
}
