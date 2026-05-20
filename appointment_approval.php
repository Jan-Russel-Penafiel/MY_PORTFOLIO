<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

loadDotEnv(__DIR__ . '/.env');

$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? '';
$token = $_GET['token'] ?? '';

if (!in_array($action, ['approve', 'disapprove'], true) || $id === '' || $token === '') {
    displayResultPage(false, 'Invalid Link', 'This approval link is missing or has invalid parameters.', null);
    exit;
}

$expectedToken = generateAppointmentToken($id);
if (!hash_equals($expectedToken, $token)) {
    displayResultPage(false, 'Invalid Link', 'This approval link is invalid or has expired.', null);
    exit;
}

$dataFile = __DIR__ . '/appointments.json';
if (!is_file($dataFile)) {
    displayResultPage(false, 'Not Found', 'No appointments exist yet. The link may be stale.', null);
    exit;
}

$fp = fopen($dataFile, 'c+');
if ($fp === false || !flock($fp, LOCK_EX)) {
    if ($fp !== false) {
        fclose($fp);
    }
    displayResultPage(false, 'Server Error', 'Could not open appointments storage. Please try again.', null);
    exit;
}

$appointments = json_decode((string) stream_get_contents($fp), true);
if (!is_array($appointments)) {
    $appointments = [];
}

$foundIndex = -1;
foreach ($appointments as $i => $appt) {
    if (($appt['id'] ?? '') === $id) {
        $foundIndex = $i;
        break;
    }
}

if ($foundIndex === -1) {
    flock($fp, LOCK_UN);
    fclose($fp);
    displayResultPage(false, 'Not Found', 'That appointment could not be found. It may have been removed.', null);
    exit;
}

$appointment = $appointments[$foundIndex];
$currentStatus = $appointment['status'] ?? 'pending';

// Already decided: idempotent — report the existing status, do not change it.
if ($currentStatus !== 'pending') {
    flock($fp, LOCK_UN);
    fclose($fp);
    $label = $currentStatus === 'approved' ? 'already approved' : 'already disapproved';
    displayResultPage(
        true,
        'Already Decided',
        'This appointment was ' . $label . '. No changes were made.',
        $appointment
    );
    exit;
}

$newStatus = $action === 'approve' ? 'approved' : 'disapproved';
$appointment['status'] = $newStatus;
$appointment['decidedAt'] = date('Y-m-d H:i:s');
$appointments[$foundIndex] = $appointment;

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($appointments, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

if ($newStatus === 'approved') {
    // Record the approved appointment as a Project row in the task PMS database.
    // Non-blocking: a failure here surfaces as a warning but does not undo the
    // approval — the JSON status flip is authoritative.
    $debugLog = [];
    $projectWarning = recordApprovedAppointmentAsProject($appointment, $debugLog);

    // When ?debug=1 is appended to the approve URL, dump the step-by-step
    // diagnostic trace to a file next to this script. Useful on shared hosts
    // where reading the PHP error_log is not possible. Safe to leave in
    // production because it only writes when the flag is set explicitly.
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        $logFile = __DIR__ . '/debug_approval_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $id) . '.log';
        @file_put_contents(
            $logFile,
            "Approval diagnostic for {$id} at " . date('c') . "\n"
                . "Result: " . ($projectWarning === null ? 'SUCCESS (project recorded)' : 'WARNING: ' . $projectWarning) . "\n\n"
                . implode("\n", $debugLog) . "\n",
            LOCK_EX
        );
    }

    $message = 'The appointment is now confirmed and shows as approved on the calendar.';
    if ($projectWarning !== null) {
        $message .= ' Note: ' . $projectWarning;
    } else {
        $message .= ' A matching Project was added to your task dashboard.';
    }
    displayResultPage(true, 'Appointment Approved', $message, $appointment);
} else {
    displayResultPage(true, 'Appointment Disapproved', 'The appointment was disapproved. That date is now free for other clients to book.', $appointment);
}

// ---------------- helpers ----------------

/**
 * Insert an approved appointment as a row in the task PMS `projects` table.
 *
 * Idempotent on appointment id (UNIQUE constraint + pre-check) so re-clicking
 * the approve link does not create duplicates.
 *
 * Returns null on success, or a human-readable warning string on failure
 * (so the approval result page can surface it without rolling back the
 * already-flipped JSON status).
 */
function recordApprovedAppointmentAsProject(array $appointment, array &$debugLog = []): ?string
{
    $debugLog[] = '[step 1] __DIR__ = ' . __DIR__;

    // Locate the task PMS install. Supports either layout:
    //   - doc/ and task/ as siblings   (../task)
    //   - task/ nested inside doc/      (./task)
    $candidates = [__DIR__ . '/task', __DIR__ . '/../task'];
    $taskBase = null;
    foreach ($candidates as $candidate) {
        $hasFunctions = is_file($candidate . '/includes/functions.php');
        $hasAi        = is_file($candidate . '/includes/ai_functions.php');
        $debugLog[] = '[step 1] probe ' . $candidate
            . ' functions.php=' . ($hasFunctions ? 'yes' : 'no')
            . ' ai_functions.php=' . ($hasAi ? 'yes' : 'no');
        if ($hasFunctions && $hasAi) {
            $taskBase = $candidate;
            break;
        }
    }
    if ($taskBase === null) {
        $debugLog[] = '[step 1] FAIL: no candidate matched';
        error_log('approval: task PMS includes not found in any known location');
        return 'Project dashboard files were not found — please add this project manually.';
    }
    $debugLog[] = '[step 1] OK taskBase = ' . $taskBase;
    $functionsPath  = $taskBase . '/includes/functions.php';
    $aiFunctionsPath = $taskBase . '/includes/ai_functions.php';

    try {
        require_once $functionsPath;
        require_once $aiFunctionsPath;
        $debugLog[] = '[step 2] OK includes loaded';
    } catch (Throwable $e) {
        $debugLog[] = '[step 2] FAIL: ' . $e->getMessage();
        error_log('approval: failed to load task PMS includes: ' . $e->getMessage());
        return 'Could not reach the project dashboard — please add this project manually.';
    }

    global $conn;
    $debugLog[] = '[step 3] $conn isset=' . (isset($conn) ? 'yes' : 'no')
        . ' type=' . (isset($conn) ? gettype($conn) : 'n/a')
        . ' class=' . (isset($conn) && is_object($conn) ? get_class($conn) : 'n/a');
    if (defined('DB_HOST')) {
        $debugLog[] = '[step 3] DB constants: host=' . DB_HOST
            . ' name=' . DB_NAME
            . ' user=' . DB_USER
            . ' pass_set=' . (DB_PASS === '' ? 'no(empty)' : 'yes(' . strlen(DB_PASS) . ' chars)');
    } else {
        $debugLog[] = '[step 3] DB_HOST constant NOT defined — config/config.php did not load';
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        // Try connecting ourselves to capture the actual error.
        if (defined('DB_HOST')) {
            try {
                $probe = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                if ($probe->connect_errno) {
                    $debugLog[] = '[step 3] manual connect_errno=' . $probe->connect_errno
                        . ' connect_error=' . $probe->connect_error;
                } else {
                    $debugLog[] = '[step 3] manual connect SUCCEEDED unexpectedly — using $probe instead';
                    $conn = $probe;
                }
            } catch (Throwable $e) {
                $debugLog[] = '[step 3] manual connect threw: ' . $e->getMessage();
            }
        }
        if (!isset($conn) || !($conn instanceof mysqli)) {
            $debugLog[] = '[step 3] FAIL: $conn not usable';
            return 'Project dashboard database is unavailable — please add this project manually.';
        }
    }
    $debugLog[] = '[step 3] OK $conn is mysqli; host_info=' . $conn->host_info;

    $apptId = (string) ($appointment['id'] ?? '');
    if ($apptId === '') {
        $debugLog[] = '[step 4] FAIL: appointment has no id';
        return 'Appointment is missing an id — cannot record the project.';
    }
    $debugLog[] = '[step 4] OK apptId = ' . $apptId;

    try {
        $stmt = $conn->prepare('SELECT id FROM projects WHERE appointment_id = ? LIMIT 1');
        $stmt->bind_param('s', $apptId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            $debugLog[] = '[step 5] SKIP: already recorded as project id ' . $existing['id'];
            return null;
        }
        $debugLog[] = '[step 5] OK no existing project, proceeding to insert';
    } catch (Throwable $e) {
        $debugLog[] = '[step 5] FAIL duplicate-check: ' . $e->getMessage();
        error_log('approval: duplicate-check failed: ' . $e->getMessage());
        return 'Could not verify project uniqueness — skipped to avoid duplicates. Please check the dashboard.';
    }

    $projectName = (string) ($appointment['projectName'] ?? '');
    $projectType = (string) ($appointment['projectType'] ?? 'Simple Project');
    $clientFb    = (string) ($appointment['facebookName'] ?? '');
    $deadline    = (string) ($appointment['projectDeadline'] ?? '');
    $price       = 0.00;
    $status      = 'Pending';

    $debugLog[] = '[step 6] fields: name=' . $projectName
        . ' type=' . $projectType
        . ' fb=' . $clientFb
        . ' deadline=' . $deadline;

    if ($projectName === '' || $clientFb === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
        $debugLog[] = '[step 6] FAIL: required field empty or deadline malformed';
        return 'Appointment fields are incomplete — could not record project.';
    }

    try {
        $duration = gemini_estimate_duration($projectName, $projectType, $deadline);
        if ($duration === null) {
            $duration = 1;
        }

        $others = other_projects_windows($conn, 0);
        $window = gemini_target_time($projectName, $duration, $deadline, $others);
        if ($window === null) {
            $window = fallback_target_time($duration, $deadline, $others);
        }
        $debugLog[] = '[step 7] OK duration=' . $duration
            . ' window=' . $window['target_start'] . '..' . $window['target_end'];
    } catch (Throwable $e) {
        $debugLog[] = '[step 7] AI failed, using fallback: ' . $e->getMessage();
        error_log('approval: AI scheduling failed: ' . $e->getMessage());
        $duration = 1;
        $window = fallback_target_time($duration, $deadline, []);
    }

    try {
        $stmt = $conn->prepare(
            'INSERT INTO projects
                (project_name, project_type, client_fb_name, deadline,
                 estimated_duration, target_start, target_end, price, status,
                 appointment_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'ssssissdss',
            $projectName, $projectType, $clientFb, $deadline,
            $duration, $window['target_start'], $window['target_end'],
            $price, $status, $apptId
        );
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();
        $debugLog[] = '[step 8] OK INSERT succeeded, new project id=' . $newId;
    } catch (Throwable $e) {
        // mysqli duplicate-key (1062) means another approval click won the race —
        // not a real failure, just report success.
        if (str_contains($e->getMessage(), '1062')) {
            $debugLog[] = '[step 8] OK duplicate-key race, treating as success';
            return null;
        }
        $debugLog[] = '[step 8] FAIL INSERT: ' . $e->getMessage();
        error_log('approval: INSERT projects failed: ' . $e->getMessage());
        return 'Project dashboard insert failed — please add this project manually.';
    }

    return null;
}

function generateAppointmentToken(string $appointmentId): string
{
    $secret = envValue('APPROVAL_SECRET') ?: 'default-secret-change-me';
    return hash_hmac('sha256', $appointmentId, $secret);
}

function displayResultPage(bool $success, string $title, string $message, ?array $appointment): void
{
    $accent = $success ? '#28a745' : '#ff4757';
    $accent2 = $success ? '#20c997' : '#ff6b81';
    $icon = $success ? '&#10003;' : '&#10005;';
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    $safeMessage = htmlspecialchars($message, ENT_QUOTES);

    $detailsHtml = '';
    if ($appointment !== null) {
        $pn = htmlspecialchars($appointment['projectName'] ?? '', ENT_QUOTES);
        $pt = htmlspecialchars($appointment['projectType'] ?? '', ENT_QUOTES);
        $fb = htmlspecialchars($appointment['facebookName'] ?? '', ENT_QUOTES);
        $dl = htmlspecialchars($appointment['deadline'] ?? '', ENT_QUOTES);
        $pd = htmlspecialchars($appointment['projectDeadline'] ?? '', ENT_QUOTES);
        $st = htmlspecialchars(strtoupper((string) ($appointment['status'] ?? '')), ENT_QUOTES);
        $detailsHtml = <<<DET
        <div style="background:rgba(0,255,247,0.05); border:1px solid rgba(0,255,247,0.2); border-radius:15px; padding:24px; margin:24px 0; text-align:left;">
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Project</span><span style="color:#ffffff; font-weight:600;">{$pn}</span></div>
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Type</span><span style="color:#ffffff; font-weight:600;">{$pt}</span></div>
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Client (FB Name)</span><span style="color:#ffffff; font-weight:600;">{$fb}</span></div>
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Appointment Date</span><span style="color:#00fff7; font-weight:700;">{$dl}</span></div>
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Deadline of the Project</span><span style="color:#00fff7; font-weight:700;">{$pd}</span></div>
            <div style="display:flex; justify-content:space-between; padding:12px 0;"><span style="color:#7f8ea8; font-weight:600;">Status</span><span style="color:{$accent}; font-weight:700;">{$st}</span></div>
        </div>
DET;
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle}</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',Arial,sans-serif; background:linear-gradient(135deg,#0a0e1a 0%,#1a1c2e 100%); color:#e0e6ed; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
        .card { max-width:640px; width:100%; background:linear-gradient(135deg,#181825 0%,#1a1c2e 100%); border:1.5px solid rgba(0,255,247,0.3); border-radius:20px; padding:48px 40px; text-align:center; }
        .icon { width:96px; height:96px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 26px; font-size:54px; color:#fff; background:linear-gradient(135deg,{$accent} 0%,{$accent2} 100%); }
        h1 { font-size:30px; font-weight:700; color:{$accent}; margin-bottom:14px; }
        .msg { color:#b0b8c8; font-size:16px; line-height:1.6; }
        .home-btn { display:inline-block; background:linear-gradient(135deg,#00fff7 0%,#00d4d4 100%); color:#0a0e1a; padding:14px 36px; border-radius:12px; text-decoration:none; font-weight:700; font-size:15px; margin-top:26px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{$icon}</div>
        <h1>{$safeTitle}</h1>
        <p class="msg">{$safeMessage}</p>
        {$detailsHtml}
        <a href="index.html" class="home-btn">&larr; Back to Portfolio</a>
    </div>
</body>
</html>
HTML;
}

function envValue(string $name): string
{
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
        return $value;
    }
    if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
        return $_ENV[$name];
    }
    return '';
}

function loadDotEnv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $separatorPos = strpos($trimmed, '=');
        if ($separatorPos === false) {
            continue;
        }
        $key = trim(substr($trimmed, 0, $separatorPos));
        $value = trim(substr($trimmed, $separatorPos + 1));
        if ($key === '') {
            continue;
        }
        $existing = getenv($key);
        if (is_string($existing) && $existing !== '') {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
