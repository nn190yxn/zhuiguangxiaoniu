<?php
declare(strict_types=1);

final class IdentityContextService
{
    public function __construct(private PDO $db)
    {
    }

    public function current(int $userId, array $staffContext, ?array $staff): array
    {
        $stmt = $this->db->prepare(
            'SELECT ID AS user_id, user_login, display_name, user_pass FROM wp_users WHERE ID = ?'
        );
        $stmt->execute([$userId]);
        $wpUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wpUser) {
            throw new PlatformApiException(404, 'user_not_found', '用户不存在');
        }

        $mustChangePassword = $this->usesDefaultPassword(
            (string)($wpUser['user_login'] ?? ''),
            (string)($wpUser['user_pass'] ?? '')
        );

        return [
            'user_id' => (int)$wpUser['user_id'],
            'username' => (string)$wpUser['user_login'],
            'display_name' => (string)$wpUser['display_name'],
            'role' => (string)($staffContext['role'] ?? 'staff'),
            'is_manager' => !empty($staffContext['is_manager']) || !empty($staffContext['is_admin']),
            'is_admin' => !empty($staffContext['is_admin']),
            'is_hq' => !empty($staffContext['is_hq']),
            'permissions' => $staffContext['permissions'] ?? [],
            'must_change_password' => $mustChangePassword,
            'staff' => $staff ? [
                'id' => (int)$staff['id'],
                'name' => (string)$staff['name'],
                'role' => (string)$staff['role'],
                'phone' => (string)$staff['phone'],
                'store_id' => (int)$staff['store_id'],
            ] : null,
        ];
    }

    private function usesDefaultPassword(string $username, string $passwordHash): bool
    {
        if (preg_match('/^1[3-9]\d{9}$/', strtolower($username)) !== 1 || $passwordHash === '') {
            return false;
        }
        if (str_starts_with($passwordHash, '$wp')) {
            $encodedDefault = base64_encode(hash_hmac('sha384', '123456', 'wp-sha384', true));
            return password_verify($encodedDefault, substr($passwordHash, 3));
        }
        return password_verify('123456', $passwordHash);
    }
}
