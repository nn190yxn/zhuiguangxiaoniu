<?php

class GatewaySignature
{
    const HEADER_VERSION = 'HTTP_X_CLOUD_SIGNATURE_VERSION';
    const HEADER_TIMESTAMP = 'HTTP_X_CLOUD_TIMESTAMP';
    const HEADER_NONCE = 'HTTP_X_CLOUD_NONCE';
    const HEADER_BODY_SHA256 = 'HTTP_X_CLOUD_BODY_SHA256';
    const HEADER_SIGNATURE = 'HTTP_X_CLOUD_SIGNATURE';
    const DEFAULT_WINDOW_SECONDS = 300;

    public static function bodyHash($body)
    {
        return hash('sha256', (string)$body);
    }

    public static function canonicalString($method, $path, $timestamp, $nonce, $bodyHash, $version)
    {
        return implode("\n", [
            strtoupper((string)$method),
            (string)$path,
            (string)$timestamp,
            (string)$nonce,
            strtolower((string)$bodyHash),
            (string)$version,
        ]);
    }

    public static function sign($secret, $method, $path, $timestamp, $nonce, $bodyHash, $version = 'v1')
    {
        $canonical = self::canonicalString($method, $path, $timestamp, $nonce, $bodyHash, $version);
        return hash_hmac('sha256', $canonical, (string)$secret);
    }

    public static function verify($method, $path, $body, array $server, $secret, array $options = [])
    {
        $version = isset($server[self::HEADER_VERSION]) ? (string)$server[self::HEADER_VERSION] : '';
        $timestamp = isset($server[self::HEADER_TIMESTAMP]) ? (string)$server[self::HEADER_TIMESTAMP] : '';
        $nonce = isset($server[self::HEADER_NONCE]) ? (string)$server[self::HEADER_NONCE] : '';
        $bodyHash = isset($server[self::HEADER_BODY_SHA256]) ? strtolower((string)$server[self::HEADER_BODY_SHA256]) : '';
        $signature = isset($server[self::HEADER_SIGNATURE]) ? strtolower((string)$server[self::HEADER_SIGNATURE]) : '';
        $now = isset($options['now']) ? (int)$options['now'] : time();
        $window = isset($options['window_seconds']) ? (int)$options['window_seconds'] : self::DEFAULT_WINDOW_SECONDS;

        if ($secret === '') {
            return ['ok' => false, 'code' => 'gateway_secret_missing'];
        }
        if ($version !== 'v1') {
            return ['ok' => false, 'code' => 'signature_version_invalid'];
        }
        if (!ctype_digit($timestamp) || abs($now - (int)$timestamp) > $window) {
            return ['ok' => false, 'code' => 'signature_timestamp_invalid'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $nonce)) {
            return ['ok' => false, 'code' => 'signature_nonce_invalid'];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $bodyHash) || !hash_equals(self::bodyHash($body), $bodyHash)) {
            return ['ok' => false, 'code' => 'signature_body_hash_invalid'];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return ['ok' => false, 'code' => 'signature_invalid'];
        }

        if (isset($options['nonce_store']) && is_callable($options['nonce_store'])) {
            $accepted = call_user_func($options['nonce_store'], $nonce, (int)$timestamp);
            if ($accepted !== true) {
                return ['ok' => false, 'code' => 'signature_nonce_replayed'];
            }
        }

        $expected = self::sign($secret, $method, $path, $timestamp, $nonce, $bodyHash, $version);
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'code' => 'signature_mismatch'];
        }

        return ['ok' => true, 'code' => 'ok'];
    }
}
