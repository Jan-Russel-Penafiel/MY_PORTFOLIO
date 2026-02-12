<?php
// Simple file-based view counter with unique visitor cookie + prompt limit tracking
header('Content-Type: application/json');
$filename = __DIR__ . '/counters.json';
$limitsFilename = __DIR__ . '/counters_limits.json';

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

// Helper to get client IP
function getClientIP() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Hash IP for privacy
    return hash('sha256', $ip . date('Y-m-d'));
}

// Helper to get today's date
function getTodayDate() {
    return date('Y-m-d');
}

// Load prompt limits from file
function loadLimits($limitsFilename) {
    if (!file_exists($limitsFilename)) {
        return [];
    }
    $contents = @file_get_contents($limitsFilename);
    $data = $contents ? json_decode($contents, true) : [];
    return is_array($data) ? $data : [];
}

// Save prompt limits to file with locking
function saveLimits($limitsFilename, $data) {
    $fp = fopen($limitsFilename, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

// Clean old entries (older than 2 days)
function cleanOldEntries($data, $currentDate) {
    $cleaned = [];
    foreach ($data as $key => $entry) {
        if (isset($entry['date'])) {
            $entryDate = strtotime($entry['date']);
            $current = strtotime($currentDate);
            // Keep entries from last 2 days
            if (($current - $entryDate) <= 2 * 86400) {
                $cleaned[$key] = $entry;
            }
        }
    }
    return $cleaned;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'increment_view';
    
    // Handle prompt limit actions
    if ($action === 'get_limits') {
        $clientIP = getClientIP();
        $today = getTodayDate();
        $limits = loadLimits($limitsFilename);
        
        // Clean old entries
        $limits = cleanOldEntries($limits, $today);
        
        // Get or create entry for this IP
        if (!isset($limits[$clientIP]) || $limits[$clientIP]['date'] !== $today) {
            // New day or new IP - reset counts
            $limits[$clientIP] = [
                'date' => $today,
                'prompt_count' => 0
            ];
            saveLimits($limitsFilename, $limits);
        }
        
        echo json_encode([
            'success' => true,
            'prompt_count' => $limits[$clientIP]['prompt_count'],
            'date' => $limits[$clientIP]['date']
        ]);
        exit;
    }
    
    if ($action === 'increment_prompt') {
        $clientIP = getClientIP();
        $today = getTodayDate();
        $limits = loadLimits($limitsFilename);
        
        // Clean old entries
        $limits = cleanOldEntries($limits, $today);
        
        // Get or create entry
        if (!isset($limits[$clientIP]) || $limits[$clientIP]['date'] !== $today) {
            $limits[$clientIP] = [
                'date' => $today,
                'prompt_count' => 0
            ];
        }
        
        // Increment prompt count
        $limits[$clientIP]['prompt_count']++;
        saveLimits($limitsFilename, $limits);
        
        echo json_encode([
            'success' => true,
            'prompt_count' => $limits[$clientIP]['prompt_count']
        ]);
        exit;
    }
    
    // Default action: increment view counter
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
