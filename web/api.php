<?php
// api.php
/*
0 => id
1 => date          (data di lavorazione / attuale)
2 => origin_date   (data da cui è stato generato il workout)
3 => activity
4 => pairs         (coppie reps@peso attuali)
5 => prev_pairs    (coppie reps@peso precedenti, per confronto)
*/
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
	case 'renameExercise':
        handleRenameExercise($user['log_csv']);
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
			if (count($row) < 6) continue;
			$id        = $row[0];
			$date      = $row[1];
			$activity  = $row[3];
			$pairsStr  = $row[4];

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
    fputcsv($fh, ['id','date','origin_date','activity','pairs','prev_pairs'], ';');
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
            if (count($row) < 6) continue;
            $id          = $row[0];
            $rowDate     = $row[1];
            $originDate  = $row[2] ?? $rowDate;
            $activity    = $row[3];
            $pairsStr    = $row[4];

            if ($rowDate !== $date) continue;

            $pairs = [];
            if ($pairsStr !== '') {
                foreach (explode('|', $pairsStr) as $p) {
                    $tmp    = explode('@', $p);
                    $reps   = isset($tmp[0]) ? (int)$tmp[0] : 0;
                    $weight = isset($tmp[1]) ? (float)$tmp[1] : 0;
                    if ($reps > 0) {
                        $pairs[] = ['reps' => $reps, 'weight' => $weight];
                    }
                }
            }

            $items[] = [
                'id'          => $id,
                'activity'    => $activity,
                'pairs'       => $pairs,
                // se ti servirà in UI
                'origin_date' => $originDate
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

		// vecchio schema o nuovo: normalizziamo comunque
		$activity   = $row[3] ?? $row[2];
		$pairsStr   = $row[4] ?? ($row[3] ?? '');

		$newId      = time() . '_' . mt_rand(1000, 9999);
		$originDate = $source;
		$newRows[]  = [
			$newId,        // id
			$target,       // date (lavorazione corrente)
			$originDate,   // origin_date
			$activity,     // activity
			'',            // pairs (vuote: le farai oggi)
			$pairsStr      // prev_pairs (storico da confrontare)
		];
	}

	$fh = fopen($logCsv, 'w');
	if (!$fh) {
		http_response_code(500);
		echo json_encode(['error' => 'Cannot write log CSV']);
		return;
	}
	fputcsv($fh, ['id','date','origin_date','activity','pairs','prev_pairs'], ';');
	foreach ($newRows as $row) {
		fputcsv($fh, $row, ';');
	}
	fclose($fh);
    echo json_encode(['success' => true, 'skipped' => false]);
}

function handleGetExercise($logCsv)
{
    $date     = $_GET['date'] ?? '';
    $activity = trim($_GET['activity'] ?? '');
    if ($date === '' || $activity === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date or activity']);
        return;
    }
    $date = normalizeDate($date);

    $pairs      = [];
    $prevPairs  = [];
    $id         = null;
    $originDate = $date;

    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (count($row) < 6) continue;
            if ($row[1] === $date && $row[3] === $activity) {
                $id         = $row[0];
                $originDate = $row[2] ?: $date;
                $pairsStr   = $row[4];
                $prevStr    = $row[5];

                if ($pairsStr !== '') {
                    foreach (explode('|', $pairsStr) as $p) {
                        $tmp    = explode('@', $p);
                        $reps   = isset($tmp[0]) ? (int)$tmp[0] : 0;
                        $weight = isset($tmp[1]) ? (float)$tmp[1] : 0;
                        if ($reps > 0) {
                            $pairs[] = ['reps' => $reps, 'weight' => $weight];
                        }
                    }
                }

                if ($prevStr !== '') {
                    foreach (explode('|', $prevStr) as $p) {
                        $tmp    = explode('@', $p);
                        $reps   = isset($tmp[0]) ? (int)$tmp[0] : 0;
                        $weight = isset($tmp[1]) ? (float)$tmp[1] : 0;
                        if ($reps > 0) {
                            $prevPairs[] = ['reps' => $reps, 'weight' => $weight];
                        }
                    }
                }
                break;
            }
        }
        fclose($fh);
    }

    echo json_encode([
        'id'          => $id,
        'date'        => $date,
        'origin_date' => $originDate,
        'activity'    => $activity,
        'pairs'       => $pairs,
        'prev_pairs'  => $prevPairs
    ]);
}


function handleSaveExercisePairs($logCsv)
{
    $date     = isset($_POST['date']) ? normalizeDate($_POST['date']) : '';
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
        $reps   = isset($p['reps']) ? (int)$p['reps'] : 0;
        $weight = isset($p['weight']) ? (float)$p['weight'] : 0;
        if ($reps > 0) {
            $parts[] = $reps . '@' . $weight;
        }
    }
    $pairsStr = implode('|', $parts);

    $rows  = [];
    $found = false;

    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (count($row) < 6) {
                // upgrade eventuali righe vecchie (4 colonne)
                $row = array_pad($row, 6, '');
                if ($row[2] === '') {
                    $row[2] = $row[1]; // origin_date = date
                }
            }

            if ($row[1] === $date && $row[3] === $activity) {
                $found       = true;
                // mantieni origin_date e prev_pairs, aggiorna solo pairs
                $row[4]      = $pairsStr;
            }
            $rows[] = $row;
        }
        fclose($fh);
    }

    if (!$found) {
        $id          = time() . '_' . mt_rand(1000, 9999);
        $originDate  = $date; // nuovo esercizio, origine = oggi
        $prevStr     = '';
        $rows[] = [$id, $date, $originDate, $activity, $pairsStr, $prevStr];
    }

    $fh = fopen($logCsv, 'w');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write log CSV']);
        return;
    }
    fputcsv($fh, ['id','date','origin_date','activity','pairs','prev_pairs'], ';');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ';');
    }
    fclose($fh);

    echo json_encode(['success' => true]);
}


function handleDeleteExercise($logCsv)
{
    $date     = isset($_POST['date']) ? normalizeDate($_POST['date']) : '';
    $activity = trim($_POST['activity'] ?? '');

    if ($date === '' || $activity === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date or activity']);
        return;
    }

    $rows = [];
    $deleted = false;

    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';'); // header

        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            // upgrade righe vecchie
            if (count($row) < 6) {
                $row = array_pad($row, 6, '');
                if ($row[2] === '') $row[2] = $row[1]; // origin_date = date
            }
            // schema: 0=id,1=date,2=origin_date,3=activity,4=pairs,5=prev_pairs
            if ($row[1] === $date && $row[3] === $activity) {
                $deleted = true;
                continue; // SALTA la riga da eliminare
            }

            $rows[] = $row;
        }
        fclose($fh);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open log CSV']);
        return;
    }

    $fh = fopen($logCsv, 'w');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write log CSV']);
        return;
    }

    fputcsv($fh, ['id','date','origin_date','activity','pairs','prev_pairs'], ';');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ';');
    }
    fclose($fh);

    echo json_encode(['success' => true, 'deleted' => $deleted]);
}

function handleRenameExercise($logCsv)
{
    $date        = isset($_POST['date']) ? trim($_POST['date']) : '';
    $oldActivity = isset($_POST['old_activity']) ? trim($_POST['old_activity']) : '';
    $newActivity = isset($_POST['new_activity']) ? trim($_POST['new_activity']) : '';

    if ($date === '' || $oldActivity === '' || $newActivity === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date or activity']);
        return;
    }

    // normalizza la data in formato YYYY-MM-DD se usi già una funzione simile
    if (function_exists('normalizeDate')) {
        $date = normalizeDate($date);
    }

    $rows    = [];
    $changed = false;

    if (($fh = fopen($logCsv, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';');
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            // aggiorna eventuali righe vecchie: porta a 6 colonne
            if (count($row) < 6) {
                $row = array_pad($row, 6, '');
                if ($row[2] === '') {
                    $row[2] = $row[1]; // origin_date = date
                }
            }

            // schema: 0=id,1=date,2=origin_date,3=activity,4=pairs,5=prev_pairs
            if ($row[1] === $date && $row[3] === $oldActivity) {
                $row[3] = $newActivity;
                $changed = true;
            }
            $rows[] = $row;
        }
        fclose($fh);
    }

    if (!$changed) {
        echo json_encode(['error' => 'Exercise not found']);
        return;
    }

    $fh = fopen($logCsv, 'w');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write log CSV']);
        return;
    }
    // header coerente con il resto del progetto
    fputcsv($fh, ['id','date','origin_date','activity','pairs','prev_pairs'], ';');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ';');
    }
    fclose($fh);

    echo json_encode(['success' => true]);
}

