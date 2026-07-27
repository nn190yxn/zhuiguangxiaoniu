<?php

declare(strict_types=1);

final class PasswordPolicyValidationException extends RuntimeException {}

final class PasswordPolicy {
    public static function validate(string $password): void {
        $minimumLength = max(8, (int)(getenv('PASSWORD_MIN_LENGTH') ?: 10));
        if (strlen($password) < $minimumLength
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/\d/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new PasswordPolicyValidationException(
                'password must meet the configured length and include uppercase, lowercase, number, and special characters'
            );
        }
    }

    public static function hash(string $password): string {
        self::validate($password);
        $passwordToHash = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        return '$wp' . password_hash($passwordToHash, PASSWORD_BCRYPT);
    }

    public static function generate(): string {
        return 'Aa1!' . bin2hex(random_bytes(8));
    }
}
