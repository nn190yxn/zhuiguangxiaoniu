<?php
declare(strict_types=1);

require_once __DIR__ . '/SessionFactory.php';

function platformMiniProgramIdentityHash(string $provider, string $identity): string
{
    $provider = strtolower(trim($provider));
    $identity = trim($identity);
    if (!in_array($provider, ['wechat', 'wecom'], true) || $identity === '') {
        return '';
    }
    return hash('sha256', $provider . ':' . $identity);
}

function platformMiniProgramIdentity(array $staff, string $preferredProvider = ''): array
{
    $preferredProvider = strtolower(trim($preferredProvider));
    $candidates = $preferredProvider === 'wecom'
        ? [['wecom', $staff['wecom_userid'] ?? ''], ['wechat', $staff['openid'] ?? '']]
        : [['wechat', $staff['openid'] ?? ''], ['wecom', $staff['wecom_userid'] ?? '']];

    foreach ($candidates as [$provider, $value]) {
        $hash = platformMiniProgramIdentityHash($provider, (string)$value);
        if ($hash !== '') {
            return ['provider' => $provider, 'hash' => $hash];
        }
    }
    return ['provider' => '', 'hash' => ''];
}

function platformIssueMiniProgramSession(
    PDO $db,
    array $staff,
    string $username,
    string $role,
    string $deviceId,
    string $preferredProvider = ''
): ?array {
    $identity = platformMiniProgramIdentity($staff, $preferredProvider);
    if (empty($staff['id']) || $identity['hash'] === '') {
        return null;
    }

    return platformSessionService($db)->issue([
        'user_id' => (int)$staff['user_id'],
        'staff_id' => (int)$staff['id'],
        'username' => $username,
        'role' => $role,
        'session_version' => (int)($staff['session_version'] ?? 0),
    ], 'mini_program', $deviceId, $identity['hash']);
}

function platformMiniProgramResponse(array $session): array
{
    return [
        'token' => $session['access_token'],
        'refresh_token' => $session['refresh_token'],
        'expire' => $session['access_expires_in'],
        'refresh_expire' => $session['refresh_expires_in'],
        'session_id' => $session['session_id'],
        'session_version' => $session['session_version'],
        'session_type' => 'device',
    ];
}

function platformValidateMiniProgramSession(PDO $db, array $session, string $deviceId): array
{
    if (($session['client_type'] ?? '') !== 'mini_program') {
        throw new PlatformApiException(401, 'invalid_session_client', '会话客户端不匹配，请重新登录');
    }
    if ($deviceId === '' || !hash_equals((string)($session['device_id'] ?? ''), $deviceId)) {
        throw new PlatformApiException(401, 'device_changed', '登录设备已变化，请重新认证');
    }

    $stmt = $db->prepare(
        'SELECT s.*, u.user_login, u.user_status FROM staffs s '
        . 'INNER JOIN wp_users u ON u.ID = s.user_id WHERE s.id = ? AND s.user_id = ? LIMIT 1'
    );
    $stmt->execute([(int)($session['staff_id'] ?? 0), (int)($session['user_id'] ?? 0)]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff || (int)$staff['status'] !== 1 || (int)$staff['user_status'] !== 0
        || (string)($staff['lifecycle_status'] ?? 'active') !== 'active') {
        throw new PlatformApiException(401, 'account_unavailable', '账号状态已变化，请重新登录');
    }

    $storedHash = (string)($session['identity_hash'] ?? '');
    $identityHashes = array_filter([
        platformMiniProgramIdentityHash('wechat', (string)($staff['openid'] ?? '')),
        platformMiniProgramIdentityHash('wecom', (string)($staff['wecom_userid'] ?? '')),
    ]);
    $identityMatches = false;
    foreach ($identityHashes as $identityHash) {
        if ($storedHash !== '' && hash_equals($storedHash, $identityHash)) {
            $identityMatches = true;
            break;
        }
    }
    if (!$identityMatches) {
        throw new PlatformApiException(401, 'wechat_identity_changed', '微信身份已变化，请重新认证');
    }

    return $staff;
}
