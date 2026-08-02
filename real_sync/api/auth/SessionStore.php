<?php
declare(strict_types=1);

interface PlatformSessionStore
{
    public function createSession(array $session, array $refreshToken): void;

    public function findRefreshSession(string $tokenHash): ?array;

    public function rotateRefreshToken(
        string $tokenHash,
        int $sessionVersion,
        array $replacement,
        int $now
    ): array;

    public function revokeSessionFamily(string $familyId, string $reason, int $now): void;
}

final class PlatformPdoSessionStore implements PlatformSessionStore
{
    public function __construct(private PDO $db)
    {
    }

    public function createSession(array $session, array $refreshToken): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO platform_sessions '
                . '(id, family_id, user_id, staff_id, username_snapshot, role_snapshot, client_type, device_id, '
                . 'identity_hash, session_version, status, expires_at, created_at, last_refreshed_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $session['id'],
                $session['family_id'],
                $session['user_id'],
                $session['staff_id'],
                $session['username'],
                $session['role'],
                $session['client_type'],
                $session['device_id'],
                $session['identity_hash'],
                $session['session_version'],
                $session['status'],
                self::dateTime($session['expires_at']),
                self::dateTime($session['created_at']),
                self::dateTime($session['created_at']),
            ]);
            $this->insertRefreshToken($refreshToken);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function findRefreshSession(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT rt.session_id, s.family_id, s.user_id, s.staff_id, s.username_snapshot, s.role_snapshot, '
            . 's.client_type, s.device_id, s.identity_hash, s.session_version, s.status AS session_status, '
            . 'UNIX_TIMESTAMP(s.expires_at) AS session_expires_at '
            . 'FROM platform_refresh_tokens rt '
            . 'INNER JOIN platform_sessions s ON s.id = rt.session_id '
            . 'WHERE rt.token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->sessionFromRow($row) : null;
    }

    public function rotateRefreshToken(
        string $tokenHash,
        int $sessionVersion,
        array $replacement,
        int $now
    ): array {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT rt.id AS refresh_id, rt.session_id, rt.status AS refresh_status, '
                . 'UNIX_TIMESTAMP(rt.expires_at) AS refresh_expires_at, '
                . 's.family_id, s.user_id, s.staff_id, s.username_snapshot, s.role_snapshot, '
                . 's.client_type, s.device_id, s.identity_hash, s.session_version, s.status AS session_status, '
                . 'UNIX_TIMESTAMP(s.expires_at) AS session_expires_at '
                . 'FROM platform_refresh_tokens rt '
                . 'INNER JOIN platform_sessions s ON s.id = rt.session_id '
                . 'WHERE rt.token_hash = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }

            $session = $this->sessionFromRow($row);
            if ($row['refresh_status'] === 'rotated') {
                $this->revokeFamilyInTransaction($row['family_id'], 'refresh_token_reused', $now);
                $this->recordSecurityEvent($session, 'refresh_token_reused', $row['refresh_id'], $now);
                $this->db->commit();
                $session['status'] = 'revoked';
                return ['status' => 'reuse_detected', 'session' => $session];
            }
            if ($row['session_status'] !== 'active' || $row['refresh_status'] !== 'active') {
                $this->db->commit();
                return ['status' => 'revoked', 'session' => $session];
            }
            if ((int)$row['session_version'] !== $sessionVersion) {
                $this->revokeFamilyInTransaction($row['family_id'], 'session_version_changed', $now);
                $this->recordSecurityEvent($session, 'session_version_changed', $row['refresh_id'], $now);
                $this->db->commit();
                return ['status' => 'version_mismatch', 'session' => $session];
            }
            if ((int)$row['refresh_expires_at'] <= $now || (int)$row['session_expires_at'] <= $now) {
                $expire = $this->db->prepare(
                    "UPDATE platform_refresh_tokens SET status = 'expired', revoked_at = ? WHERE id = ?"
                );
                $expire->execute([self::dateTime($now), $row['refresh_id']]);
                $this->db->commit();
                return ['status' => 'expired', 'session' => $session];
            }

            $replacement['session_id'] = $row['session_id'];
            $replacement['expires_at'] = min((int)$replacement['expires_at'], (int)$row['session_expires_at']);
            $this->insertRefreshToken($replacement);
            $rotate = $this->db->prepare(
                "UPDATE platform_refresh_tokens SET status = 'rotated', rotated_at = ?, replaced_by_token_id = ? "
                . "WHERE id = ? AND status = 'active'"
            );
            $rotate->execute([self::dateTime($now), $replacement['id'], $row['refresh_id']]);
            if ($rotate->rowCount() !== 1) {
                throw new RuntimeException('Refresh token rotation lost its lock');
            }
            $touch = $this->db->prepare('UPDATE platform_sessions SET last_refreshed_at = ? WHERE id = ?');
            $touch->execute([self::dateTime($now), $row['session_id']]);
            $this->db->commit();
            return ['status' => 'rotated', 'session' => $session];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function revokeSessionFamily(string $familyId, string $reason, int $now): void
    {
        $this->db->beginTransaction();
        try {
            $this->revokeFamilyInTransaction($familyId, $reason, $now);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function insertRefreshToken(array $token): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO platform_refresh_tokens '
            . '(id, session_id, token_hash, status, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $token['id'],
            $token['session_id'],
            $token['token_hash'],
            $token['status'],
            self::dateTime($token['expires_at']),
            self::dateTime($token['created_at']),
        ]);
    }

    private function revokeFamilyInTransaction(string $familyId, string $reason, int $now): void
    {
        $revokedAt = self::dateTime($now);
        $sessions = $this->db->prepare(
            "UPDATE platform_sessions SET status = 'revoked', revoked_at = ?, revoke_reason = ? "
            . "WHERE family_id = ? AND status = 'active'"
        );
        $sessions->execute([$revokedAt, mb_substr($reason, 0, 80), $familyId]);
        $tokens = $this->db->prepare(
            "UPDATE platform_refresh_tokens rt INNER JOIN platform_sessions s ON s.id = rt.session_id "
            . "SET rt.status = 'revoked', rt.revoked_at = ? "
            . "WHERE s.family_id = ? AND rt.status = 'active'"
        );
        $tokens->execute([$revokedAt, $familyId]);
    }

    private function recordSecurityEvent(array $session, string $eventType, string $refreshTokenId, int $now): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO platform_security_events '
            . '(event_type, user_id, staff_id, session_id, family_id, refresh_token_id, client_type, event_data_json, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventType,
            $session['user_id'],
            $session['staff_id'],
            $session['id'],
            $session['family_id'],
            $refreshTokenId,
            $session['client_type'],
            json_encode(['reason' => $eventType], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::dateTime($now),
        ]);
    }

    private function sessionFromRow(array $row): array
    {
        return [
            'id' => (string)$row['session_id'],
            'family_id' => (string)$row['family_id'],
            'user_id' => (int)$row['user_id'],
            'staff_id' => $row['staff_id'] === null ? null : (int)$row['staff_id'],
            'username' => (string)$row['username_snapshot'],
            'role' => (string)$row['role_snapshot'],
            'client_type' => (string)$row['client_type'],
            'device_id' => (string)($row['device_id'] ?? ''),
            'identity_hash' => (string)($row['identity_hash'] ?? ''),
            'session_version' => (int)$row['session_version'],
            'status' => (string)$row['session_status'],
            'expires_at' => (int)$row['session_expires_at'],
        ];
    }

    private static function dateTime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
