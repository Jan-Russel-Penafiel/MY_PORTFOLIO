<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$dataFile = __DIR__ . '/appointments.json';

const ALLOWED_PROJECT_TYPES = [
    'Simple Project',
    'Documentation Project (Papers only)',
    'Capstone Project (System only)',
    'Capstone Thesis Project',
    'Business Project',
];

// ---- GET: return all appointments ----
if ($method === 'GET') {
    respond(200, [
        'ok' => true,
        'appointments' => readAppointments($dataFile),
    ]);
}

if ($method !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed. Use GET or POST.']);
}

// ---- POST: create a new appointment ----
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    respond(500, [
        'ok' => false,
        'error' => 'PHPMailer not installed. Run: composer require phpmailer/phpmailer',
    ]);
}
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

loadDotEnv(__DIR__ . '/.env');

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON payload.']);
}

$projectName = sanitizeText(trim((string) ($input['projectName'] ?? '')), 120);
$projectType = trim((string) ($input['projectType'] ?? ''));
$facebookName = sanitizeText(trim((string) ($input['facebookName'] ?? '')), 80);
$deadline = trim((string) ($input['deadline'] ?? ''));
$projectDeadline = trim((string) ($input['projectDeadline'] ?? ''));

if ($projectName === '') {
    respond(422, ['ok' => false, 'error' => 'Project Name is required.']);
}
if (!in_array($projectType, ALLOWED_PROJECT_TYPES, true)) {
    respond(422, ['ok' => false, 'error' => 'Please choose a valid Type of Project.']);
}
if ($facebookName === '') {
    respond(422, ['ok' => false, 'error' => 'Facebook Name is required.']);
}
if (!isValidDate($deadline)) {
    respond(422, ['ok' => false, 'error' => 'Please choose a valid Appointment Date.']);
}
if ($deadline < date('Y-m-d')) {
    respond(422, ['ok' => false, 'error' => 'Appointment Date cannot be in the past.']);
}
if (!isValidDate($projectDeadline)) {
    respond(422, ['ok' => false, 'error' => 'Please choose a valid Deadline of the Project.']);
}
if ($projectDeadline < date('Y-m-d')) {
    respond(422, ['ok' => false, 'error' => 'Deadline of the Project cannot be in the past.']);
}

// Append under an exclusive lock so two same-date bookings cannot both win.
$fp = fopen($dataFile, 'c+');
if ($fp === false) {
    respond(500, ['ok' => false, 'error' => 'Could not open appointments storage.']);
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    respond(500, ['ok' => false, 'error' => 'Could not lock appointments storage.']);
}

$raw = stream_get_contents($fp);
$appointments = json_decode((string) $raw, true);
if (!is_array($appointments)) {
    $appointments = [];
}

foreach ($appointments as $existing) {
    $existingStatus = $existing['status'] ?? 'pending';
    if (
        ($existing['deadline'] ?? '') === $deadline
        && $existingStatus !== 'disapproved'
    ) {
        flock($fp, LOCK_UN);
        fclose($fp);
        respond(409, [
            'ok' => false,
            'error' => 'That date is already booked. Please choose another date.',
        ]);
    }
}

$appointment = [
    'id' => 'APT-' . time() . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8)),
    'projectName' => $projectName,
    'projectType' => $projectType,
    'facebookName' => $facebookName,
    'deadline' => $deadline,
    'projectDeadline' => $projectDeadline,
    'status' => 'pending',
    'bookedAt' => date('Y-m-d H:i:s'),
    'decidedAt' => null,
];
$appointments[] = $appointment;

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($appointments, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

// Email the owner. A failure here is logged but does not undo the saved booking.
$emailWarning = null;
try {
    sendOwnerEmail($appointment);
} catch (Throwable $e) {
    error_log('Appointments: owner email failed: ' . $e->getMessage());
    $emailWarning = 'Appointment saved, but the notification email could not be sent.';
}

respond(200, [
    'ok' => true,
    'message' => $emailWarning ?? 'Appointment booked successfully.',
    'appointment' => $appointment,
    'emailWarning' => $emailWarning,
]);

// ---------------- helpers ----------------

function readAppointments(string $dataFile): array
{
    if (!is_file($dataFile)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($dataFile), true);
    return is_array($decoded) ? $decoded : [];
}

function isValidDate(string $value): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

function sendOwnerEmail(array $appointment): void
{
    $recipientEmail = 'janrusselpenafiel01172005@gmail.com';
    $fromEmail = envValue('SMTP_FROM_EMAIL') ?: 'noreply@yourdomain.com';
    $fromName = envValue('SMTP_FROM_NAME') ?: 'Portfolio Appointment System';
    $smtpHost = envValue('SMTP_HOST') ?: 'smtp.gmail.com';
    $smtpPort = (int) (envValue('SMTP_PORT') ?: 587);
    $smtpUsername = envValue('SMTP_USERNAME') ?: '';
    $smtpPassword = envValue('SMTP_PASSWORD') ?: '';

    if ($smtpUsername === '' || $smtpPassword === '') {
        throw new RuntimeException('SMTP credentials not configured in .env');
    }

    $token = generateAppointmentToken($appointment['id']);
    $base = getBaseUrl();
    $approveLink = $base . '/appointment_approval.php?action=approve&id='
        . urlencode($appointment['id']) . '&token=' . $token;
    $disapproveLink = $base . '/appointment_approval.php?action=disapprove&id='
        . urlencode($appointment['id']) . '&token=' . $token;

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $smtpPort;
    $mail->SMTPDebug = 0;

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipientEmail, 'Jan Russel Penaflor');

    $mail->isHTML(true);
    $mail->Subject = '📅 Appointment Request - ' . $appointment['projectName'];
    $mail->Body = generateOwnerEmailBody($appointment, $approveLink, $disapproveLink);
    $mail->AltBody = strip_tags(str_replace('<br>', "\n", $mail->Body));

    $mail->send();
    error_log('Appointments: approval email sent for ' . $appointment['id']);
}

function generateOwnerEmailBody(array $a, string $approveLink, string $disapproveLink): string
{
    $projectName = htmlspecialchars($a['projectName'], ENT_QUOTES);
    $projectType = htmlspecialchars($a['projectType'], ENT_QUOTES);
    $facebookName = htmlspecialchars($a['facebookName'], ENT_QUOTES);
    $deadline = htmlspecialchars($a['deadline'], ENT_QUOTES);
    $projectDeadline = htmlspecialchars($a['projectDeadline'] ?? '', ENT_QUOTES);
    $bookedAt = htmlspecialchars($a['bookedAt'], ENT_QUOTES);
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, sans-serif; background:#0a0e1a; color:#e0e6ed; margin:0; padding:20px;">
    <div style="max-width:650px; margin:0 auto; background:linear-gradient(135deg,#181825 0%,#1a1c2e 100%); border:1.5px solid rgba(0,255,247,0.3); border-radius:14px; overflow:hidden;">
        <div style="background:linear-gradient(135deg,#00fff7 0%,#00d4d4 100%); color:#0a0e1a; padding:35px 30px; text-align:center;">
            <h1 style="margin:0; font-size:26px;">📅 New Appointment Request</h1>
            <p style="margin:8px 0 0 0; font-size:14px;">Review and approve or disapprove this booking</p>
        </div>
        <div style="padding:35px 30px;">
            <p style="color:#b0b8c8;">A client has requested a project appointment. It is <strong style="color:#ffb74d;">pending your decision</strong>:</p>
            <div style="padding:16px 0; border-bottom:1px solid rgba(255,255,255,0.1);">
                <span style="color:#7f8ea8; font-weight:600;">📁 Project Name:</span>
                <span style="color:#ffffff; float:right; font-weight:600;">{$projectName}</span>
            </div>
            <div style="padding:16px 0; border-bottom:1px solid rgba(255,255,255,0.1);">
                <span style="color:#7f8ea8; font-weight:600;">🏷️ Type of Project:</span>
                <span style="color:#ffffff; float:right; font-weight:600;">{$projectType}</span>
            </div>
            <div style="padding:16px 0; border-bottom:1px solid rgba(255,255,255,0.1);">
                <span style="color:#7f8ea8; font-weight:600;">👤 Facebook Name:</span>
                <span style="color:#ffffff; float:right; font-weight:600;">{$facebookName}</span>
            </div>
            <div style="padding:16px 0; border-bottom:1px solid rgba(255,255,255,0.1);">
                <span style="color:#7f8ea8; font-weight:600;">📆 Appointment Date:</span>
                <span style="color:#00fff7; float:right; font-weight:700;">{$deadline}</span>
            </div>
            <div style="padding:16px 0; border-bottom:1px solid rgba(255,255,255,0.1);">
                <span style="color:#7f8ea8; font-weight:600;">⏰ Deadline of the Project:</span>
                <span style="color:#00fff7; float:right; font-weight:700;">{$projectDeadline}</span>
            </div>
            <div style="padding:16px 0;">
                <span style="color:#7f8ea8; font-weight:600;">🕒 Requested On:</span>
                <span style="color:#ffffff; float:right; font-weight:600;">{$bookedAt}</span>
            </div>
            <div style="text-align:center; margin-top:30px;">
                <a href="{$approveLink}" style="display:inline-block; background:linear-gradient(135deg,#28a745 0%,#20c997 100%); color:#ffffff; padding:14px 32px; border-radius:10px; text-decoration:none; font-weight:700; font-size:15px; margin:6px;">✓ APPROVE</a>
                <a href="{$disapproveLink}" style="display:inline-block; background:linear-gradient(135deg,#ff4757 0%,#ff6b81 100%); color:#ffffff; padding:14px 32px; border-radius:10px; text-decoration:none; font-weight:700; font-size:15px; margin:6px;">✕ DISAPPROVE</a>
            </div>
            <p style="color:#7f8ea8; font-size:13px; margin-top:25px; text-align:center;">Approving marks the date confirmed. Disapproving frees the date for other clients.</p>
        </div>
        <div style="background:rgba(0,0,0,0.3); padding:25px 30px; text-align:center; color:#7f8ea8; font-size:12px;">
            <p style="margin:5px 0;">This is an automated notification from your Portfolio Appointment System.</p>
            <p style="margin:5px 0;">&copy; {$year} Jan Russel Penaflor. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

function sanitizeText(string $value, int $maxLength): string
{
    $clean = preg_replace('/[^a-zA-Z0-9 .,:()\-]/', '', $value);
    if ($clean === null) {
        return '';
    }
    return substr(trim($clean), 0, $maxLength);
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

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function generateAppointmentToken(string $appointmentId): string
{
    $secret = envValue('APPROVAL_SECRET') ?: 'default-secret-change-me';
    return hash_hmac('sha256', $appointmentId, $secret);
}

function getBaseUrl(): string
{
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = str_replace('\\', '/', $basePath);
    $basePath = rtrim($basePath, '/');
    return $scheme . '://' . $host . $basePath;
}
