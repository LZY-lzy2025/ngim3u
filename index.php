<?php
// 获取需要代理的 URL
$url = isset($_GET['url']) ? $_GET['url'] : '';
if (!$url) die("Missing URL parameter");

// 从环境变量读取配置 (在 Zeabur 面板设置)
$referer_env = getenv('TARGET_REFERER');
$user_agent = getenv('TARGET_UA') ?: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

// 初始化 cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

// --- 处理 Referer ---
if ($referer_env === 'none' || $referer_env === false || $referer_env === '') {
    // 如果环境变量填了 'none' 或者完全没填，强制清空 Referer 请求头，实现 no-Referer
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Referer:'));
} else {
    // 如果填了具体的网址，就伪装成该网址
    curl_setopt($ch, CURLOPT_REFERER, $referer_env);
}

// 执行请求
$response = curl_exec($ch);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// 获取基础路径，用于补全 ts 文件的相对路径
$base_url = substr($url, 0, strrpos($url, '/') + 1);

// 自动识别当前的访问协议 (适配 Zeabur 的 HTTPS)
$protocol = (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https://' : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://');
$self_url = $protocol . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'])[0] . "?url=";

// 判断如果是 M3U8 列表，则需要重写里面的链接
if (strpos($url, '.m3u8') !== false || strpos($content_type, 'mpegurl') !== false) {
    header("Content-Type: application/vnd.apple.mpegurl");
    
    $lines = explode("\n", $response);
    $new_response = "";
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // 保留 M3U8 的标签和注释
        if (strpos($line, '#') === 0) {
            $new_response .= $line . "\n";
        } else {
            // 处理视频流链接
            if (strpos($line, 'http') === 0) {
                // 已经是绝对路径
                $ts_url = $line;
            } else {
                // 如果是相对路径，拼接成绝对路径
                if (strpos($line, '/') === 0) {
                    $parsed = parse_url($url);
                    $ts_url = $parsed['scheme'] . "://" . $parsed['host'] . $line;
                } else {
                    $ts_url = $base_url . $line;
                }
            }
            // 让所有的 ts 切片文件也通过代理脚本去请求
            $new_response .= $self_url . urlencode($ts_url) . "\n";
        }
    }
    echo $new_response;
    
} else {
    // 如果是 .ts 视频切片或其他文件，直接输出原始的二进制流
    header("Content-Type: " . ($content_type ? $content_type : "video/MP2T"));
    echo $response;
}
?>
