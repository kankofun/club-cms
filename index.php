<?php
// public_html/index.php

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('BASE_DIR', __DIR__); 
define('DATA_DIR', BASE_DIR . '/data');
define('SRC_DIR', BASE_DIR . '/src');
define('VIEWS_DIR', BASE_DIR . '/views');

require_once SRC_DIR . '/FileDB.php';
require_once SRC_DIR . '/User.php';
require_once SRC_DIR . '/Auth.php';
require_once SRC_DIR . '/Content.php';
require_once SRC_DIR . '/Template.php'; // ★追加
require_once SRC_DIR . '/Router.php';

$db = new FileDB();
$userModel = new User($db);
$auth = new Auth($userModel);
$contentModel = new Content($db);
$templateModel = new Template(); // ★追加

$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
if ($script_dir !== '/' && $script_dir !== '\\') {
    $request_uri = str_replace($script_dir, '', $request_uri);
}
$path = trim($request_uri, '/');

// ★ $templateModel を追加
$router = new Router($auth, $userModel, $contentModel, $templateModel);
$router->dispatch($path);