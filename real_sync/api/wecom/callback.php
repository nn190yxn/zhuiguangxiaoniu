<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function wecomCallbackFail(int $statusCode, string $message): void {
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    error_log('[wecom.callback] ' . $message);
    echo 'fail';
    exit;
}

function wecomCallbackVerifySignature(string $token, string $signature, string $timestamp, string $nonce, string $encrypt): bool {
    $items = [$token, $timestamp, $nonce, $encrypt];
    sort($items, SORT_STRING);
    return hash_equals(sha1(implode('', $items)), $signature);
}

function wecomCallbackDecrypt(string $aesKey, string $cipherText, string $corpId): string {
    $key = base64_decode($aesKey . '=', true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('invalid aes key');
    }

    $iv = substr($key, 0, 16);
    $plainText = openssl_decrypt(base64_decode($cipherText, true) ?: '', 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($plainText === false || strlen($plainText) < 20) {
        throw new RuntimeException('decrypt failed');
    }

    $pad = ord(substr($plainText, -1));
    if ($pad < 1 || $pad > 32) {
        throw new RuntimeException('invalid padding');
    }
    $plainText = substr($plainText, 0, -$pad);

    $messageLength = unpack('N', substr($plainText, 16, 4))[1] ?? 0;
    $message = substr($plainText, 20, $messageLength);
    $fromCorpId = substr($plainText, 20 + $messageLength);
    if ($fromCorpId !== $corpId) {
        throw new RuntimeException('corp id mismatch');
    }

    return $message;
}

if (WECOM_CALLBACK_TOKEN === '' || WECOM_CALLBACK_AES_KEY === '') {
    wecomCallbackFail(503, 'callback config missing');
}

$signature = (string)($_GET['msg_signature'] ?? '');
$timestamp = (string)($_GET['timestamp'] ?? '');
$nonce = (string)($_GET['nonce'] ?? '');
$echostr = (string)($_GET['echostr'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($signature === '' || $timestamp === '' || $nonce === '' || $echostr === '') {
        wecomCallbackFail(400, 'verify params missing');
    }
    if (!wecomCallbackVerifySignature(WECOM_CALLBACK_TOKEN, $signature, $timestamp, $nonce, $echostr)) {
        wecomCallbackFail(403, 'invalid signature');
    }

    try {
        $reply = wecomCallbackDecrypt(WECOM_CALLBACK_AES_KEY, $echostr, WECOM_CORP_ID);
    } catch (Throwable $e) {
        wecomCallbackFail(400, $e->getMessage());
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $reply;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'success';
    exit;
}

wecomCallbackFail(405, 'method not allowed');
