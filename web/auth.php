<?php
// auth.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/common.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $userConfig = findUserConfigByUsername($username);
    if ($userConfig && isset($userConfig['password']) && $userConfig['password'] === $password) {
        $_SESSION['user'] = [
            'username' => $userConfig['username'],
            'log_csv'  => __DIR__ . '/' . $userConfig['log_csv'],
            'workout_routine_csv' => __DIR__ . '/' . $userConfig['workout_routine_csv']
        ];
        echo json_encode(['success' => true, 'username' => $userConfig['username']]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Credenziali non valide']);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'me') {
    $user = getCurrentUser();
    if ($user) {
        echo json_encode([
            'logged'   => true,
            'username' => $user['username']
        ]);
    } else {
        echo json_encode(['logged' => false]);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action']);
