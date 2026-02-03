<?php
// api.php
/*
php 7.x

Compatibilità: se il CSV è nel vecchio formato (senza title), l'API lo “upgrada” in memoria
e, quando riscrive, usa sempre l'header completo.
*/
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/sqlite_helper.php';
$BASE_DIR = __DIR__;

/* ===================== LOG ===================== */
function apiLog($msg, $level = 'INFO')
{
    $line = sprintf("[%s][%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
    @file_put_contents(__DIR__ . '/apilog.txt', $line, FILE_APPEND | LOCK_EX);
}


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

/* ===================== HELPERS ===================== */
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
        [$r,$w] = explode('@', $p, 2);
        $out[] = ['reps'=>(int)$r, 'weight'=>(float)$w];
    }
    return $out;
}

function pairsToString(array $pairs)
{
    $out = [];
    foreach ($pairs as $p) {
        if (!isset($p['reps'],$p['weight'])) continue;
        $out[] = (int)$p['reps'].'@'.(float)$p['weight'];
    }
    return implode('|', $out);
}

function newId()
{
    return time().'_'.mt_rand(1000,9999);
}

/* ===================== CONFIG ===================== */
$configPath = $BASE_DIR .'/config.json';
if (!file_exists($configPath)) {
    apiLog('config.json not found: ' . $configPath, 'ERROR');
    echo json_encode(['error'=>'Missing config.json']);
    exit;
}

$configRaw = file_get_contents($configPath);
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
    echo json_encode(['error'=>'No action']);
    exit;
}

switch ($action) {

    case 'listActivities':
        echo json_encode([
            'items'=>$db->queryDt(
                "SELECT id, activity, activity_type FROM workout_activies ORDER BY activity"
            )
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'listWorkoutDates':
        echo json_encode([
            'items'=>$db->queryDt(
                "SELECT wo_date AS date, MIN(title) AS title, COUNT(*) AS count
                 FROM workout_log
                 GROUP BY wo_date
                 ORDER BY wo_date DESC
                 LIMIT 20"
            )
        ], JSON_UNESCAPED_UNICODE);
        break;
    case 'cloneWorkout':
        handleCloneWorkout($db); 
        break;
    
    case 'getWorkout':
        $date = normalizeDate($_GET['date'] ?? '');
        $rows = $db->queryDt(
            "SELECT id, title, activity, pairs, prev_pairs, origin_date, activity_order
             FROM workout_log
             WHERE wo_date = :d
             ORDER BY activity_order, activity",
            [':d'=>$date]
        );

        foreach ($rows as &$r) {
            $r['pairs'] = parsePairs($r['pairs']);
            $r['prev_pairs'] = parsePairs($r['prev_pairs']);
        }

        echo json_encode([
            'date'=>$date,
            'title'=>$rows[0]['title'] ?? '',
            'items'=>$rows
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'renameExercise':
        handleRenameExercise($db);
        break;        

    case 'getExercise':
        $date = normalizeDate($_GET['date'] ?? '');
        $activity = trim($_GET['activity'] ?? '');

        if ($date === '' || $activity === '') {
            echo json_encode(['error' => 'Missing date or activity']);
            break;
        }

        $rows = $db->queryDt(
            "SELECT pairs, prev_pairs
            FROM workout_log
            WHERE wo_date = :d AND activity = :a
            ORDER BY activity_order, id
            LIMIT 1",
            [':d' => $date, ':a' => $activity]
        );

        $pairs = [];
        $prevPairs = [];
        if (count($rows) > 0) {
            $pairs = parsePairs($rows[0]['pairs'] ?? '');
            $prevPairs = parsePairs($rows[0]['prev_pairs'] ?? '');
        }

        echo json_encode([
            'date' => $date,
            'activity' => $activity,
            'pairs' => $pairs,
            'prev_pairs' => $prevPairs
        ], JSON_UNESCAPED_UNICODE);
        break;        

    case 'saveExercisePairs':
        $date = normalizeDate($_POST['date'] ?? '');
        $activity = trim($_POST['activity'] ?? '');
        $pairs = json_decode($_POST['pairs'] ?? '[]', true);

        $db->query(
            "INSERT OR REPLACE INTO workout_log
             (id,wo_date,origin_date,title,activity,pairs,prev_pairs,activity_order)
             VALUES
             (:id,:d,:o,:t,:a,:p,:pp,:ord)",
            [
                ':id'=>newId(),
                ':d'=>$date,
                ':o'=>$date,
                ':t'=>$_POST['title'] ?? '',
                ':a'=>$activity,
                ':p'=>pairsToString($pairs),
                ':pp'=>'',
                ':ord'=>$_POST['activity_order'] ?? null
            ]
        );

        echo json_encode(['success'=>true]);
        break;

    default:
        echo json_encode(['error'=>'Unknown action']);
}

function handleRenameExercise(SqliteDb $db)
{
    $date = normalizeDate($_POST['date'] ?? ($_GET['date'] ?? ''));
    $oldName = trim($_POST['old_name'] ?? ($_POST['activity'] ?? ($_GET['activity'] ?? '')));
    $newName = trim($_POST['new_name'] ?? ($_GET['new_name'] ?? ''));

    if ($date === '' || $oldName === '' || $newName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date/old_name/new_name']);
        return;
    }

    // rinomina SOLO per quel giorno
    $n = $db->query(
        "UPDATE workout_log
         SET activity = :new
         WHERE wo_date = :d AND activity = :old;",
        [
            ':new' => $newName,
            ':d'   => $date,
            ':old' => $oldName
        ]
    );

    echo json_encode([
        'success' => true,
        'updated' => $n
    ], JSON_UNESCAPED_UNICODE);
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
        if (trim((string)$r['title']) !== '') { $sourceTitle = (string)$r['title']; break; }
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
