<?php
/**
 * Novera Admin Panel - dosya okuma/yazma API'si.
 *
 * YÜKLEMEDEN ÖNCE: ADMIN_TOKEN'ı uzun, rastgele bir değerle değiştirin ve
 * bu değeri sadece admin panelinde girdiğiniz parola olarak kullanın.
 */

define('ADMIN_TOKEN', 'BURAYA-UZUN-RASTGELE-BIR-ANAHTAR-YAZIN');

define('ALLOWED_FILES', array(
  'index.html',
  'novera_esg.html',
  'novera_karbon.html',
  'novera_enerji.html',
  'novera_finans.html',
  'novera_marka.html',
  'novera_strateji.html',
  'novera_logo.png',
  'novera_hero_main.jpg',
  'novera_carbon.jpg',
  'novera_energy.jpg',
  'novera_strategy.jpg',
  'novera_esg_bg.jpg',
  'novera_finance_bg.jpg',
  'novera_marka_bg.jpg',
));

function fail($code, $msg) {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  echo $msg;
  exit;
}

function getAuthHeader() {
  // Bazı paylaşımlı hosting kurulumları (suPHP/FastCGI) Authorization header'ını
  // $_SERVER['HTTP_AUTHORIZATION'] yerine REDIRECT_HTTP_AUTHORIZATION'a taşır.
  if (isset($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
  if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  if (function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
      if (strcasecmp($k, 'Authorization') === 0) return $v;
    }
  }
  return '';
}

function checkAuth() {
  $hdr = getAuthHeader();
  if (!preg_match('/^Bearer\s+(.+)$/', $hdr, $m) || !hash_equals(ADMIN_TOKEN, $m[1])) {
    fail(401, 'Yetkisiz');
  }
}

function safeFile($name) {
  $base = basename((string)$name);
  if ($base === '' || !in_array($base, ALLOWED_FILES, true)) {
    fail(403, 'Dosya izin listesinde değil: ' . $base);
  }
  return $base;
}

checkAuth();

$dir    = __DIR__;
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($method === 'GET' && $action === 'read') {
  $file = safeFile(isset($_GET['file']) ? $_GET['file'] : '');
  $path = $dir . '/' . $file;
  if (!is_file($path)) fail(404, 'Dosya bulunamadı');
  header('Content-Type: application/octet-stream');
  readfile($path);
  exit;
}

if ($method === 'POST' && $action === 'write') {
  $file = safeFile(isset($_GET['file']) ? $_GET['file'] : '');
  $path = $dir . '/' . $file;
  $newContent = file_get_contents('php://input');
  if ($newContent === false) fail(400, 'İstek gövdesi okunamadı');

  if (is_file($path)) {
    $origBytes = file_get_contents($path);
    @copy($path, $path . '.bak');
    $hasBom = substr($origBytes, 0, 3) === "\xEF\xBB\xBF";
    if ($hasBom && substr($newContent, 0, 3) !== "\xEF\xBB\xBF") {
      $newContent = "\xEF\xBB\xBF" . $newContent;
    }
  }
  if (file_put_contents($path, $newContent) === false) fail(500, 'Yazma başarısız');
  echo 'OK';
  exit;
}

if ($method === 'POST' && $action === 'upload-image') {
  $file  = safeFile(isset($_GET['file']) ? $_GET['file'] : '');
  $path  = $dir . '/' . $file;
  $bytes = file_get_contents('php://input');
  if ($bytes === false || $bytes === '') fail(400, 'İstek gövdesi okunamadı');
  if (is_file($path)) @copy($path, $path . '.bak');
  if (file_put_contents($path, $bytes) === false) fail(500, 'Yazma başarısız');
  echo 'OK';
  exit;
}

fail(400, 'Geçersiz istek');
