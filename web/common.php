<?php
// common.php
session_start();

define('CONFIG_PATH', __DIR__ . '/config.json');

function loadConfig()
{
    static $config = null;
    if ($config === null) {
        $json = @file_get_contents(CONFIG_PATH);
        if ($json === false) {
            throw new Exception('Impossibile leggere config.json');
        }
        $config = json_decode($json, true);
        if (!is_array($config)) {
            throw new Exception('config.json non valido');
        }
    }
    return $config;
}

function findUserConfigByUsername($username)
{
    $config = loadConfig();
    if (!isset($config['users']) || !is_array($config['users'])) {
        return null;
    }
    foreach ($config['users'] as $user) {
        if (isset($user['username']) && $user['username'] === $username) {
            return $user;
        }
    }
    return null;
}

function getCurrentUser()
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function requireLogin()
{
    $user = getCurrentUser();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    return $user;
}

function ensureLogCsvExists($logCsvPath)
{
    if (!file_exists($logCsvPath)) {
        $dir = dirname($logCsvPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $fh = fopen($logCsvPath, 'w');
        if ($fh) {
            // id;date;activity;pairs
            fputcsv($fh, ['id','date','activity','pairs'], ';');
            fclose($fh);
        }
    }
}

function normalizeDate($s)
{
    $s = trim($s);
    if ($s === '') return $s;
    if (preg_match('/^\d{8}$/', $s)) {
        return substr($s,0,4) . '-' . substr($s,4,2) . '-' . substr($s,6,2);
    }
    return $s;
}
