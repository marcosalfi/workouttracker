<?php
// api.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/common.php';

$user = requireLogin();

$action = $_GET['action'] ?? ($_POST['action'] ?? null);
if ($action === null) {
    echo json_encode(['error' => 'No action']);
    exit;
}

$logCsv  = $user['log_csv'];
$routineCsv = $user['workout_routine_csv'];

ensureLogCsvExists($logCsv);

switch ($action) {
    case 'listActivities':
        handleListActivities($routineCsv);
        break;
    case 'addLog':
        handleAddLog($logCsv);
        break;
    case 'listLog':
        handleListLog($logCsv);
        break;
    case 'deleteLog':
        handleDeleteLog($logCsv);
        break;
    case 'listWorkoutDates':
        handleListWorkoutDates($logCsv);
        break;
    case 'getWorkout':
        handleGetWorkout($logCsv);
        break;
    case 'cloneWorkout':
        handleCloneWorkout($logCsv);
        break;
    case 'getExercise':
        handleGetExercise($logCsv);
        break;
    case 'saveExercisePairs':
        handleSaveExercisePairs($logCsv);
        break;
    case 'deleteExercise':
        handleDeleteExercise($logCsv);
        break;
    default:
        echo json_encode(['error' => 'Unknown action']);
}

// ---------- Handlers ----------

function handleListActivities($routineCsv)
{
    $activities = [];
    if (!file_exists($routineCsv)) {
        echo json_encode(['activities' => []]);
        return;
    }

    if (($fh = fopen($routineCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ',');
        if ($header === false) {
            fclose($fh);
            echo json_encode(['activities' => []]);
            return;
        }
        $activityIndex = array_search('activity', $header);
        if ($activityIndex === false) {
            fclose($fh);
            echo json_encode(['activities' => []]);
            return;
        }

        while (($row = fgetcsv($fh, 0, ',')) !== false) {
            if (!isset($row[$activityIndex])) continue;
            $act = trim($row[$activityIndex]);
            if ($act !== '' && !in_array($act, $activities, true)) {
                $activities[] = $act;
            }
        }
        fclose($fh);
    }

    sort($activities);
    echo json_encode(['activities' => $activities]);
}

function handleAddLog($logCsv)
{
    $date     = $_POST['date'] ?? '';
    $activity = trim($_POST['activity'] ?? '');
    $pairsJson = $_POST['pairs'] ?? '[]';

    if ($date === '' || $activity === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date or activity']);
        return;
    }

    $pairsArr = json_decode($pairsJson, true);
    if (!is_array($pairsArr) || count($pairsArr) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No reps/weight pairs']);
        return;
    }

    $parts = [];
    foreach ($pairsArr as $p) {
        $reps = isset($p['reps']) ? (int)$p['reps'] : 0;
        $weight = isset($p['weight']) ? (float)$p['weight'] : 0;
        if ($reps > 0) {
            $parts[] = $reps . '@' . $weight;
        }
    }
    if (count($parts) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid pairs']);
        return;
    }

    $pairsStr = implode('|', $parts);
    $id = time() . '_' . mt_rand(1000, 9999);

    $fh = fopen($logCsv, 'a');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open log CSV']);
        return;
    }
    fputcsv($fh, [$id, $date, $activity, $pairsStr], ';');
    fclose($fh);

    echo json_encode(['success' => true, 'id' => $id]);
}

function handleListLog($logCsv)
{
    $items = [];
    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (count($row) < 4) continue;
            list($id, $date, $activity, $pairsStr) = $row;
            $pairsHuman = [];
            if ($pairsStr !== '') {
                $pairs = explode('|', $pairsStr);
                foreach ($pairs as $p) {
                    $tmp = explode('@', $p);
                    $reps = $tmp[0] ?? '';
                    $weight = $tmp[1] ?? '';
                    if ($reps !== '') {
                        $pairsHuman[] = $reps . 'x' . $weight;
                    }
                }
            }
            $items[] = [
                'id'       => $id,
                'date'     => $date,
                'activity' => $activity,
                'pairs'    => implode(', ', $pairsHuman)
            ];
        }
        fclose($fh);
    }
    echo json_encode(['items' => $items]);
}

function handleDeleteLog($logCsv)
{
    $id = $_POST['id'] ?? '';
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        return;
    }

    $rows = [];
    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            $rows[] = $row;
        }
        fclose($fh);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open log CSV']);
        return;
    }

    $newRows = [];
    foreach ($rows as $r) {
        if (!isset($r[0]) || $r[0] !== $id) {
            $newRows[] = $r;
        }
    }

    $fh = fopen($logCsv, 'w');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write log CSV']);
        return;
    }
    fputcsv($fh, ['id','date','activity','pairs'], ';');
    foreach ($newRows as $r) {
        fputcsv($fh, $r, ';');
    }
    fclose($fh);

    echo json_encode(['success' => true]);
}

function handleListWorkoutDates($logCsv)
{
    $dates = [];
    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (!isset($row[1])) continue;
            $date = $row[1];
            if ($date !== '' && !in_array($date, $dates, true)) {
                $dates[] = $date;
            }
        }
        fclose($fh);
    }
    sort($dates);
    echo json_encode(['dates' => $dates]);
}

function handleGetWorkout($logCsv)
{
    $date = $_GET['date'] ?? '';
    if ($date === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date']);
        return;
    }
    $date = normalizeDate($date);

    $items = [];
    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (count($row) < 4) continue;
            list($id, $rowDate, $activity, $pairsStr) = $row;
            if ($rowDate !== $date) continue;

            $pairs = [];
            if ($pairsStr !== '') {
                foreach (explode('|', $pairsStr) as $p) {
                    $tmp = explode('@', $p);
                    $reps = isset($tmp[0]) ? (int)$tmp[0] : 0;
                    $weight = isset($tmp[1]) ? (float)$tmp[1] : 0;
                    if ($reps > 0) {
                        $pairs[] = ['reps' => $reps, 'weight' => $weight];
                    }
                }
            }
            $items[] = [
                'id'       => $id,
                'activity' => $activity,
                'pairs'    => $pairs
            ];
        }
        fclose($fh);
    }

    echo json_encode(['items' => $items]);
}

function handleCloneWorkout($logCsv)
{
    $source = isset($_POST['source']) ? normalizeDate($_POST['source']) : '';
    $target = isset($_POST['target']) ? normalizeDate($_POST['target']) : '';

    if ($source === '' || $target === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing source/target']);
        return;
    }

    $rows = [];
    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            $rows[] = $row;
        }
        fclose($fh);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open log CSV']);
        return;
    }

    foreach ($rows as $row) {
        if (isset($row[1]) && $row[1] === $target) {
            echo json_encode(['success' => true, 'skipped' => true]);
            return;
        }
    }

    $newRows = $rows;
    foreach ($rows as $row) {
        if (!isset($row[1]) || $row[1] !== $source) continue;
        $newId = time() . '_' . mt_rand(1000, 9999);
        $row[0] = $newId;
        $row[1] = $target;
        $newRows[] = $row;
    }

    $fh = fopen($logCsv, 'w');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write log CSV']);
        return;
    }
    fputcsv($fh, ['id','date','activity','pairs'], ';');
    foreach ($newRows as $row) {
        fputcsv($fh, $row, ';');
    }
    fclose($fh);

    echo json_encode(['success' => true, 'skipped' => false]);
}

function handleGetExercise($logCsv)
{
    $date = $_GET['date'] ?? '';
    $activity = trim($_GET['activity'] ?? '');
    if ($date === '' || $activity === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date or activity']);
        return;
    }
    $date = normalizeDate($date);

    $pairs = [];
    $id = null;

    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (count($row) < 4) continue;
            if ($row[1] === $date && $row[2] === $activity) {
                $id = $row[0];
                $pairsStr = $row[3];
                if ($pairsStr !== '') {
                    foreach (explode('|', $pairsStr) as $p) {
                        $tmp = explode('@', $p);
                        $reps = isset($tmp[0]) ? (int)$tmp[0] : 0;
                        $weight = isset($tmp[1]) ? (float)$tmp[1] : 0;
                        if ($reps > 0) {
                            $pairs[] = ['reps' => $reps, 'weight' => $weight];
                        }
                    }
                }
                break;
            }
        }
        fclose($fh);
    }

    echo json_encode([
        'id'       => $id,
        'date'     => $date,
        'activity' => $activity,
        'pairs'    => $pairs
    ]);
}

function handleSaveExercisePairs($logCsv)
{
    $date = isset($_POST['date']) ? normalizeDate($_POST['date']) : '';
    $activity = trim($_POST['activity'] ?? '');
    $pairsJson = $_POST['pairs'] ?? '[]';

    if ($date === '' || $activity === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date or activity']);
        return;
    }

    $pairsArr = json_decode($pairsJson, true);
    if (!is_array($pairsArr)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid pairs']);
        return;
    }

    $parts = [];
    foreach ($pairsArr as $p) {
        $reps = isset($p['reps']) ? (int)$p['reps'] : 0;
        $weight = isset($p['weight']) ? (float)$p['weight'] : 0;
        if ($reps > 0) {
            $parts[] = $reps . '@' . $weight;
        }
    }
    $pairsStr = implode('|', $parts);

    $rows = [];
    $found = false;

    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if ($row[1] === $date && $row[2] === $activity) {
                $found = true;
                $row[3] = $pairsStr;
            }
            $rows[] = $row;
        }
        fclose($fh);
    }

    if (!$found) {
        $id = time() . '_' . mt_rand(1000, 9999);
        $rows[] = [$id, $date, $activity, $pairsStr];
    }

    $fh = fopen($logCsv, 'w');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write log CSV']);
        return;
    }
    fputcsv($fh, ['id','date','activity','pairs'], ';');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ';');
    }
    fclose($fh);

    echo json_encode(['success' => true]);
}

function handleDeleteExercise($logCsv)
{
    $date = $_POST['date'] ?? '';
    $activity = trim($_POST['activity'] ?? '');
    if ($date === '' || $activity === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date or activity']);
        return;
    }
    $date = normalizeDate($date);

    $rows = [];
    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (!($row[1] === $date && $row[2] === $activity)) {
                $rows[] = $row;
            }
        }
        fclose($fh);
    }

    $fh = fopen($logCsv, 'w');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write log CSV']);
        return;
    }
    fputcsv($fh, ['id','date','activity','pairs'], ';');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ';');
    }
    fclose($fh);

    echo json_encode(['success' => true]);
}
