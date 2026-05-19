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
    displayResultPage(true, 'Appointment Approved', 'The appointment is now confirmed and shows as approved on the calendar.', $appointment);
} else {
    displayResultPage(true, 'Appointment Disapproved', 'The appointment was disapproved. That date is now free for other clients to book.', $appointment);
}

// ---------------- helpers ----------------

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
        $st = htmlspecialchars(strtoupper((string) ($appointment['status'] ?? '')), ENT_QUOTES);
        $detailsHtml = <<<DET
        <div style="background:rgba(0,255,247,0.05); border:1px solid rgba(0,255,247,0.2); border-radius:15px; padding:24px; margin:24px 0; text-align:left;">
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Project</span><span style="color:#ffffff; font-weight:600;">{$pn}</span></div>
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Type</span><span style="color:#ffffff; font-weight:600;">{$pt}</span></div>
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Facebook</span><span style="color:#ffffff; font-weight:600;">{$fb}</span></div>
            <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.1);"><span style="color:#7f8ea8; font-weight:600;">Deadline</span><span style="color:#00fff7; font-weight:700;">{$dl}</span></div>
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
