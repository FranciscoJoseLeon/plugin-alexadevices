<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/*
 * Pont HTTP entre le navigateur et le démon Python pour afficher la page
 * officielle de connexion Amazon (toutes les méthodes de vérification proposées
 * par Amazon : SMS, WhatsApp, notification dans l'application, TOTP, captcha...).
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

$cookie_name = 'alexadevices_proxy';
$cookie_path = '/plugins/alexadevices/core/php/';

$auth_token = init('auth_token', '');
if (empty($auth_token) && isset($_COOKIE[$cookie_name])) {
    $auth_token = $_COOKIE[$cookie_name];
}
$cached_token = cache::byKey('alexadevices_auth_token')->getValue('');
$is_token_valid = (!empty($auth_token) && !empty($cached_token) && hash_equals($cached_token, $auth_token));

if (!isConnect('admin') && !$is_token_valid) {
    http_response_code(401);
    die(__('401 - Unauthorized access', __FILE__));
}

if ($is_token_valid && (!isset($_COOKIE[$cookie_name]) || $_COOKIE[$cookie_name] !== $auth_token)) {
    // Le jeton est conservé dans un cookie : les requêtes AJAX de la page Amazon n'ont pas besoin de le transporter.
    setcookie($cookie_name, $auth_token, array(
        'expires' => time() + 1800,
        'path' => $cookie_path,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
}

$daemon_port = config::byKey('daemon_port', 'alexadevices', 55065);
$daemon_host = '127.0.0.1';

$action = init('action', '');
$target_url = '';
// L'URL cible est toujours le dernier paramètre : on la lit brute pour conserver ses propres « & ».
$raw_query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
if (preg_match('/(?:^|&)url=(.*)$/s', $raw_query, $m)) {
    $target_url = $m[1];
    if (strpos($target_url, '&') === false && (preg_match('/^https?%3A/i', $target_url) || strpos($target_url, '%3D') !== false || strpos($target_url, '%26') !== false)) {
        $target_url = rawurldecode($target_url);
    }
}
$domain = preg_replace('/[^a-z]/', '', strtolower(init('domain', config::byKey('amazon_domain', 'alexadevices', 'es'))));

if (empty($target_url) && $action != 'start') {
    $domain_suffix = ($domain == 'us') ? 'com' : (($domain == 'gb') ? 'co.uk' : $domain);
    $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    parse_str($query, $parsed);
    unset($parsed['auth_token'], $parsed['action'], $parsed['domain'], $parsed['url']);
    if (!empty($parsed)) {
        $target_url = "https://www.amazon.{$domain_suffix}/errors/validateCaptcha?" . http_build_query($parsed);
    }
}

$proxy_base = '/plugins/alexadevices/core/php/proxy.php';

if ($action == 'start') {
    config::save('amazon_domain', $domain, 'alexadevices');
    $daemon_url = "http://{$daemon_host}:{$daemon_port}/proxy/start?domain=" . urlencode($domain) . "&proxy_base=" . urlencode($proxy_base);
} else {
    $daemon_url = "http://{$daemon_host}:{$daemon_port}/proxy/relay?url=" . urlencode($target_url);
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $daemon_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$method = $_SERVER['REQUEST_METHOD'];
if ($method != 'GET') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $post_body = file_get_contents('php://input');
    if (empty($post_body) && !empty($_POST)) {
        $post_body = http_build_query($_POST);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
}

// Transmettre les en-têtes utiles du navigateur (sauf ceux propres à Jeedom).
$skip = array('host', 'cookie', 'content-length', 'accept-encoding', 'connection', 'referer', 'origin', 'authorization');
$headers = array();
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') !== 0) {
        continue;
    }
    $name = str_replace('_', '-', strtolower(substr($key, 5)));
    if (in_array($name, $skip)) {
        continue;
    }
    $headers[] = $name . ': ' . $value;
}
$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '');
if (!empty($content_type)) {
    $headers[] = 'Content-Type: ' . $content_type;
} else if ($method != 'GET') {
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    $err = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . __('Alexa daemon not running', __FILE__) . '</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f6f9;color:#333;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box}';
    echo '.card{background:#fff;border-radius:12px;padding:30px;max-width:520px;box-shadow:0 6px 20px rgba(0,0,0,.12);text-align:center}';
    echo 'h2{color:#d9534f;margin-top:0}p{line-height:1.6;color:#555}ol{text-align:left;line-height:1.9;background:#fdf2f2;border-left:4px solid #d9534f;padding:15px 15px 15px 35px;border-radius:4px}';
    echo 'button{margin-top:10px;padding:10px 22px;background:#337ab7;color:#fff;border:0;border-radius:6px;font-weight:bold;cursor:pointer}small{color:#999}</style></head><body><div class="card">';
    echo '<h2>' . __('Alexa daemon not running', __FILE__) . '</h2>';
    echo '<p>' . __('The plugin local service must be running to sign in with Amazon.', __FILE__) . '</p>';
    echo '<ol><li>' . __('Close this window.', __FILE__) . '</li>';
    echo '<li>' . __('In the plugin page, check that Dependencies are OK.', __FILE__) . '</li>';
    echo '<li>' . __('Click (Re)Start in the Daemon section and wait until it is OK.', __FILE__) . '</li>';
    echo '<li>' . __('Click Connect with Amazon again.', __FILE__) . '</li></ol>';
    echo '<button onclick="window.location.reload()">' . __('Retry', __FILE__) . '</button>';
    echo '<p><small>' . htmlspecialchars($err) . '</small></p></div></body></html>';
    exit;
}

$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$header_text = substr($response, 0, $header_size);
$body = substr($response, $header_size);

http_response_code($http_code);

foreach (explode("\r\n", $header_text) as $i => $line) {
    if ($i === 0 || empty($line) || strpos($line, ':') === false) {
        continue;
    }
    $lower = strtolower($line);
    if (strpos($lower, 'transfer-encoding:') === 0 || strpos($lower, 'content-length:') === 0
        || strpos($lower, 'connection:') === 0 || strpos($lower, 'set-cookie:') === 0
        || strpos($lower, 'date:') === 0 || strpos($lower, 'server:') === 0) {
        continue;
    }
    header($line, true);
}

echo $body;
