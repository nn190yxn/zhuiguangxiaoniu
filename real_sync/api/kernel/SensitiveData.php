<?php
declare(strict_types=1);

final class PlatformSensitiveData
{
    private const SECRET_KEYS = [
        'password', 'passwd', 'token', 'jwt', 'authorization', 'cookie', 'secret',
        'api_key', 'apikey', 'credential', 'csrf',
    ];
    private const PHONE_KEYS = ['phone', 'mobile', 'telephone'];
    private const IDENTIFIER_KEYS = ['openid', 'unionid', 'wecom_userid', 'external_userid'];
    private const CONTENT_KEYS = [
        'resume', 'curriculum_vitae', 'recording', 'audio', 'transcript',
        'provider_response', 'vendor_response', 'raw_response',
    ];

    public static function sanitize(mixed $value, string $field = ''): mixed
    {
        $normalizedField = strtolower($field);
        if (self::containsAny($normalizedField, self::SECRET_KEYS)) {
            return '[REDACTED]';
        }
        if (self::containsAny($normalizedField, self::PHONE_KEYS)) {
            return self::maskPhone((string)$value);
        }
        if (self::containsAny($normalizedField, self::IDENTIFIER_KEYS)) {
            return self::summary($value, 'identifier');
        }
        if (self::containsAny($normalizedField, self::CONTENT_KEYS)) {
            return self::summary($value, 'content');
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = self::sanitize($item, (string)$key);
            }
            return $sanitized;
        }
        if (is_object($value)) {
            return self::summary(get_class($value), 'object');
        }
        if (is_resource($value)) {
            return ['redacted' => true, 'type' => 'resource'];
        }
        if (is_string($value)) {
            return self::sanitizeString($value);
        }
        return $value;
    }

    public static function summary(mixed $value, string $type = 'content'): array
    {
        if (is_string($value)) {
            $serialized = $value;
        } else {
            $serialized = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($serialized === false) {
                $serialized = get_debug_type($value);
            }
        }
        return [
            'redacted' => true,
            'type' => $type,
            'sha256' => hash('sha256', $serialized),
            'bytes' => strlen($serialized),
        ];
    }

    private static function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 7) {
            return '[REDACTED]';
        }
        return substr($digits, 0, 3) . '****' . substr($digits, -4);
    }

    private static function sanitizeString(string $value): string
    {
        $value = preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace_callback('/(?<!\d)1[3-9]\d{9}(?!\d)/', static fn(array $match): string => self::maskPhone($match[0]), $value) ?? $value;
        return $value;
    }

    private static function containsAny(string $field, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($field, $needle)) {
                return true;
            }
        }
        return false;
    }
}
