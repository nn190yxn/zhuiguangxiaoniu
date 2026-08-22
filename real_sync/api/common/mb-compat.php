<?php
declare(strict_types=1);

if (!function_exists('app_mb_chars')) {
    function app_mb_chars(string $value): array {
        if ($value === '') {
            return array();
        }
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? $chars : str_split($value);
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return count(app_mb_chars($string));
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        $chars = app_mb_chars($string);
        $count = count($chars);
        if ($start < 0) {
            $start = max(0, $count + $start);
        }
        $slice = $length === null
            ? array_slice($chars, $start)
            : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}

if (!function_exists('mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false {
        if ($needle === '') {
            return false;
        }
        $haystackChars = app_mb_chars($haystack);
        $needleChars = app_mb_chars($needle);
        $haystackLength = count($haystackChars);
        $needleLength = count($needleChars);
        if ($offset < 0) {
            $offset = max(0, $haystackLength + $offset);
        }
        for ($index = $offset; $index <= $haystackLength - $needleLength; $index++) {
            if (array_slice($haystackChars, $index, $needleLength) === $needleChars) {
                return $index;
            }
        }
        return false;
    }
}

if (!function_exists('mb_stripos')) {
    function mb_stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false {
        return mb_strpos(strtolower($haystack), strtolower($needle), $offset, $encoding);
    }
}
