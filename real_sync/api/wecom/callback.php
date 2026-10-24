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
    $rawBody = (string)file_get_contents('php://input');
    if ($signature === '' || $timestamp === '' || $nonce === '' || $rawBody === '') {
        wecomCallbackFail(400, 'callback params missing');
    }

    // POST 密文在 body 的 XML <Encrypt> 字段中；先整包验签，失败拒绝
    $encrypt = '';
    if (preg_match('#<Encrypt><!\[CDATA\[(.*?)\]\]></Encrypt>#s', $rawBody, $m)) {
        $encrypt = $m[1];
    } elseif (preg_match('#<Encrypt>(.*?)</Encrypt>#s', $rawBody, $m)) {
        $encrypt = $m[1];
    }
    if (!wecomCallbackVerifySignature(WECOM_CALLBACK_TOKEN, $signature, $timestamp, $nonce, $encrypt)) {
        wecomCallbackFail(403, 'invalid signature');
    }

    // 解密事件并落日志；解密失败只记录原始长度，仍回 success 避免企微重试风暴
    try {
        $plain = wecomCallbackDecrypt(WECOM_CALLBACK_AES_KEY, $encrypt, WECOM_CORP_ID);
        error_log('[wecom.callback.event] ' . mb_substr(trim($plain), 0, 2000));
    } catch (Throwable $e) {
        error_log('[wecom.callback.decrypt_failed] len=' . strlen($rawBody) . ' err=' . $e->getMessage());
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'success';
    exit;
}

wecomCallbackFail(405, 'method not allowed');
