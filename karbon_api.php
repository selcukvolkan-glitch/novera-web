<?php
/**
 * Karbon Veri Toplama Formu - kayıt/giriş + form okuma/yazma API'si.
 *
 * YÜKLEMEDEN ÖNCE: ADMIN_BOOTSTRAP_PASSWORD'u gerçek admin şifrenizle
 * değiştirin (bu repo herkese açık olabilir, placeholder'ı asla canlıda
 * bırakmayın). İlk istek geldiğinde data/users.json bu şifreyle oluşturulur.
 */

session_start();

define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('ADMIN_USERNAME', 'admin');
define('ADMIN_BOOTSTRAP_PASSWORD', 'DEGISTIR-BU-SIFREYI-1234');

function fail($code, $msg) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('error' => $msg));
  exit;
}

function ensureDataDir() {
  if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
}

function loadUsers() {
  ensureDataDir();
  if (!is_file(USERS_FILE)) {
    $seed = array(
      ADMIN_USERNAME => array(
        'passwordHash' => password_hash(ADMIN_BOOTSTRAP_PASSWORD, PASSWORD_DEFAULT),
        'role' => 'admin',
        'company' => '',
        'createdAt' => date('c'),
      ),
    );
    file_put_contents(USERS_FILE, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $seed;
  }
  $data = json_decode(file_get_contents(USERS_FILE), true);
  return is_array($data) ? $data : array();
}

function saveUsers($users) {
  ensureDataDir();
  file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function findUsername($users, $username) {
  foreach ($users as $existing => $u) {
    if (strcasecmp($existing, $username) === 0) return $existing;
  }
  return null;
}

function submissionPath($username) {
  $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
  return DATA_DIR . '/sub_' . $safe . '.json';
}

function readSubmission($username) {
  $path = submissionPath($username);
  if (!is_file($path)) return null;
  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? $data : null;
}

function jsonInput() {
  $data = json_decode(file_get_contents('php://input'), true);
  return is_array($data) ? $data : array();
}

function currentUser() {
  return isset($_SESSION['username']) ? $_SESSION['username'] : null;
}

function currentRole() {
  return isset($_SESSION['role']) ? $_SESSION['role'] : null;
}

function requireLogin() {
  if (!currentUser()) fail(401, 'Giriş yapılmamış');
}

function requireAdmin() {
  requireLogin();
  if (currentRole() !== 'admin') fail(403, 'Yetkisiz');
}

header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($method === 'POST' && $action === 'register') {
  $in = jsonInput();
  $username = isset($in['username']) ? trim((string)$in['username']) : '';
  $password = isset($in['password']) ? (string)$in['password'] : '';
  $company  = isset($in['company']) ? trim((string)$in['company']) : '';

  if (!preg_match('/^[a-zA-Z0-9_.\-]{3,40}$/', $username)) fail(400, 'Kullanıcı adı 3-40 karakter olmalı, sadece harf/rakam/._- içerebilir');
  if (strlen($password) < 4) fail(400, 'Parola en az 4 karakter olmalı');
  if ($company === '') fail(400, 'Şirket adı gerekli');
  if (strcasecmp($username, ADMIN_USERNAME) === 0) fail(400, 'Bu kullanıcı adı kullanılamaz');

  $users = loadUsers();
  if (findUsername($users, $username) !== null) fail(409, 'Bu kullanıcı adı zaten kayıtlı');

  $users[$username] = array(
    'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
    'role' => 'customer',
    'company' => $company,
    'createdAt' => date('c'),
  );
  saveUsers($users);

  session_regenerate_id(true);
  $_SESSION['username'] = $username;
  $_SESSION['role'] = 'customer';
  echo json_encode(array('ok' => true, 'username' => $username, 'role' => 'customer', 'company' => $company));
  exit;
}

if ($method === 'POST' && $action === 'login') {
  $in = jsonInput();
  $username = isset($in['username']) ? trim((string)$in['username']) : '';
  $password = isset($in['password']) ? (string)$in['password'] : '';

  $users = loadUsers();
  $match = findUsername($users, $username);
  if ($match === null || !password_verify($password, $users[$match]['passwordHash'])) {
    fail(401, 'Kullanıcı adı veya parola hatalı');
  }

  session_regenerate_id(true);
  $_SESSION['username'] = $match;
  $_SESSION['role'] = $users[$match]['role'];
  echo json_encode(array(
    'ok' => true,
    'username' => $match,
    'role' => $users[$match]['role'],
    'company' => isset($users[$match]['company']) ? $users[$match]['company'] : '',
  ));
  exit;
}

if ($action === 'logout') {
  $_SESSION = array();
  session_destroy();
  echo json_encode(array('ok' => true));
  exit;
}

if ($action === 'whoami') {
  $user = currentUser();
  if (!$user) { echo json_encode(array('loggedIn' => false)); exit; }
  $users = loadUsers();
  $company = isset($users[$user]['company']) ? $users[$user]['company'] : '';
  echo json_encode(array('loggedIn' => true, 'username' => $user, 'role' => currentRole(), 'company' => $company));
  exit;
}

if ($method === 'GET' && $action === 'get-form') {
  requireLogin();
  echo json_encode(array('data' => readSubmission(currentUser())));
  exit;
}

if ($method === 'POST' && $action === 'save-form') {
  requireLogin();
  $in = jsonInput();
  $in['lastSaved'] = date('c');
  ensureDataDir();
  file_put_contents(submissionPath(currentUser()), json_encode($in, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
  echo json_encode(array('ok' => true, 'lastSaved' => $in['lastSaved']));
  exit;
}

if ($method === 'GET' && $action === 'admin-list') {
  requireAdmin();
  $users = loadUsers();
  $list = array();
  foreach ($users as $username => $u) {
    if ($u['role'] === 'admin') continue;
    $sub = readSubmission($username);
    $list[] = array(
      'username' => $username,
      'company' => isset($u['company']) ? $u['company'] : '',
      'createdAt' => isset($u['createdAt']) ? $u['createdAt'] : '',
      'lastSaved' => $sub && isset($sub['lastSaved']) ? $sub['lastSaved'] : null,
    );
  }
  echo json_encode(array('customers' => $list));
  exit;
}

if ($method === 'GET' && $action === 'admin-get-form') {
  requireAdmin();
  $target = isset($_GET['user']) ? (string)$_GET['user'] : '';
  $users = loadUsers();
  $match = findUsername($users, $target);
  if ($match === null) fail(404, 'Kullanıcı bulunamadı');
  echo json_encode(array(
    'data' => readSubmission($match),
    'company' => isset($users[$match]['company']) ? $users[$match]['company'] : '',
    'username' => $match,
  ));
  exit;
}

fail(400, 'Geçersiz istek');
