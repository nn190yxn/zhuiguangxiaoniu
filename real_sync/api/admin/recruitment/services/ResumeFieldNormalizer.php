<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeProfileSchema.php';

final class ResumeFieldNormalizer
{
    public function deterministicProfile(array $pages): array
    {
        $profile = ResumeProfileSchema::emptyProfile();
        foreach ($pages as $page) {
            $pageNo = (int) ($page['page_no'] ?? 0);
            $text = (string) ($page['text'] ?? '');
            if ($profile['phone']['value'] === '' && preg_match('/(?<!\d)(?:\+?86[- ]?)?(1[3-9]\d)[- ]?(\d{4})[- ]?(\d{4})(?!\d)/', $text, $match) === 1) {
                $phone = $match[1] . $match[2] . $match[3];
                $profile['phone'] = $this->scalar($phone, 0.98, $pageNo, $match[0]);
            }
            if ($profile['email']['value'] === '' && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $match) === 1) {
                $profile['email'] = $this->scalar(strtolower($match[0]), 0.98, $pageNo, $match[0]);
            }
            if ($profile['name']['value'] === '' && preg_match('/(?:姓名|姓\s*名)\s*[:：]?\s*([\x{4e00}-\x{9fa5}·]{2,20})/u', $text, $match) === 1) {
                $profile['name'] = $this->scalar($match[1], 0.9, $pageNo, $match[0]);
            }
        }
        return ResumeProfileSchema::validate($profile, $pages);
    }

    public function protectPhone(string $phone): array
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($normalized) === 13 && str_starts_with($normalized, '86')) {
            $normalized = substr($normalized, 2);
        }
        if (preg_match('/^1[3-9]\d{9}$/', $normalized) !== 1) {
            return ['normalized' => '', 'ciphertext' => null, 'display_ciphertext' => null, 'lookup_hash' => null, 'masked' => '待补充', 'key_version' => null];
        }
        $key = $this->encryptionKey();
        $hmacKey = $this->hmacKey();
        return [
            'normalized' => $normalized,
            'ciphertext' => $this->encrypt($normalized, $key),
            'display_ciphertext' => $this->encrypt(substr($normalized, 0, 3) . '****' . substr($normalized, -4), $key),
            'lookup_hash' => hash_hmac('sha256', $normalized, $hmacKey),
            'masked' => substr($normalized, 0, 3) . '****' . substr($normalized, -4),
            'key_version' => $this->configuration('RECRUITMENT_PII_KEY_VERSION', 'v1'),
        ];
    }

    public function protectProfile(array $profile): array
    {
        $phone = (string) ($profile['phone']['value'] ?? '');
        if ($phone !== '' && empty($profile['phone']['protected'])) {
            $protected = $this->protectPhone($phone);
            unset($protected['normalized']);
            $profile['phone']['value'] = $protected['masked'];
            $profile['phone']['protected'] = $protected;
        }
        $email = strtolower(trim((string) ($profile['email']['value'] ?? '')));
        if ($email !== '' && empty($profile['email']['protected']) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            [$local, $domain] = explode('@', $email, 2);
            $masked = mb_substr($local, 0, 1, 'UTF-8') . '***@' . $domain;
            $profile['email']['value'] = $masked;
            $profile['email']['protected'] = [
                'ciphertext' => $this->encrypt($email, $this->encryptionKey()),
                'lookup_hash' => hash_hmac('sha256', $email, $this->hmacKey()),
                'masked' => $masked,
                'key_version' => $this->configuration('RECRUITMENT_PII_KEY_VERSION', 'v1'),
            ];
        }
        return $profile;
    }

    public function decrypt(?string $ciphertext): string
    {
        if ($ciphertext === null || $ciphertext === '') {
            return '';
        }
        $decoded = json_decode(base64_decode($ciphertext, true) ?: '', true);
        if (!is_array($decoded)) {
            throw new RecruitmentAdminException('敏感字段密文格式无效', 500);
        }
        $iv = base64_decode((string) ($decoded['iv'] ?? ''), true);
        $tag = base64_decode((string) ($decoded['tag'] ?? ''), true);
        $data = base64_decode((string) ($decoded['data'] ?? ''), true);
        $plain = openssl_decrypt($data ?: '', 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv ?: '', $tag ?: '');
        if (!is_string($plain)) {
            throw new RecruitmentAdminException('敏感字段解密失败', 500);
        }
        return $plain;
    }

    private function scalar(string $value, float $confidence, int $pageNo, string $evidence): array
    {
        return ['value' => trim($value), 'confidence' => $confidence, 'evidence' => [['page_no' => $pageNo, 'text' => trim($evidence)]], 'status' => 'verified'];
    }

    private function encrypt(string $plain, string $key): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($encrypted)) {
            throw new RecruitmentAdminException('敏感字段加密失败', 500);
        }
        return base64_encode(json_encode([
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($encrypted),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function encryptionKey(): string
    {
        $configured = $this->configuration('RECRUITMENT_PII_KEY');
        if ($configured === '') {
            throw new RecruitmentAdminException('RECRUITMENT_PII_KEY 尚未配置', 503);
        }
        $decoded = base64_decode($configured, true);
        $key = is_string($decoded) && strlen($decoded) >= 32 ? $decoded : $configured;
        if (strlen($key) < 32) {
            throw new RecruitmentAdminException('RECRUITMENT_PII_KEY 长度必须至少为 32 字节', 503);
        }
        return hash('sha256', $key, true);
    }

    private function hmacKey(): string
    {
        $key = $this->configuration('RECRUITMENT_PII_HMAC_KEY');
        if (strlen($key) < 32) {
            throw new RecruitmentAdminException('RECRUITMENT_PII_HMAC_KEY 长度必须至少为 32 字节', 503);
        }
        return $key;
    }

    private function configuration(string $key, string $default = ''): string
    {
        if (function_exists('configValue')) {
            return trim((string) configValue($key, $default));
        }
        return trim((string) (getenv($key) ?: $default));
    }
}
