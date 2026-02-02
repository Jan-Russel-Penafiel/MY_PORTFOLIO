<?php
// Simple file-based view counter with unique visitor cookie
header('Content-Type: application/json');
$filename = __DIR__ . '/counters.json';

// Ensure file exists
if (!file_exists($filename)) {
    file_put_contents($filename, json_encode(['total' => 0, 'unique' => 0]));
}

$data = ['total' => 0, 'unique' => 0];
$contents = @file_get_contents($filename);
if ($contents) {
    $tmp = json_decode($contents, true);
    if (is_array($tmp)) $data = $tmp;
}

// Helper to safely write JSON with locking
function safe_write($filename, $dataArr) {
    $fp = fopen($filename, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($dataArr));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // When POST: increment total; if no visitor cookie, also increment unique and set cookie
    $isNewUnique = false;
    if (empty($_COOKIE['jr_visitor'])) {
        $isNewUnique = true;
        // set cookie for 1 year
        setcookie('jr_visitor', bin2hex(random_bytes(8)), time() + 60*60*24*365, '/');
    }

    // Re-read current counters then update under lock
    $fp = fopen($filename, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            $fileContents = stream_get_contents($fp);
            $current = $fileContents ? json_decode($fileContents, true) : ['total' => 0, 'unique' => 0];
            if (!is_array($current)) $current = ['total' => 0, 'unique' => 0];
            $current['total'] = (int)($current['total'] ?? 0) + 1;
            if ($isNewUnique) $current['unique'] = (int)($current['unique'] ?? 0) + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($current));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    // Return updated counters
    $newContents = @file_get_contents($filename);
    $out = $newContents ? json_decode($newContents, true) : $data;
    echo json_encode($out);
    exit;
} else {
    // GET: return current counters (read-only)
    $fp = fopen($filename, 'r');
    if ($fp) {
        if (flock($fp, LOCK_SH)) {
            $fileContents = stream_get_contents($fp);
            $out = $fileContents ? json_decode($fileContents, true) : $data;
            flock($fp, LOCK_UN);
        } else {
            $out = $data;
        }
        fclose($fp);
    } else {
        $out = $data;
    }
    echo json_encode($out);
    exit;
}
