<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillMediaStorageAdapter.php';

final class DrillMediaService
{
    private const MAX_ASSET_BYTES = 52428800;
    private const MAX_CHUNK_BYTES = 5242880;
    private const ALLOWED_MIME_TYPES = [
        'audio/aac',
        'audio/mp4',
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
        'audio/x-m4a',
    ];

    private PDO $pdo;
    private DrillMediaStorageAdapter $storage;

    public function __construct(PDO $pdo, ?string $storageRoot = null, ?DrillMediaStorageAdapter $storage = null)
    {
        $this->pdo = $pdo;
        $this->storage = $storage ?? DrillMediaStorageAdapter::create($storageRoot);
    }

    public function createAudioAsset(int $staffId, int $attemptId, array $input, DateTimeImmutable $now): array
    {
        $mimeType = $this->normalizeMimeType((string) ($input['mime_type'] ?? ''));
        $byteSize = $this->positiveInt($input['byte_size'] ?? 0, '音频大小无效。');
        $checksum = $this->checksum((string) ($input['checksum'] ?? ''), '音频摘要无效。');
        $durationMs = $this->nullablePositiveInt($input['duration_ms'] ?? null, '音频时长无效。');
        $assetType = $this->limitedCode((string) ($input['asset_type'] ?? 'turn_recording'), '音频类型无效。', 32);
        $purposeCode = $this->limitedCode((string) ($input['purpose_code'] ?? 'drill_training'), '音频用途无效。', 64);
        $consentStatus = $this->limitedCode((string) ($input['consent_status'] ?? 'not_required'), '授权状态无效。', 32);
        $consentBasis = trim((string) ($input['consent_basis'] ?? ''));
        $retentionDays = $this->positiveInt($input['retention_days'] ?? 180, '留存天数无效。');
        $accessScope = $this->accessScope((array) ($input['access_scope'] ?? ['owner' => true, 'reviewer' => true]));

        if ($byteSize > self::MAX_ASSET_BYTES) {
            throw new DomainException('音频文件不能超过 50MB。');
        }
        if ($consentStatus === 'granted' && $consentBasis === '') {
            throw new DomainException('真实录音授权依据不能为空。');
        }

        return $this->transaction(function () use ($staffId, $attemptId, $mimeType, $byteSize, $checksum, $durationMs, $assetType, $purposeCode, $consentStatus, $consentBasis, $retentionDays, $accessScope, $now): array {
            $attempt = $this->ownedAttempt($attemptId, $staffId);
            $this->assertRealCallReviewConsentInput($attempt, $consentStatus, $consentBasis, $purposeCode, $accessScope);
            $existing = $this->findAudioAssetByChecksum($attemptId, $checksum);
            if ($existing !== null) {
                return $this->normalizeAudioAsset($existing, true);
            }

            $retentionUntil = $now->modify('+' . $retentionDays . ' days');
            $consentValidUntil = $consentStatus === 'granted' ? $retentionUntil->format('Y-m-d H:i:s') : null;
            $relativePath = $this->storage->createAssetMarker([
                'attempt_id' => (int) $attempt['id'],
                'status' => 'uploading',
            ], $now);
            $stmt = $this->pdo->prepare(
                'INSERT INTO drill_audio_assets (attempt_id, staff_id, asset_type, storage_path, public_locator, mime_type, byte_size, duration_ms, checksum, consent_status, consent_basis, purpose_code, access_scope_json, consent_valid_until, retention_until, status) '
                . 'VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $attemptId,
                $staffId,
                $assetType,
                $relativePath,
                $mimeType,
                $byteSize,
                $durationMs,
                $checksum,
                $consentStatus,
                $consentBasis === '' ? null : $consentBasis,
                $purposeCode,
                $this->json($accessScope),
                $consentValidUntil,
                $retentionUntil->format('Y-m-d H:i:s'),
                'uploading',
            ]);

            $assetId = (int) $this->pdo->lastInsertId();
            $asset = $this->lockAudioAsset($assetId, $staffId, false);
            return $this->normalizeAudioAsset($asset, false);
        });
    }

    public function uploadChunk(int $staffId, int $audioAssetId, array $input, DateTimeImmutable $now): array
    {
        $chunkNo = $this->positiveInt($input['chunk_no'] ?? 0, '分片序号无效。');
        $checksum = $this->checksum((string) ($input['checksum'] ?? ''), '分片摘要无效。');
        $byteSize = $this->positiveInt($input['byte_size'] ?? 0, '分片大小无效。');
        $contentBase64 = trim((string) ($input['content_base64'] ?? ''));
        $partialTranscript = trim((string) ($input['transcript_text'] ?? ''));
        $provider = $this->limitedCode((string) ($input['provider'] ?? 'manual'), '转写服务无效。', 64);
        $model = $this->nullableLimitedText($input['model'] ?? null, 128);
        $confidence = $this->nullableConfidence($input['confidence'] ?? null);
        $rawResponseRef = $this->nullableLimitedText($input['raw_response_ref'] ?? null, 500);
        if ($byteSize > self::MAX_CHUNK_BYTES) {
            throw new DomainException('单个音频分片不能超过 5MB。');
        }
        if ($contentBase64 === '') {
            throw new DomainException('分片内容不能为空。');
        }
        $binary = base64_decode(preg_replace('/\s+/', '', $contentBase64) ?? '', true);
        if ($binary === false) {
            throw new DomainException('分片 base64 内容无效。');
        }
        if (strlen($binary) !== $byteSize) {
            throw new DomainException('分片大小与声明不一致。');
        }
        if (hash('sha256', $binary) !== $checksum) {
            throw new DomainException('分片摘要与内容不一致。');
        }

        return $this->transaction(function () use ($staffId, $audioAssetId, $chunkNo, $checksum, $byteSize, $binary, $partialTranscript, $provider, $model, $confidence, $rawResponseRef, $now): array {
            $asset = $this->lockAudioAsset($audioAssetId, $staffId, true);
            if (!in_array((string) $asset['status'], ['uploading', 'uploaded', 'transcription_failed', 'transcription_timeout'], true)) {
                throw new DomainException('当前音频资源状态不允许上传分片。');
            }
            if ($partialTranscript !== '') {
                $this->assertTranscriptionReady($asset, $now);
            }

            $existing = $this->findChunk($audioAssetId, $chunkNo);
            if ($existing !== null) {
                if ((string) $existing['checksum'] !== $checksum || (int) $existing['byte_size'] !== $byteSize) {
                    throw new DomainException('同一分片序号已存在不同内容。');
                }
                return $this->normalizeChunk($existing, true);
            }

            $relativePath = $this->storage->storeChunk($binary, $now);
            $stmt = $this->pdo->prepare(
                'INSERT INTO drill_audio_chunks (audio_asset_id, chunk_no, checksum, byte_size, storage_path, status, received_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$audioAssetId, $chunkNo, $checksum, $byteSize, $relativePath, 'received', $now->format('Y-m-d H:i:s')]);
            $chunk = $this->findChunk($audioAssetId, $chunkNo);
            $result = $this->normalizeChunk($chunk ?? [], false);
            if ($partialTranscript !== '') {
                $result['partial_transcript'] = $this->upsertPartialTranscript($asset, $chunkNo, $checksum, $partialTranscript, $provider, $model, $confidence, $rawResponseRef, $now);
            }
            return $result;
        });
    }

    public function finalizeTranscription(int $staffId, int $audioAssetId, array $input, DateTimeImmutable $now): array
    {
        $expectedChunks = $this->nullablePositiveInt($input['expected_chunks'] ?? null, '分片数量无效。');
        $provider = $this->limitedCode((string) ($input['provider'] ?? 'manual'), '转写服务无效。', 64);
        $model = $this->nullableLimitedText($input['model'] ?? null, 128);
        $confidence = $this->nullableConfidence($input['confidence'] ?? null);
        $rawResponseRef = $this->nullableLimitedText($input['raw_response_ref'] ?? null, 500);
        $finalTranscript = trim((string) ($input['final_transcript_text'] ?? ''));

        return $this->transaction(function () use ($staffId, $audioAssetId, $expectedChunks, $provider, $model, $confidence, $rawResponseRef, $finalTranscript, $now): array {
            $asset = $this->lockAudioAsset($audioAssetId, $staffId, true);
            if (!in_array((string) $asset['status'], ['uploading', 'uploaded', 'transcription_failed', 'transcription_timeout'], true)) {
                throw new DomainException('当前音频资源状态不允许完成转写。');
            }
            $this->assertTranscriptionReady($asset, $now);

            $chunks = $this->listChunks($audioAssetId);
            if ($chunks === []) {
                throw new DomainException('音频分片不能为空。');
            }
            $missing = $this->missingChunkNumbers($chunks, $expectedChunks);
            if ($missing !== []) {
                throw new DomainException('音频分片不完整，请重传缺失分片：' . implode(',', $missing) . '。');
            }

            $content = $finalTranscript !== '' ? $finalTranscript : $this->composePartialTranscript($audioAssetId, $chunks);
            if ($content === '') {
                throw new DomainException('最终转写内容不能为空。');
            }

            $transcript = $this->upsertTranscript(
                (int) $asset['attempt_id'],
                $audioAssetId,
                'final',
                $provider,
                $model,
                $content,
                $confidence,
                'completed',
                $rawResponseRef,
                $now
            );
            $this->pdo->prepare('UPDATE drill_audio_assets SET status = ? WHERE id = ?')->execute(['uploaded', $audioAssetId]);
            $this->updateAudioTurnTranscriptionStatus((int) $asset['attempt_id'], $audioAssetId, 'completed');

            return [
                'audio_asset_id' => $audioAssetId,
                'attempt_id' => (int) $asset['attempt_id'],
                'transcript_id' => (int) $transcript['id'],
                'status' => (string) $transcript['status'],
                'transcript_type' => (string) $transcript['transcript_type'],
                'content' => (string) $transcript['content'],
                'chunk_count' => count($chunks),
                'chunk_numbers' => array_map(static fn(array $chunk): int => (int) $chunk['chunk_no'], $chunks),
                'completed_at' => (string) $transcript['completed_at'],
            ];
        });
    }

    public function recoverTranscription(int $staffId, int $audioAssetId, array $input, DateTimeImmutable $now): array
    {
        $action = $this->limitedCode((string) ($input['action'] ?? ''), '恢复操作无效。', 32);
        $reason = $this->nullableLimitedText($input['reason'] ?? null, 500);
        $textContent = trim((string) ($input['text_content'] ?? ''));
        if (!in_array($action, ['mark_failed', 'mark_timeout', 'retry', 'text_fallback'], true)) {
            throw new DomainException('恢复操作不支持。');
        }
        if ($action === 'text_fallback' && $textContent === '') {
            throw new DomainException('文本降级内容不能为空。');
        }

        return $this->transaction(function () use ($staffId, $audioAssetId, $action, $reason, $textContent, $now): array {
            $asset = $this->lockAudioAsset($audioAssetId, $staffId, true);
            $this->assertTranscriptionReady($asset, $now);
            $partial = $this->findTranscript($audioAssetId, 'partial');
            $partialContent = $partial ? $this->composePartialTranscript($audioAssetId, $this->listChunks($audioAssetId)) : '';
            $provider = $action === 'text_fallback' ? 'manual' : 'recovery';
            $status = match ($action) {
                'mark_failed' => 'failed',
                'mark_timeout' => 'timeout',
                'retry' => 'pending',
                default => 'completed',
            };
            $assetStatus = match ($action) {
                'mark_failed' => 'transcription_failed',
                'mark_timeout' => 'transcription_timeout',
                'retry' => 'uploading',
                default => 'uploaded',
            };
            $content = $action === 'text_fallback' ? $textContent : $partialContent;
            $transcript = $this->upsertTranscript(
                (int) $asset['attempt_id'],
                $audioAssetId,
                'final',
                $provider,
                null,
                $content,
                null,
                $status,
                $reason,
                $now
            );
            $this->pdo->prepare('UPDATE drill_audio_assets SET status = ? WHERE id = ?')->execute([$assetStatus, $audioAssetId]);
            $turnStatus = match ($action) {
                'mark_failed' => 'transcription_failed',
                'mark_timeout' => 'transcription_timeout',
                'retry' => 'retry_available',
                default => 'text_fallback',
            };
            $this->updateAudioTurnTranscriptionStatus((int) $asset['attempt_id'], $audioAssetId, $turnStatus);

            return [
                'audio_asset_id' => $audioAssetId,
                'attempt_id' => (int) $asset['attempt_id'],
                'action' => $action,
                'audio_status' => $assetStatus,
                'transcription_status' => $turnStatus,
                'transcript_id' => (int) $transcript['id'],
                'transcript_status' => (string) $transcript['status'],
                'content' => (string) $transcript['content'],
                'preserved_chunk_count' => count($this->listChunks($audioAssetId)),
                'can_retry' => in_array($action, ['mark_failed', 'mark_timeout', 'retry'], true),
                'can_use_text_fallback' => $action !== 'text_fallback',
            ];
        });
    }

    public function accessAudioAsset(int $actorStaffId, int $audioAssetId, array $actorContext, DateTimeImmutable $now): array
    {
        $asset = $this->findAudioAssetForAccess($audioAssetId);
        if ($asset === null) {
            throw new DomainException('音频资源不存在。');
        }
        try {
            $this->assertAudioAccessAllowed($asset, $actorStaffId, $actorContext, $now);
        } catch (DomainException $error) {
            $this->auditAudioAccess($asset, $actorStaffId, $actorContext, 'denied', $error->getMessage());
            throw $error;
        }
        $this->auditAudioAccess($asset, $actorStaffId, $actorContext, 'allowed', null);

        $downloadUrl = '/api/drill/v2/audio-access.php?audio_asset_id=' . (int) $asset['id'] . '&download=1';
        return [
            'audio_asset_id' => (int) $asset['id'],
            'attempt_id' => (int) $asset['attempt_id'],
            'status' => (string) $asset['status'],
            'mime_type' => (string) $asset['mime_type'],
            'byte_size' => (int) $asset['byte_size'],
            'url' => $downloadUrl,
            'download_url' => $downloadUrl,
            'retention_until' => (string) $asset['retention_until'],
            'consent_status' => (string) $asset['consent_status'],
            'purpose_code' => (string) $asset['purpose_code'],
            'access_scope' => $this->decodeAccessScope((string) $asset['access_scope_json']),
        ];
    }

    public function prepareAudioDownload(int $actorStaffId, int $audioAssetId, array $actorContext, DateTimeImmutable $now): array
    {
        $this->accessAudioAsset($actorStaffId, $audioAssetId, $actorContext, $now);
        $asset = $this->findAudioAssetForAccess($audioAssetId);
        if ($asset === null) {
            throw new DomainException('音频资源不存在。');
        }
        $chunks = $this->listChunksForDownload($audioAssetId);
        if ($chunks === []) {
            throw new DomainException('音频内容尚未完成上传。');
        }
        return $this->storage->prepareDownload($asset, $chunks);
    }

    public function streamPreparedDownload(array $download, bool $emitHeaders = false): void
    {
        $this->storage->stream($download, $emitHeaders);
    }

    public function streamAudioAsset(int $actorStaffId, int $audioAssetId, array $actorContext, DateTimeImmutable $now): void
    {
        $this->streamPreparedDownload(
            $this->prepareAudioDownload($actorStaffId, $audioAssetId, $actorContext, $now),
            true
        );
    }

    public function expireDueAudioAssets(DateTimeImmutable $now, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->transaction(function () use ($now, $limit): array {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM drill_audio_assets WHERE status IN ('uploading', 'uploaded', 'transcription_failed', 'transcription_timeout') AND retention_until <= ? ORDER BY retention_until ASC LIMIT " . $limit . ' FOR UPDATE'
            );
            $stmt->execute([$now->format('Y-m-d H:i:s')]);
            $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $expiredIds = [];
            $cleanup = [];
            foreach ($assets as $asset) {
                $chunks = $this->listChunksForDownload((int) $asset['id']);
                $cleanup[(int) $asset['id']] = $this->storage->cleanup($asset, $chunks);
                $update = $this->pdo->prepare('UPDATE drill_audio_assets SET status = ?, expired_at = ? WHERE id = ?');
                $update->execute(['expired', $now->format('Y-m-d H:i:s'), (int) $asset['id']]);
                $expiredIds[] = (int) $asset['id'];
            }

            return [
                'expired_count' => count($expiredIds),
                'expired_audio_asset_ids' => $expiredIds,
                'physical_cleanup' => $cleanup,
            ];
        });
    }

    private function ownedAttempt(int $attemptId, int $staffId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_attempts WHERE id = ? AND staff_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$attemptId, $staffId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new DomainException('演练实例不存在或不属于当前员工。');
        }
        return $attempt;
    }

    private function lockAudioAsset(int $audioAssetId, int $staffId, bool $forUpdate): array
    {
        $sql = 'SELECT audio.*, attempt.evaluation_context FROM drill_audio_assets audio INNER JOIN drill_attempts attempt ON attempt.id = audio.attempt_id WHERE audio.id = ? AND audio.staff_id = ? AND attempt.staff_id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$audioAssetId, $staffId, $staffId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            throw new DomainException('音频资源不存在或不属于当前员工。');
        }
        return $asset;
    }

    private function findAudioAssetByChecksum(int $attemptId, string $checksum): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_audio_assets WHERE attempt_id = ? AND checksum = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$attemptId, $checksum]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        return $asset ?: null;
    }

    private function findAudioAssetForAccess(int $audioAssetId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT audio.*, attempt.evaluation_context FROM drill_audio_assets audio INNER JOIN drill_attempts attempt ON attempt.id = audio.attempt_id WHERE audio.id = ? LIMIT 1'
        );
        $stmt->execute([$audioAssetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        return $asset ?: null;
    }

    private function findChunk(int $audioAssetId, int $chunkNo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_audio_chunks WHERE audio_asset_id = ? AND chunk_no = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$audioAssetId, $chunkNo]);
        $chunk = $stmt->fetch(PDO::FETCH_ASSOC);
        return $chunk ?: null;
    }

    private function listChunks(int $audioAssetId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_audio_chunks WHERE audio_asset_id = ? ORDER BY chunk_no ASC FOR UPDATE');
        $stmt->execute([$audioAssetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function listChunksForDownload(int $audioAssetId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_audio_chunks WHERE audio_asset_id = ? ORDER BY chunk_no ASC');
        $stmt->execute([$audioAssetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function findTranscript(int $audioAssetId, string $type): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_transcripts WHERE audio_asset_id = ? AND transcript_type = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$audioAssetId, $type]);
        $transcript = $stmt->fetch(PDO::FETCH_ASSOC);
        return $transcript ?: null;
    }

    private function updateAudioTurnTranscriptionStatus(int $attemptId, int $audioAssetId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE drill_turns SET transcription_status = ? WHERE attempt_id = ? AND audio_asset_id = ?'
        );
        $stmt->execute([$status, $attemptId, $audioAssetId]);
    }

    private function upsertPartialTranscript(
        array $asset,
        int $chunkNo,
        string $checksum,
        string $text,
        string $provider,
        ?string $model,
        ?float $confidence,
        ?string $rawResponseRef,
        DateTimeImmutable $now
    ): array {
        $existing = $this->findTranscript((int) $asset['id'], 'partial');
        $payload = $existing ? json_decode((string) $existing['content'], true) : null;
        if (!is_array($payload)) {
            $payload = ['chunks' => []];
        }

        $chunks = [];
        foreach ((array) ($payload['chunks'] ?? []) as $chunk) {
            if (!is_array($chunk) || (int) ($chunk['chunk_no'] ?? 0) === $chunkNo) {
                continue;
            }
            $chunks[] = $chunk;
        }
        $chunks[] = [
            'chunk_no' => $chunkNo,
            'checksum' => $checksum,
            'text' => $text,
            'provider' => $provider,
            'model' => $model,
            'confidence' => $confidence,
            'raw_response_ref' => $rawResponseRef,
            'completed_at' => $now->format('Y-m-d H:i:s'),
        ];
        usort($chunks, static fn(array $left, array $right): int => (int) $left['chunk_no'] <=> (int) $right['chunk_no']);

        $transcript = $this->upsertTranscript(
            (int) $asset['attempt_id'],
            (int) $asset['id'],
            'partial',
            $provider,
            $model,
            $this->json(['chunks' => $chunks, 'merged_text' => $this->mergeTranscriptChunks($chunks)]),
            $confidence,
            'completed',
            $rawResponseRef,
            $now
        );

        return [
            'transcript_id' => (int) $transcript['id'],
            'transcript_type' => 'partial',
            'chunk_no' => $chunkNo,
            'chunk_count' => count($chunks),
            'merged_text' => $this->mergeTranscriptChunks($chunks),
        ];
    }

    private function upsertTranscript(
        int $attemptId,
        int $audioAssetId,
        string $type,
        string $provider,
        ?string $model,
        string $content,
        ?float $confidence,
        string $status,
        ?string $rawResponseRef,
        DateTimeImmutable $now
    ): array {
        $completedAt = $status === 'completed' ? $now->format('Y-m-d H:i:s') : null;
        $existing = $this->findTranscript($audioAssetId, $type);
        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE drill_transcripts SET provider = ?, model = ?, content = ?, confidence = ?, status = ?, raw_response_ref = ?, completed_at = ? WHERE id = ?'
            );
            $stmt->execute([$provider, $model, $content, $confidence, $status, $rawResponseRef, $completedAt, $existing['id']]);
            return $this->findTranscript($audioAssetId, $type) ?? $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO drill_transcripts (attempt_id, audio_asset_id, turn_id, transcript_type, provider, model, content, confidence, status, raw_response_ref, completed_at) '
            . 'VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$attemptId, $audioAssetId, $type, $provider, $model, $content, $confidence, $status, $rawResponseRef, $completedAt]);
        return $this->findTranscript($audioAssetId, $type) ?? [];
    }

    private function composePartialTranscript(int $audioAssetId, array $chunks): string
    {
        $partial = $this->findTranscript($audioAssetId, 'partial');
        if (!$partial) {
            return '';
        }
        $payload = json_decode((string) $partial['content'], true);
        if (!is_array($payload)) {
            return '';
        }

        $byChunkNo = [];
        foreach ((array) ($payload['chunks'] ?? []) as $chunk) {
            if (is_array($chunk)) {
                $byChunkNo[(int) ($chunk['chunk_no'] ?? 0)] = trim((string) ($chunk['text'] ?? ''));
            }
        }
        $ordered = [];
        foreach ($chunks as $chunk) {
            $text = $byChunkNo[(int) $chunk['chunk_no']] ?? '';
            if ($text !== '') {
                $ordered[] = $text;
            }
        }
        return implode("\n", $ordered);
    }

    private function missingChunkNumbers(array $chunks, ?int $expectedChunks): array
    {
        $received = [];
        $max = 0;
        foreach ($chunks as $chunk) {
            $chunkNo = (int) $chunk['chunk_no'];
            $received[$chunkNo] = true;
            $max = max($max, $chunkNo);
        }
        $expected = $expectedChunks ?? $max;
        $missing = [];
        for ($chunkNo = 1; $chunkNo <= $expected; $chunkNo++) {
            if (empty($received[$chunkNo])) {
                $missing[] = $chunkNo;
            }
        }
        return $missing;
    }

    private function mergeTranscriptChunks(array $chunks): string
    {
        $texts = [];
        foreach ($chunks as $chunk) {
            $text = trim((string) ($chunk['text'] ?? ''));
            if ($text !== '') {
                $texts[] = $text;
            }
        }
        return implode("\n", $texts);
    }

    private function normalizeAudioAsset(array $asset, bool $replay): array
    {
        return [
            'audio_asset_id' => (int) $asset['id'],
            'attempt_id' => (int) $asset['attempt_id'],
            'status' => (string) $asset['status'],
            'mime_type' => (string) $asset['mime_type'],
            'byte_size' => (int) $asset['byte_size'],
            'checksum' => (string) $asset['checksum'],
            'retention_until' => (string) $asset['retention_until'],
            'idempotent_replay' => $replay,
        ];
    }

    private function normalizeChunk(array $chunk, bool $replay): array
    {
        return [
            'chunk_id' => (int) ($chunk['id'] ?? 0),
            'audio_asset_id' => (int) ($chunk['audio_asset_id'] ?? 0),
            'chunk_no' => (int) ($chunk['chunk_no'] ?? 0),
            'status' => (string) ($chunk['status'] ?? 'received'),
            'byte_size' => (int) ($chunk['byte_size'] ?? 0),
            'checksum' => (string) ($chunk['checksum'] ?? ''),
            'idempotent_replay' => $replay,
        ];
    }

    private function assertRealCallReviewConsentInput(array $attempt, string $consentStatus, string $consentBasis, string $purposeCode, array $accessScope): void
    {
        if (!$this->isRealCallReview($attempt)) {
            return;
        }
        if ($consentStatus !== 'granted') {
            throw new DomainException('真实录音需要先确认告知授权。');
        }
        if ($consentBasis === '') {
            throw new DomainException('真实录音授权依据不能为空。');
        }
        if ($purposeCode === '') {
            throw new DomainException('真实录音用途不能为空。');
        }
        if ($accessScope === []) {
            throw new DomainException('真实录音访问范围不能为空。');
        }
    }

    private function assertTranscriptionReady(array $asset, DateTimeImmutable $now): void
    {
        if ((string) ($asset['status'] ?? '') === 'expired') {
            throw new DomainException('音频已到期，不能继续转写。');
        }
        if ($this->isExpired($asset, $now)) {
            throw new DomainException('音频已到期，不能继续转写。');
        }
        if ($this->isRealCallReview($asset)) {
            $this->assertRealCallReviewConsentReady($asset, $now);
        }
    }

    private function assertAudioAccessAllowed(array $asset, int $actorStaffId, array $actorContext, DateTimeImmutable $now): void
    {
        if ((string) ($asset['status'] ?? '') === 'expired' || $this->isExpired($asset, $now)) {
            throw new DomainException('音频已到期，不能播放或人工查看。');
        }
        if ($this->isRealCallReview($asset)) {
            $this->assertRealCallReviewConsentReady($asset, $now);
        }

        $scope = $this->decodeAccessScope((string) ($asset['access_scope_json'] ?? ''));
        if ($this->scopeAllows($scope, 'owner') && $actorStaffId > 0 && $actorStaffId === (int) $asset['staff_id']) {
            return;
        }
        if ($this->scopeAllows($scope, 'admin') && $this->actorHasAnyRole($actorContext, ['admin', 'ceo', 'operation'])) {
            return;
        }
        if ($this->scopeAllows($scope, 'coach') && $this->actorHasAnyRole($actorContext, ['coach'])) {
            return;
        }
        if ($this->scopeAllows($scope, 'reviewer') && $this->isAssignedReviewer((int) $asset['attempt_id'], $actorStaffId)) {
            return;
        }

        throw new DomainException('当前账号没有该音频的访问权限。');
    }

    private function assertRealCallReviewConsentReady(array $asset, DateTimeImmutable $now): void
    {
        if ((string) ($asset['consent_status'] ?? '') !== 'granted') {
            throw new DomainException('真实录音授权待补，请先补充告知授权后再继续。');
        }
        if (trim((string) ($asset['consent_basis'] ?? '')) === '') {
            throw new DomainException('真实录音授权依据待补，请先补充后再继续。');
        }
        if (trim((string) ($asset['purpose_code'] ?? '')) === '') {
            throw new DomainException('真实录音用途待补，请先补充后再继续。');
        }
        if ($this->decodeAccessScope((string) ($asset['access_scope_json'] ?? '')) === []) {
            throw new DomainException('真实录音访问范围待补，请先补充后再继续。');
        }
        $validUntil = trim((string) ($asset['consent_valid_until'] ?? ''));
        if ($validUntil === '' || new DateTimeImmutable($validUntil) <= $now) {
            throw new DomainException('真实录音授权已失效，请重新确认授权后再继续。');
        }
    }

    private function isRealCallReview(array $row): bool
    {
        return (string) ($row['evaluation_context'] ?? '') === 'real_call_review';
    }

    private function isExpired(array $asset, DateTimeImmutable $now): bool
    {
        $retentionUntil = trim((string) ($asset['retention_until'] ?? ''));
        return $retentionUntil !== '' && new DateTimeImmutable($retentionUntil) <= $now;
    }

    private function decodeAccessScope(string $scopeJson): array
    {
        $scope = json_decode($scopeJson, true);
        if (!is_array($scope)) {
            return [];
        }
        return $this->accessScope($scope);
    }

    private function scopeAllows(array $scope, string $key): bool
    {
        return !empty($scope[$key]);
    }

    private function actorHasAnyRole(array $actorContext, array $roles): bool
    {
        $tokens = array_filter(array_map('strtolower', [
            (string) ($actorContext['role'] ?? ''),
            (string) ($actorContext['raw_role'] ?? ''),
            !empty($actorContext['is_admin']) ? 'admin' : '',
            !empty($actorContext['is_hq']) ? 'operation' : '',
        ]));
        return count(array_intersect($tokens, $roles)) > 0;
    }

    private function isAssignedReviewer(int $attemptId, int $actorStaffId): bool
    {
        if ($actorStaffId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM drill_review_tasks WHERE attempt_id = ? AND reviewer_staff_id = ? LIMIT 1');
        $stmt->execute([$attemptId, $actorStaffId]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function auditAudioAccess(
        array $asset,
        int $actorStaffId,
        array $actorContext,
        string $decision,
        ?string $denialReason
    ): void
    {
        $requestId = trim((string) ($actorContext['request_id'] ?? ''));
        $accessReason = trim((string) ($actorContext['access_reason'] ?? ''));
        $snapshot = [
            'decision' => $decision,
            'attempt_id' => (int) $asset['attempt_id'],
            'purpose_code' => (string) $asset['purpose_code'],
            'scope' => $this->decodeAccessScope((string) $asset['access_scope_json']),
            'denial_reason' => $denialReason,
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO drill_audit_logs (request_id, actor_staff_id, action, object_type, object_id, after_snapshot_json, reason, context_json) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $requestId === '' ? null : substr($requestId, 0, 64),
            $actorStaffId > 0 ? $actorStaffId : null,
            'audio.file.access',
            'drill_audio_asset',
            (int) $asset['id'],
            $this->json($snapshot),
            $accessReason === '' ? null : substr($accessReason, 0, 1000),
            $this->json(['surface' => 'drill_v2', 'result' => $decision]),
        ]);
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new DomainException('音频格式不支持。');
        }
        return $mimeType;
    }

    private function checksum(string $checksum, string $message): string
    {
        $checksum = strtolower(trim($checksum));
        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            throw new DomainException($message);
        }
        return $checksum;
    }

    private function positiveInt(mixed $value, string $message): int
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new DomainException($message);
        }
        return (int) $value;
    }

    private function nullablePositiveInt(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveInt($value, $message);
    }

    private function limitedCode(string $value, string $message, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/^[a-z0-9_:-]+$/i', $value) !== 1) {
            throw new DomainException($message);
        }
        return $value;
    }

    private function nullableLimitedText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $maxLength) {
            throw new DomainException('文本长度超出限制。');
        }
        return $text;
    }

    private function nullableConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new DomainException('转写置信度无效。');
        }
        $confidence = (float) $value;
        if ($confidence < 0 || $confidence > 1) {
            throw new DomainException('转写置信度必须在 0 到 1 之间。');
        }
        return $confidence;
    }

    private function accessScope(array $scope): array
    {
        $allowed = ['owner', 'reviewer', 'coach', 'admin'];
        $normalized = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $scope)) {
                $normalized[$key] = (bool) $scope[$key];
            }
        }
        if ($normalized === []) {
            throw new DomainException('音频访问范围不能为空。');
        }
        return $normalized;
    }

    private function transaction(callable $operation): array
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
