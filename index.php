<?php
// 简单的 M3U8 代理：支持 Referer/UA 伪装、相对路径重写，并增加了基础安全与稳定性控制。

function env_bool(string $key, bool $default = false): bool {
    $v = getenv($key);
    if ($v === false) {
        return $default;
    }
    $v = strtolower(trim((string)$v));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function bad_request(string $message): void {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function upstream_error(string $message, int $status = 502): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function is_allowed_host(string $host, string $allowlist): bool {
    $allowlist = trim($allowlist);
    if ($allowlist === '') {
        return true;
    }

    $allowed = array_filter(array_map('trim', explode(',', $allowlist)));
    foreach ($allowed as $item) {
        if (strcasecmp($host, $item) === 0) {
            return true;
        }
    }
    return false;
}

function absolute_url(string $base, string $relative): string {
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }

    $parts = parse_url($base);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return $relative;
    }

    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    if (strpos($relative, '//') === 0) {
        return $scheme . ':' . $relative;
    }

    $path = isset($parts['path']) ? $parts['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);

    if (strpos($relative, '/') === 0) {
        $fullPath = $relative;
    } else {
        $fullPath = $dir . $relative;
    }

    // 规范化 ./ 和 ../
    $segments = [];
    foreach (explode('/', $fullPath) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
}

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($url === '') {
    bad_request('Missing URL parameter');
}

$parsedUrl = parse_url($url);
if (!$parsedUrl || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
    bad_request('Invalid URL');
}

$scheme = strtolower((string)$parsedUrl['scheme']);
if (!in_array($scheme, ['http', 'https'], true)) {
    bad_request('Only http/https URLs are supported');
}

$enableHostAllowlist = env_bool('ENABLE_HOST_ALLOWLIST', false);
$allowlist = getenv('TARGET_HOST_ALLOWLIST') ?: '';
if ($enableHostAllowlist) {
    if (trim($allowlist) === '') {
        bad_request('ENABLE_HOST_ALLOWLIST is on, but TARGET_HOST_ALLOWLIST is empty');
    }

    if (!is_allowed_host((string)$parsedUrl['host'], $allowlist)) {
        bad_request('Target host is not in allowlist');
    }
}

$refererEnv = getenv('TARGET_REFERER');
$userAgent = getenv('TARGET_UA') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$insecureSsl = env_bool('INSECURE_SSL', false);
$connectTimeout = (int)(getenv('CONNECT_TIMEOUT') ?: 5);
$requestTimeout = (int)(getenv('REQUEST_TIMEOUT') ?: 20);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, $connectTimeout));
curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $requestTimeout));
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

if ($insecureSsl) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}

if ($refererEnv === 'none' || $refererEnv === false || $refererEnv === '') {
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Referer:']);
} else {
    curl_setopt($ch, CURLOPT_REFERER, $refererEnv);
}

$response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    upstream_error('Upstream request failed: ' . $err);
}

$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($httpCode >= 400) {
    upstream_error('Upstream responded with HTTP ' . $httpCode, 502);
}

$protocol = (
    isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
) ? 'https://' : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://');

$selfUrl = $protocol . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'])[0] . '?url=';

$isM3u8 = (stripos($url, '.m3u8') !== false) || (stripos($contentType, 'mpegurl') !== false);
if ($isM3u8) {
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $lines = preg_split('/\r\n|\r|\n/', $response);
    $newResponse = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            $newResponse .= "\n";
            continue;
        }

        if (strpos($line, '#') === 0) {
            $newResponse .= $line . "\n";
            continue;
        }

        $segmentUrl = absolute_url($url, $line);
        $newResponse .= $selfUrl . rawurlencode($segmentUrl) . "\n";
    }

    echo $newResponse;
    exit;
}

header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/octet-stream'));
header('Cache-Control: public, max-age=30');
echo $response;
