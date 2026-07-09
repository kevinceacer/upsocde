<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/**
 * 支付宝当面付公共函数。
 *
 * 本文件只负责：
 * 1. RSA2 请求签名
 * 2. 支付宝异步通知验签
 * 3. OpenAPI 请求
 * 4. 简单诊断日志
 *
 * 配置统一读取现有 settings 表：
 * alipay_app_id / alipay_gateway / alipay_private_key / alipay_public_key
 */

function alipayPemPrivateKey(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    if (str_contains($key, 'BEGIN PRIVATE KEY')) {
        return $key;
    }

    $body = preg_replace('/\s+/', '', $key) ?? '';

    return "-----BEGIN PRIVATE KEY-----\n"
        . chunk_split($body, 64, "\n")
        . "-----END PRIVATE KEY-----";
}

function alipayPemRsaPrivateKey(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    if (str_contains($key, 'BEGIN RSA PRIVATE KEY')) {
        return $key;
    }

    $body = preg_replace('/\s+/', '', $key) ?? '';

    return "-----BEGIN RSA PRIVATE KEY-----\n"
        . chunk_split($body, 64, "\n")
        . "-----END RSA PRIVATE KEY-----";
}

function alipayPemPublicKey(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    if (str_contains($key, 'BEGIN PUBLIC KEY')) {
        return $key;
    }

    $body = preg_replace('/\s+/', '', $key) ?? '';

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split($body, 64, "\n")
        . "-----END PUBLIC KEY-----";
}

function alipayLoadPrivateKey(string $key)
{
    $candidates = [
        trim($key),
        alipayPemPrivateKey($key),
        alipayPemRsaPrivateKey($key),
    ];

    foreach (array_unique($candidates) as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $resource = openssl_pkey_get_private($candidate);
        if ($resource !== false) {
            return $resource;
        }
    }

    return false;
}

function alipayLoadPublicKey(string $key)
{
    $candidates = [
        trim($key),
        alipayPemPublicKey($key),
    ];

    foreach (array_unique($candidates) as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $resource = openssl_pkey_get_public($candidate);
        if ($resource !== false) {
            return $resource;
        }
    }

    return false;
}

function alipayBuildSignContent(array $params): string
{
    unset($params['sign']);
    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $pairs[] = $key . '=' . (string)$value;
    }

    return implode('&', $pairs);
}

function alipaySign(array $params, string $privateKey): string
{
    $resource = alipayLoadPrivateKey($privateKey);
    if ($resource === false) {
        throw new RuntimeException('支付宝应用私钥格式错误');
    }

    $signature = '';
    $ok = openssl_sign(
        alipayBuildSignContent($params),
        $signature,
        $resource,
        OPENSSL_ALGO_SHA256
    );

    if (!$ok) {
        throw new RuntimeException('支付宝请求签名失败');
    }

    return base64_encode($signature);
}

function alipayVerify(array $params): bool
{
    $sign = (string)($params['sign'] ?? '');
    if ($sign === '') {
        return false;
    }

    unset($params['sign'], $params['sign_type']);

    $publicKey = secretSetting('alipay_public_key');
    $resource = alipayLoadPublicKey($publicKey);
    if ($resource === false) {
        alipayLog('notify_error.log', '支付宝公钥加载失败');
        return false;
    }

    $decoded = base64_decode($sign, true);
    if ($decoded === false) {
        return false;
    }

    return openssl_verify(
        alipayBuildSignContent($params),
        $decoded,
        $resource,
        OPENSSL_ALGO_SHA256
    ) === 1;
}

function alipayLogDir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/ups-ceacer-alipay';

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

function alipayLog(string $file, string $message): void
{
    $file = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file) ?: 'alipay.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    @file_put_contents(
        alipayLogDir() . '/' . $file,
        $line,
        FILE_APPEND | LOCK_EX
    );
}

function alipayRequest(
    string $method,
    array $bizContent,
    string $notifyUrl = ''
): array {
    $appId = trim(setting('alipay_app_id'));
    $privateKey = trim(secretSetting('alipay_private_key'));
    $gateway = trim(setting(
        'alipay_gateway',
        'https://openapi.alipay.com/gateway.do'
    ));

    if ($appId === '') {
        throw new RuntimeException('支付宝 App ID 未配置');
    }

    if ($privateKey === '') {
        throw new RuntimeException('支付宝应用私钥未配置');
    }

    if (!filter_var($gateway, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('支付宝网关地址格式错误：' . $gateway);
    }

    $bizJson = json_encode(
        $bizContent,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($bizJson === false) {
        throw new RuntimeException(
            '支付宝业务参数 JSON 编码失败：' . json_last_error_msg()
        );
    }

    $params = [
        'app_id' => $appId,
        'method' => $method,
        'format' => 'JSON',
        'charset' => 'utf-8',
        'sign_type' => 'RSA2',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'biz_content' => $bizJson,
    ];

    if ($notifyUrl !== '') {
        if (!filter_var($notifyUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('支付宝异步通知地址格式错误：' . $notifyUrl);
        }
        $params['notify_url'] = $notifyUrl;
    }

    $params['sign'] = alipaySign($params, $privateKey);

    $ch = curl_init($gateway);
    if ($ch === false) {
        throw new RuntimeException('无法初始化 cURL');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
            'Accept: application/json',
            'User-Agent: UPS-Alipay-F2F/3.0',
        ],
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException(
            "支付宝网络请求失败，cURL {$curlErrno}：{$curlError}"
        );
    }

    $raw = trim((string)$raw);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    alipayLog(
        'alipay_response.log',
        'HTTP=' . $httpCode
        . '; Content-Type=' . $contentType
        . '; Method=' . $method
        . '; Response=' . mb_substr($raw, 0, 5000)
    );

    if ($raw === '') {
        throw new RuntimeException('支付宝返回空内容，HTTP 状态码：' . $httpCode);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            '支付宝接口 HTTP 错误 ' . $httpCode . '：'
            . mb_substr(trim(strip_tags($raw)), 0, 500)
        );
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException(
            '支付宝返回内容不是有效 JSON：'
            . json_last_error_msg()
            . '；返回：'
            . mb_substr(trim(strip_tags($raw)), 0, 500)
        );
    }

    $responseKey = str_replace('.', '_', $method) . '_response';
    $response = $json[$responseKey] ?? null;

    if (!is_array($response)) {
        throw new RuntimeException(
            '支付宝返回结构异常，未找到 ' . $responseKey
        );
    }

    $code = (string)($response['code'] ?? '');
    if ($code !== '10000') {
        $message = (string)(
            $response['sub_msg']
            ?? $response['msg']
            ?? '未知错误'
        );
        $subCode = (string)($response['sub_code'] ?? '');

        throw new RuntimeException(
            '支付宝错误：' . $message
            . ($subCode !== '' ? '（' . $subCode . '）' : '')
            . ($code !== '' ? '，code=' . $code : '')
        );
    }

    return $response;
}
