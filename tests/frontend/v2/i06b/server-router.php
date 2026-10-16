<?php
// I06B 浏览器验收专用 php -S 路由脚本：静态文件直出（含 /build 资源），其余转发 Laravel。
// 本文件位于 <root>/tests/frontend/v2/i06b/，项目根目录为向上 4 级。
$root = dirname(__DIR__, 4);
$publicDir = $root . '/public';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && file_exists($publicDir . $uri) && is_file($publicDir . $uri)) {
    return false; // 交给 php -S 直接以正确 MIME 返回静态资源
}

require_once $publicDir . '/index.php';
