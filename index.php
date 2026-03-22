<?php
$url = isset($_GET['url']) ? $_GET['url'] : '';
if (!$url) die("Missing URL parameter");

// 从环境变量读取配置，如果没有则使用默认值。方便在 Zeabur 面板动态修改！
$referer = getenv('TARGET_REFERER') ?: "http://auth-domain.com/";
$user_agent = getenv('TARGET_UA') ?: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

curl_setopt($ch, CURLOPT_REFERER, $referer);
curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

$response = curl_exec($ch);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// 获取基础路径
$base_url = substr($url, 0, strrpos($url, '/') + 1);

// 适配 Zeabur 的 HTTPS 环境
$protocol = (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https://' : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://');
$self_url = $protocol . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'])[0] . "?url=";

if (strpos($url, '.m3u8') !== false) {
    header("Content-Type: application/vnd.apple.mpegurl");
    $lines = explode("\n", $response);
    $new_response = "";
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        if (strpos($line, '#') === 0) {
            $new_response .= $line . "\n";
        } else {
            if (strpos($line, 'http') === 0) {
                $ts_url = $line;
            } else {
                if (strpos($line, '/') === 0) {
                    $parsed = parse_url($url);
                    $ts_url = $parsed['scheme'] . "://" . $parsed['host'] . $line;
                } else {
                    $ts_url = $base_url . $line;
                }
            }
            $new_response .= $self_url . urlencode($ts_url) . "\n";
        }
    }
    echo $new_response;
} else {
    header("Content-Type: " . ($content_type ? $content_type : "video/MP2T"));
    echo $response;
}
?>
