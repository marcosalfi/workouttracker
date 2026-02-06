<?php
/*
api.php

php 7.x

Compatibilità: se il CSV è nel vecchio formato (senza title), l'API lo “upgrada” in memoria
e, quando riscrive, usa sempre l'header completo.
*/
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/sqlite_helper.php';
$BASE_DIR = __DIR__;


/* ===================== CONFIG ===================== */
$configPath = $BASE_DIR . '/config.json';
if (!file_exists($configPath)) {
    apiLog('config.json not found: ' . $configPath, 'ERROR');
    echo json_encode(['error' => 'Missing config.json']);
    exit;
}

$configRaw = file_get_contents($configPath);
// apiLog('configRaw: ' . $configRaw, 'DEBUG');
$config = json_decode($configRaw, true);
if (!is_array($config)) {
    apiLog('Invalid config.json in : ' . $configPath, 'ERROR');
    echo json_encode(['error' => 'Invalid config.json']);
    exit;
}
// auth (opzionale): se non passi credenziali usa users[0]
$username = $_GET['username'] ?? ($_POST['username'] ?? '');
$password = $_GET['password'] ?? ($_POST['password'] ?? '');

$userCfg = null;
if (isset($config['users']) && is_array($config['users'])) {
    if ($username === '' && $password === '') {
        $userCfg = $config['users'][0] ?? null;
    } else {
        foreach ($config['users'] as $u) {
            if (!is_array($u)) continue;
            if (($u['username'] ?? '') === $username && ($u['password'] ?? '') === $password) {
                $userCfg = $u;
                break;
            }
        }
    }
}

if (!is_array($userCfg)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// database path relativo a BASE_DIR (cartella di api.php)
$dbPath = resolvePath(($userCfg['database'] ?? 'data/workout.db'), $BASE_DIR);

apiLog('BASE_DIR: ' . $BASE_DIR, 'DEBUG');
apiLog('configPath: ' . $configPath, 'DEBUG');
apiLog('dbPath: ' . $dbPath, 'DEBUG');

/* ===================== DB ===================== */
$db = new SqliteDb();
$db->init($dbPath);
ensureSchema($db);

/* ===================== ACTION ===================== */
$action = $_GET['action'] ?? $_POST['action'] ?? null;
if (!$action) {
    echo json_encode(['error' => 'No action']);
    exit;
}

switch ($action) {

    case 'listActivities':
        handleListActivities($db);
        break;

    case 'listWorkoutDates':
        handleListWorkoutDates($db);
        break;
    case 'cloneWorkout':
        handleCloneWorkout($db);
        break;

    case 'getWorkout':
        handleWorkout($db);
        break;

    case 'deleteExercise':
        handleDeleteExercise($db);
        break;

    case 'renameExercise':
        handleRenameExercise($db);
        break;

    case 'getExerciseById':
        handleGetExerciseById($db);
        break;

    case 'addExercise':
        handleAddExercise($db);
        break;
    //case 'getExercise':
    //    handleGetExercise($db);
    //    break;        

    case 'saveExercisePairs':
        handleSaveExercisePairs($db);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}

function normalizeActivityName($s)
{
    $s = str_replace('_', ' ', (string)$s);
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function handleAddExercise(SqliteDb $db)
{
    $date     = normalizeDate($_POST['date'] ?? '');
    $activity = trim($_POST['activity'] ?? '');
    $title    = trim($_POST['title'] ?? '');

    if ($date === '' || $activity === '') jsonError('Missing date/activity');
    if ($title === '') $title = ''; // se vuoi obbligatorio, fai jsonError('Missing title');

    $id = newId();

    try {
        // opzionale: activity_order in coda
        $ordRow  = $db->queryDt(
            "SELECT COALESCE(MAX(activity_order), 0) AS mx
             FROM workout_log
             WHERE wo_date = :d",
            [':d' => $date]
        );
        $nextOrd = (int)($ordRow[0]['mx'] ?? 0) + 1;

        // FIX: origin_date NON può essere NULL
        $db->query("
            INSERT INTO workout_log
            (id, wo_date, origin_date, title, activity, pairs, prev_pairs, activity_order)
            VALUES
            (:id, :d, :o, :t, :a, :pairs, :prev, :ord)
        ", [
            ':id'    => $id,
            ':d'     => $date,
            ':o'     => $date,
            ':t'     => $title,
            ':a'     => $activity,
            ':pairs' => '',
            ':prev'  => '',
            ':ord'   => $nextOrd
        ]);

        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        // log dettagliato server-side
        apiLog('handleAddExercise ERROR: ' . $ex->getMessage(), 'ERROR');
        apiLog('payload date=' . $date . ' title=' . $title . ' activity=' . $activity . ' id=' . $id, 'ERROR');

        // risposta JSON pulita lato client
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'DB error: ' . $ex->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

function handleDeleteExercise(SqliteDb $db)
{
    $id = trim($_POST['id'] ?? ($_GET['id'] ?? ''));
    if ($id === '') jsonError('Missing id');

    try {
        $n = $db->query("DELETE FROM workout_log WHERE id = :id", [':id' => $id]);
        echo json_encode(['success' => true, 'deleted' => (int)$n], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        apiLog('handleDeleteExercise ERROR: ' . $ex->getMessage() . ' id=' . $id, 'ERROR');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}


function handleListActivities($db)
{
    echo json_encode([
        'items' => $db->queryDt(
            "SELECT id, activity, activity_type FROM workout_activies ORDER BY activity"
        )
    ], JSON_UNESCAPED_UNICODE);
}

function handleListWorkoutDates($db)
{
    echo json_encode([
        'items' => $db->queryDt(
            "SELECT wo_date AS date, MIN(title) AS title, COUNT(*) AS count
                FROM workout_log
                GROUP BY wo_date
                ORDER BY wo_date DESC
                LIMIT 20"
        )
    ], JSON_UNESCAPED_UNICODE);
}

function handleWorkout($db)
{
    $date = normalizeDate($_GET['date'] ?? '');
    $rows = $db->queryDt(
        "SELECT id, title, activity, pairs, prev_pairs, origin_date, activity_order
             FROM workout_log
             WHERE wo_date = :d
             ORDER BY activity_order, activity",
        [':d' => $date]
    );

    foreach ($rows as &$r) {
        $r['pairs'] = parsePairs($r['pairs']);
        $r['prev_pairs'] = parsePairs($r['prev_pairs']);
    }

    echo json_encode([
        'date' => $date,
        'title' => $rows[0]['title'] ?? '',
        'items' => $rows
    ], JSON_UNESCAPED_UNICODE);
}

function handleGetExercise(SqliteDb $db)
{
    $date     = $_GET['date'] ?? '';
    $activity = trim($_GET['activity'] ?? '');

    if ($date === '' || $activity === '') {
        jsonError('Missing date/activity');
    }

    $rows = $db->queryDt(
        "SELECT id, pairs, prev_pairs
         FROM workout_log
         WHERE wo_date = :d AND activity = :a
         ORDER BY rowid DESC
         LIMIT 1",
        [':d' => $date, ':a' => $activity]
    );

    $row = (is_array($rows) && count($rows) > 0) ? $rows[0] : null;

    if (!$row) {
        echo json_encode(['id' => null, 'pairs' => [], 'prev_pairs' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'id' => $row['id'],
        'pairs' => parsePairs($row['pairs']),
        'prev_pairs' => parsePairs($row['prev_pairs'])
    ], JSON_UNESCAPED_UNICODE);
}

function handleGetExerciseById(SqliteDb $db)
{
    $id = trim($_GET['id'] ?? '');
    if ($id === '') jsonError('Missing id');

    $rows = $db->queryDt(
        "SELECT id, activity, pairs, prev_pairs
         FROM workout_log
         WHERE id = :id
         LIMIT 1",
        [':id' => $id]
    );

    $row = (is_array($rows) && count($rows) > 0) ? $rows[0] : null;
    if (!$row) {
        echo json_encode(['success' => true, 'id' => null, 'activity' => null, 'pairs' => [], 'prev_pairs' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'success' => true,
        'id' => $row['id'],
        'activity' => $row['activity'],
        'pairs' => parsePairs($row['pairs']),
        'prev_pairs' => parsePairs($row['prev_pairs'])
    ], JSON_UNESCAPED_UNICODE);
}


function handleRenameExercise(SqliteDb $db)
{
    $id      = $_POST['id'] ?? $_GET['id'] ?? null;
    $newName = trim($_POST['new_name'] ?? $_GET['new_name'] ?? '');

    if (!$id || $newName === '') {
        jsonError('Missing id/new_name');
    }

    $n = $db->query(
        "UPDATE workout_log SET activity = :a WHERE id = :id",
        [':a' => $newName, ':id' => $id]
    );

    jsonOk(['updated' => $n]);
}

function handleSaveExercisePairs(SqliteDb $db)
{
    $id    = $_POST['id'] ?? '';
    $pairs = $_POST['pairs'] ?? null;

    if ($id === '' || $pairs === null) jsonError('Missing id/pairs');

    $pairsStr = pairsToString($pairs);

    $n = $db->query(
        "UPDATE workout_log SET pairs = :p WHERE id = :id",
        [':p' => $pairsStr, ':id' => $id]
    );

    echo json_encode(['success' => true, 'updated' => $n], JSON_UNESCAPED_UNICODE);
}


function handleCloneWorkout(SqliteDb $db)
{
    $source = normalizeDate($_POST['source'] ?? '');
    $target = normalizeDate($_POST['target'] ?? '');

    if ($source === '' || $target === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing source/target']);
        return;
    }

    // righe sorgente
    $srcRows = $db->queryDt("
        SELECT title, activity, pairs, prev_pairs, activity_order
        FROM workout_log
        WHERE wo_date = :s
        ORDER BY
          CASE WHEN activity_order IS NULL THEN 1 ELSE 0 END,
          activity_order ASC,
          activity ASC;
    ", [':s' => $source]);

    if (!$srcRows) {
        echo json_encode(['success' => false, 'error' => 'Source not found']);
        return;
    }

    // titolo del workout sorgente (primo non vuoto)
    $sourceTitle = '';
    foreach ($srcRows as $r) {
        if (trim((string)$r['title']) !== '') {
            $sourceTitle = (string)$r['title'];
            break;
        }
    }

    $db->begin();
    try {
        // 1) cancella target (oggi) PRIMA di replicare
        $deleted = $db->query("DELETE FROM workout_log WHERE wo_date = :t;", [':t' => $target]);

        // 2) inserisci replica
        $added = 0;
        foreach ($srcRows as $r) {
            $srcPairs = trim((string)($r['pairs'] ?? ''));
            $srcPrev  = trim((string)($r['prev_pairs'] ?? ''));

            // REGOLA:
            // se pairs è vuoto/null -> mantieni prev_pairs
            // altrimenti -> copia pairs in prev_pairs
            $newPrevPairs = ($srcPairs === '') ? $srcPrev : $srcPairs;

            $db->query("
                INSERT INTO workout_log
                (id, wo_date, origin_date, title, activity, pairs, prev_pairs, activity_order)
                VALUES
                (:id, :d, :o, :t, :a, :p, :pp, :ord);
            ", [
                ':id'  => newId(),
                ':d'   => $target,
                ':o'   => $source,
                ':t'   => ($sourceTitle !== '' ? $sourceTitle : (string)($r['title'] ?? '')),
                ':a'   => (string)$r['activity'],
                ':p'   => '',                 // oggi parti vuoto
                ':pp'  => $newPrevPairs,      // regola richiesta
                ':ord' => ($r['activity_order'] === null || $r['activity_order'] === '') ? null : (int)$r['activity_order'],
            ]);

            $added++;
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'deleted' => $deleted,
            'added'   => $added,
            'source'  => $source,
            'target'  => $target
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}


/* ===================== SCHEMA ===================== */
function ensureSchema(SqliteDb $db)
{
    $db->query(
        "CREATE TABLE IF NOT EXISTS workout_log (
            id TEXT PRIMARY KEY,
            wo_date TEXT NOT NULL,
            origin_date TEXT NOT NULL,
            title TEXT NOT NULL,
            activity TEXT NOT NULL,
            pairs TEXT NOT NULL,
            prev_pairs TEXT,
            activity_order INTEGER
        )"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS workout_activies (
            id INTEGER PRIMARY KEY,
            activity TEXT NOT NULL,
            activity_type TEXT NOT NULL
        )"
    );
}


/* ===================== LOG ===================== */
function apiLog($msg, $level = 'INFO')
{
    $line = sprintf("[%s][%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
    @file_put_contents(__DIR__ . '/apilog.txt', $line, FILE_APPEND | LOCK_EX);
}

/* ===================== HELPERS ===================== */
function isAbsolutePath($path)
{
    $path = (string)$path;
    if ($path === '') return false;

    // Linux/Unix
    if ($path[0] === '/') return true;

    // Windows (C:\...)
    if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) return true;

    // UNC path (\\server\share)
    if (substr($path, 0, 2) === '\\\\') return true;

    return false;
}

function resolvePath($path, $baseDir)
{
    $path = trim((string)$path);
    if ($path === '') return rtrim($baseDir, '/\\') . '/';

    if (isAbsolutePath($path)) return $path;

    return rtrim($baseDir, '/\\') . '/' . ltrim($path, '/\\');
}


function normalizeDate($s)
{
    $s = trim((string)$s);
    if ($s === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : '';
}

function parsePairs($s)
{
    $out = [];
    foreach (explode('|', trim((string)$s)) as $p) {
        if (strpos($p, '@') === false) continue;
        [$r, $w] = explode('@', $p, 2);
        $out[] = ['reps' => (int)$r, 'weight' => (float)$w];
    }
    return $out;
}

function pairsToString($pairsJsonOrArray)
{
    // accetta sia array PHP che JSON string
    $arr = is_string($pairsJsonOrArray) ? json_decode($pairsJsonOrArray, true) : $pairsJsonOrArray;
    if (!is_array($arr) || !count($arr)) return '';

    $out = [];
    foreach ($arr as $p) {
        $reps = isset($p['reps']) ? (int)$p['reps'] : 0;
        $w    = isset($p['weight']) ? (float)$p['weight'] : 0;
        if ($reps > 0) $out[] = $reps . '@' . $w;
    }
    return implode('|', $out);
}

function jsonOk($extra = [])
{
    echo json_encode(array_merge(['success' => true], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function newId()
{
    return time() . '_' . mt_rand(1000, 9999);
}
