# Tap-to-Schedule & Appointment Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Revise the Calendar View feature so clients pick a date by tapping a calendar tile (date field becomes read-only), every booking starts as `pending` and is approved/disapproved by the owner via signed email links, and the calendar shows three status colors.

**Architecture:** `appointments.php` `POST` assigns each appointment an `id` + `status:"pending"`, treats only `pending`/`approved` dates as booked, and emails the owner Approve/Disapprove links. A new `appointment_approval.php` (modeled on `gcash_approval.php`) verifies an HMAC token and flips the appointment's status in `appointments.json` under a file lock. `index.html` gains a read-only date display fed by tapping calendar tiles, plus status-aware tile rendering.

**Tech Stack:** Static HTML + Bootstrap 5, vanilla JavaScript (`fetch`), PHP 8 with PHPMailer (in `vendor/`), JSON flat-file storage, HMAC-SHA256 tokens keyed by `APPROVAL_SECRET` from `.env`.

**Testing note:** This repo has no automated test runner. Tasks are verified with `php -l`, `curl`, and browser checks, matching the existing project workflow. Verification commands assume the app is served at `http://localhost/doc/`.

---

## File Structure

- **Modify: `appointments.php`** — `POST` adds `id` + `status:"pending"`; the
  "already booked" check ignores `disapproved`; owner email becomes an approval
  email with two links. New helpers: `generateAppointmentToken()`,
  `getBaseUrl()`.
- **Create: `appointment_approval.php`** — handles `approve`/`disapprove` link
  clicks: verifies token, updates `appointments.json`, renders result pages.
- **Modify: `index.html`** — form date `<input>` → read-only display + hidden
  input; CSS for `selectable`/`selected`/`pending`/`approved` tiles; JS for
  tap-to-select and status-aware rendering.
- **`appointments.json`** — runtime file; records gain `id`, `status`,
  `decidedAt`. Not committed, not hand-edited.

---

### Task 1: Update `appointments.php` POST to add id, status, and skip disapproved dates

**Files:**
- Modify: `appointments.php` (the booked-date check and the `$appointment` array, lines ~88-106)

- [ ] **Step 1: Make the booked-date check ignore disapproved appointments**

In `appointments.php`, find this block:

```php
foreach ($appointments as $existing) {
    if (($existing['deadline'] ?? '') === $deadline) {
        flock($fp, LOCK_UN);
        fclose($fp);
        respond(409, [
            'ok' => false,
            'error' => 'That date is already booked. Please choose another date.',
        ]);
    }
}
```

Replace it with (a date is only taken if a pending/approved appointment holds it):

```php
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
```

- [ ] **Step 2: Add id, status, and decidedAt to the new appointment**

In `appointments.php`, find:

```php
$appointment = [
    'projectName' => $projectName,
    'projectType' => $projectType,
    'facebookName' => $facebookName,
    'deadline' => $deadline,
    'bookedAt' => date('Y-m-d H:i:s'),
];
$appointments[] = $appointment;
```

Replace it with:

```php
$appointment = [
    'id' => 'APT-' . time() . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8)),
    'projectName' => $projectName,
    'projectType' => $projectType,
    'facebookName' => $facebookName,
    'deadline' => $deadline,
    'status' => 'pending',
    'bookedAt' => date('Y-m-d H:i:s'),
    'decidedAt' => null,
];
$appointments[] = $appointment;
```

- [ ] **Step 3: Lint the file**

Run: `php -l appointments.php`
Expected: `No syntax errors detected in appointments.php`

- [ ] **Step 4: Verify a booking now stores id and status**

Make sure `appointments.json` does not exist (`del appointments.json` if it does). With XAMPP Apache running:

Run: `curl -s -X POST http://localhost/doc/appointments.php -H "Content-Type: application/json" -d "{\"projectName\":\"Test One\",\"projectType\":\"Simple Project\",\"facebookName\":\"FB Name\",\"deadline\":\"2026-12-20\"}"`
Expected: JSON with `"ok":true` and an `appointment` object containing `"id":"APT-..."` and `"status":"pending"`.

- [ ] **Step 5: Reset the test data file**

Run: `del appointments.json`
(Removes the test record so later tasks start clean.)

- [ ] **Step 6: Commit**

```bash
git add appointments.php
git commit -m "Add id and pending status to new appointments"
```

---

### Task 2: Replace the owner email in `appointments.php` with an approval email

**Files:**
- Modify: `appointments.php` — the `sendOwnerEmail()` and `generateOwnerEmailBody()` functions, and add two helper functions.

- [ ] **Step 1: Rewrite `sendOwnerEmail()` to build approval links**

In `appointments.php`, find the entire `sendOwnerEmail` function (from `function sendOwnerEmail(array $appointment): void` to its closing `}`) and replace the whole function with:

```php
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
```

- [ ] **Step 2: Rewrite `generateOwnerEmailBody()` to include the two buttons**

In `appointments.php`, find the entire `generateOwnerEmailBody` function (from `function generateOwnerEmailBody(array $a): string` to its closing `}` and the `HTML;` heredoc terminator) and replace the whole function with:

```php
function generateOwnerEmailBody(array $a, string $approveLink, string $disapproveLink): string
{
    $projectName = htmlspecialchars($a['projectName'], ENT_QUOTES);
    $projectType = htmlspecialchars($a['projectType'], ENT_QUOTES);
    $facebookName = htmlspecialchars($a['facebookName'], ENT_QUOTES);
    $deadline = htmlspecialchars($a['deadline'], ENT_QUOTES);
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
                <span style="color:#7f8ea8; font-weight:600;">⏰ Deadline:</span>
                <span style="color:#00fff7; float:right; font-weight:700;">{$deadline}</span>
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
```

- [ ] **Step 3: Add the `generateAppointmentToken()` and `getBaseUrl()` helpers**

In `appointments.php`, find the `respond()` function near the end of the file:

```php
function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
```

Immediately AFTER that function's closing `}`, add:

```php

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
```

- [ ] **Step 4: Lint the file**

Run: `php -l appointments.php`
Expected: `No syntax errors detected in appointments.php`

- [ ] **Step 5: Verify a booking still succeeds and triggers the email path**

Make sure `appointments.json` does not exist. With XAMPP running:

Run: `curl -s -X POST http://localhost/doc/appointments.php -H "Content-Type: application/json" -d "{\"projectName\":\"Email Test\",\"projectType\":\"Capstone Project\",\"facebookName\":\"FB Name\",\"deadline\":\"2026-12-21\"}"`
Expected: JSON with `"ok":true`. If SMTP is configured, `"emailWarning":null` and the owner's inbox receives an email titled "📅 Appointment Request - Email Test" containing green APPROVE and red DISAPPROVE buttons. If SMTP is not configured, `emailWarning` is a non-null string and the booking still succeeds.

- [ ] **Step 6: Reset the test data file**

Run: `del appointments.json`

- [ ] **Step 7: Commit**

```bash
git add appointments.php
git commit -m "Replace owner notification with approve/disapprove email"
```

---

### Task 3: Create `appointment_approval.php`

**Files:**
- Create: `appointment_approval.php`

- [ ] **Step 1: Write the full `appointment_approval.php` file**

Create `appointment_approval.php` with this exact content:

```php
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
```

- [ ] **Step 2: Lint the file**

Run: `php -l appointment_approval.php`
Expected: `No syntax errors detected in appointment_approval.php`

- [ ] **Step 3: Verify a bad link shows an error page**

Run: `curl -s "http://localhost/doc/appointment_approval.php?action=approve&id=APT-fake&token=wrong"`
Expected: HTML containing `Invalid Link` (token verification fails).

- [ ] **Step 4: Commit**

```bash
git add appointment_approval.php
git commit -m "Add appointment_approval.php for approve/disapprove links"
```

---

### Task 4: End-to-end backend verification of the approval flow

**Files:** none (verification only)

- [ ] **Step 1: Ensure a clean data file**

Run: `del appointments.json`
(Ignore "file not found" if it is already absent.)

- [ ] **Step 2: Book an appointment and capture its id**

Run: `curl -s -X POST http://localhost/doc/appointments.php -H "Content-Type: application/json" -d "{\"projectName\":\"Flow Test\",\"projectType\":\"Business Project\",\"facebookName\":\"FB Name\",\"deadline\":\"2026-12-22\"}"`
Expected: `"ok":true`. Note the `"id"` value from the response (referred to below as `APT-XXX`).

- [ ] **Step 3: Confirm the same date is blocked while pending**

Run: `curl -s -X POST http://localhost/doc/appointments.php -H "Content-Type: application/json" -d "{\"projectName\":\"Dup\",\"projectType\":\"Simple Project\",\"facebookName\":\"FB Name\",\"deadline\":\"2026-12-22\"}"`
Expected: JSON with `"ok":false` and `"That date is already booked. Please choose another date."`

- [ ] **Step 4: Get the valid token for the appointment**

Run (replace `APT-XXX` with the real id; this prints the token using the same secret logic):
`php -r "require 'appointment_approval.php' === false ?: null;" 2>NUL & php -r "$s=getenv('APPROVAL_SECRET');" 2>NUL`

If that is awkward, instead read the token directly from the approval email that was sent in Step 2 (the APPROVE link contains `&token=...`). Use the `approve` link from the email for Step 5 and construct the `disapprove` link by changing `action=approve` to `action=disapprove`.

- [ ] **Step 5: Approve the appointment via its link**

Open the APPROVE link from the email in a browser (or `curl -s "<approve link>"`).
Expected: an "Appointment Approved" page.
Then run: `curl -s http://localhost/doc/appointments.php`
Expected: the appointment now has `"status":"approved"`.

- [ ] **Step 6: Confirm the approval link is idempotent**

Open the same APPROVE link again.
Expected: an "Already Decided" page; `curl -s http://localhost/doc/appointments.php` still shows `"status":"approved"` (unchanged).

- [ ] **Step 7: Verify disapprove frees the date**

Book a second appointment on a new date:
`curl -s -X POST http://localhost/doc/appointments.php -H "Content-Type: application/json" -d "{\"projectName\":\"Free Test\",\"projectType\":\"Simple Project\",\"facebookName\":\"FB Name\",\"deadline\":\"2026-12-23\"}"`
Open that appointment's DISAPPROVE link (from its email, or change `action` to `disapprove`).
Expected: an "Appointment Disapproved" page.
Then re-book the same date:
`curl -s -X POST http://localhost/doc/appointments.php -H "Content-Type: application/json" -d "{\"projectName\":\"Rebook\",\"projectType\":\"Simple Project\",\"facebookName\":\"FB Name\",\"deadline\":\"2026-12-23\"}"`
Expected: `"ok":true` — the disapproved date is bookable again.

- [ ] **Step 8: Reset the data file**

Run: `del appointments.json`

- [ ] **Step 9: No commit**

This task changes no files. Nothing to commit.

---

### Task 5: Replace the form date input with a read-only display

**Files:**
- Modify: `index.html` — the Deadline field in the `#appointmentForm` (the `<label>`/`<input>` for `appointmentDeadline`, around lines 7221-7223)

- [ ] **Step 1: Replace the date input with a read-only display + hidden input**

In `index.html`, find:

```html
              <div>
                <label class="form-label" for="appointmentDeadline">Deadline of the Project</label>
                <input id="appointmentDeadline" name="deadline" class="form-control" type="date" required />
              </div>
```

Replace it with:

```html
              <div>
                <label class="form-label">Appointment Date</label>
                <div id="appointmentDateDisplay" class="form-control appointment-date-display">
                  Tap a date on the calendar
                </div>
                <input id="appointmentDeadline" name="deadline" type="hidden" />
              </div>
```

- [ ] **Step 2: Verify the form renders**

Open `http://localhost/doc/index.html`, scroll to the Calendar View. Confirm the booking form now shows an "Appointment Date" field displaying the text "Tap a date on the calendar" instead of a date picker. Selecting it does nothing yet (wiring is Task 7).

- [ ] **Step 3: Commit**

```bash
git add index.html
git commit -m "Replace appointment date input with read-only display"
```

---

### Task 6: Add CSS for selectable, selected, and status tiles

**Files:**
- Modify: `index.html` — insert into the appointments CSS block, immediately after the `.calendar-day.booked:hover` rule (around line 5921, just before `.appointment-detail`)

- [ ] **Step 1: Add the new tile-state CSS**

In `index.html`, find this rule inside the appointments CSS block:

```css
      .calendar-day.booked:hover {
        background: rgba(0, 255, 247, 0.3);
      }
```

Immediately AFTER its closing `}`, insert:

```css
      .calendar-day.selectable {
        cursor: pointer;
      }
      .calendar-day.selectable:hover {
        border-color: rgba(0, 255, 247, 0.6);
        background: rgba(0, 255, 247, 0.07);
      }
      .calendar-day.selected {
        outline: 2px solid #00fff7;
        outline-offset: -2px;
        color: #ffffff;
        font-weight: 700;
      }
      .calendar-day.pending {
        background: rgba(255, 167, 38, 0.18);
        border-color: #ffa726;
        color: #ffffff;
        font-weight: 700;
        cursor: pointer;
      }
      .calendar-day.pending::after {
        content: '';
        position: absolute;
        bottom: 5px;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #ffa726;
      }
      .calendar-day.pending:hover {
        background: rgba(255, 167, 38, 0.32);
      }
      .calendar-day.approved {
        background: rgba(0, 255, 247, 0.16);
        border-color: #00fff7;
        color: #ffffff;
        font-weight: 700;
        cursor: pointer;
      }
      .calendar-day.approved::after {
        content: '';
        position: absolute;
        bottom: 5px;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #00fff7;
      }
      .calendar-day.approved:hover {
        background: rgba(0, 255, 247, 0.3);
      }
      .appointment-date-display {
        display: flex;
        align-items: center;
        min-height: 38px;
        color: #00fff7;
        font-weight: 600;
      }
      .appointment-date-display.is-empty {
        color: #7f8ea8;
        font-weight: 400;
      }
```

- [ ] **Step 2: Add light-mode variants for the new states**

In `index.html`, find this light-mode rule inside the appointments CSS block:

```css
      body.light-mode .calendar-day.booked::after {
        background: #1c1e21 !important;
      }
```

Immediately AFTER its closing `}`, insert:

```css
      body.light-mode .calendar-day.selectable:hover {
        border-color: rgba(28, 30, 33, 0.5) !important;
        background: rgba(28, 30, 33, 0.06) !important;
      }
      body.light-mode .calendar-day.selected {
        outline-color: #1c1e21 !important;
        color: #1c1e21 !important;
      }
      body.light-mode .calendar-day.pending {
        background: rgba(230, 126, 0, 0.16) !important;
        border-color: #e67e00 !important;
        color: #1c1e21 !important;
      }
      body.light-mode .calendar-day.pending::after {
        background: #e67e00 !important;
      }
      body.light-mode .calendar-day.approved {
        background: rgba(28, 30, 33, 0.1) !important;
        border-color: #1c1e21 !important;
        color: #1c1e21 !important;
      }
      body.light-mode .calendar-day.approved::after {
        background: #1c1e21 !important;
      }
      body.light-mode .appointment-date-display {
        color: #1565c0 !important;
      }
      body.light-mode .appointment-date-display.is-empty {
        color: #7f8ea8 !important;
      }
```

- [ ] **Step 3: Verify the page still loads cleanly**

Open `http://localhost/doc/index.html`. The page should render normally with no layout breakage. New tile states are not visible yet (Task 7 wires the JS).

- [ ] **Step 4: Commit**

```bash
git add index.html
git commit -m "Add CSS for selectable, selected, pending, and approved tiles"
```

---

### Task 7: Rewrite the calendar JavaScript for tap-to-select and status rendering

**Files:**
- Modify: `index.html` — the entire appointments `<script>` block (the one introduced by the comment `<!-- Appointments / Calendar View logic -->`, near the end of the file before `</html>`)

- [ ] **Step 1: Replace the appointments script block**

In `index.html`, find the block that starts with:

```html
  <!-- Appointments / Calendar View logic -->
  <script>
    (function () {
```

and ends with the matching:

```html
      loadAppointments();
    })();
  </script>
```

Replace the ENTIRE block (from `<!-- Appointments / Calendar View logic -->` through the closing `</script>`) with:

```html
  <!-- Appointments / Calendar View logic -->
  <script>
    (function () {
      var calendarDays = document.getElementById("calendarDays");
      var calendarTitle = document.getElementById("calendarTitle");
      var prevBtn = document.getElementById("calendarPrevBtn");
      var nextBtn = document.getElementById("calendarNextBtn");
      var detail = document.getElementById("appointmentDetail");
      var form = document.getElementById("appointmentForm");
      var statusEl = document.getElementById("appointmentStatus");
      var deadlineInput = document.getElementById("appointmentDeadline");
      var dateDisplay = document.getElementById("appointmentDateDisplay");
      if (!calendarDays || !form) {
        return;
      }

      var MONTHS = [
        "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December",
      ];
      var viewDate = new Date();
      viewDate.setDate(1);
      // Map of "YYYY-MM-DD" -> appointment object (pending or approved only).
      var bookedByDate = {};
      // The date string the user tapped, or "" if none.
      var selectedDate = "";

      function pad(n) {
        return n < 10 ? "0" + n : "" + n;
      }

      function dateKey(year, month, day) {
        return year + "-" + pad(month + 1) + "-" + pad(day);
      }

      function todayKey() {
        var t = new Date();
        return dateKey(t.getFullYear(), t.getMonth(), t.getDate());
      }

      function setStatus(message, kind) {
        statusEl.textContent = message;
        statusEl.className = "client-payment-status is-visible is-" + kind;
      }

      function clearStatus() {
        statusEl.textContent = "";
        statusEl.className = "client-payment-status";
      }

      function escapeHtml(value) {
        var div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
      }

      function showDetail(key) {
        var a = bookedByDate[key];
        if (!a) {
          detail.innerHTML =
            '<span class="detail-empty">Click a highlighted date to see the appointment.</span>';
          return;
        }
        var statusLabel = a.status === "approved" ? "Approved" : "Pending approval";
        detail.innerHTML =
          "<div><strong>" + escapeHtml(a.projectName) + "</strong></div>" +
          "<div>Type: " + escapeHtml(a.projectType) + "</div>" +
          "<div>Facebook: " + escapeHtml(a.facebookName) + "</div>" +
          "<div>Deadline: <strong>" + escapeHtml(a.deadline) + "</strong></div>" +
          "<div>Status: <strong>" + statusLabel + "</strong></div>";
      }

      function setSelectedDate(key) {
        selectedDate = key;
        deadlineInput.value = key;
        if (key) {
          dateDisplay.textContent = key;
          dateDisplay.classList.remove("is-empty");
        } else {
          dateDisplay.textContent = "Tap a date on the calendar";
          dateDisplay.classList.add("is-empty");
        }
        renderCalendar();
      }

      function renderCalendar() {
        var year = viewDate.getFullYear();
        var month = viewDate.getMonth();
        calendarTitle.textContent = MONTHS[month] + " " + year;
        calendarDays.innerHTML = "";

        var firstWeekday = new Date(year, month, 1).getDay();
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var tKey = todayKey();

        for (var i = 0; i < firstWeekday; i++) {
          var blank = document.createElement("div");
          blank.className = "calendar-day empty";
          calendarDays.appendChild(blank);
        }

        for (var day = 1; day <= daysInMonth; day++) {
          var cell = document.createElement("div");
          cell.className = "calendar-day";
          cell.textContent = day;
          var key = dateKey(year, month, day);

          if (key === tKey) {
            cell.classList.add("today");
          }

          var booked = bookedByDate[key];
          if (booked) {
            // Pending or approved appointment occupies this date.
            cell.classList.add(booked.status === "approved" ? "approved" : "pending");
            cell.title = booked.projectName;
            (function (k) {
              cell.addEventListener("click", function () {
                showDetail(k);
              });
            })(key);
          } else if (key >= tKey) {
            // Free, today or future: selectable.
            cell.classList.add("selectable");
            (function (k) {
              cell.addEventListener("click", function () {
                setSelectedDate(k);
                clearStatus();
              });
            })(key);
          }

          if (key === selectedDate) {
            cell.classList.add("selected");
          }

          calendarDays.appendChild(cell);
        }
      }

      function loadAppointments() {
        return fetch("appointments.php")
          .then(function (res) {
            return res.json();
          })
          .then(function (data) {
            bookedByDate = {};
            if (data && data.ok && Array.isArray(data.appointments)) {
              data.appointments.forEach(function (a) {
                if (!a || !a.deadline) {
                  return;
                }
                var status = a.status || "pending";
                // Disapproved appointments do not occupy the date.
                if (status === "pending" || status === "approved") {
                  bookedByDate[a.deadline] = a;
                }
              });
            }
            // If the previously selected date got booked, clear the selection.
            if (selectedDate && bookedByDate[selectedDate]) {
              setSelectedDate("");
            } else {
              renderCalendar();
            }
          })
          .catch(function () {
            renderCalendar();
          });
      }

      prevBtn.addEventListener("click", function () {
        viewDate.setMonth(viewDate.getMonth() - 1);
        renderCalendar();
      });
      nextBtn.addEventListener("click", function () {
        viewDate.setMonth(viewDate.getMonth() + 1);
        renderCalendar();
      });

      form.addEventListener("submit", function (event) {
        event.preventDefault();
        clearStatus();

        if (!selectedDate) {
          setStatus("Please tap a date on the calendar first.", "error");
          return;
        }
        if (!form.checkValidity()) {
          form.reportValidity();
          return;
        }
        if (bookedByDate[selectedDate]) {
          setStatus(
            "That date is already booked. Please choose another date.",
            "error"
          );
          return;
        }

        var payload = {
          projectName: document
            .getElementById("appointmentProjectName")
            .value.trim(),
          projectType: document.getElementById("appointmentProjectType").value,
          facebookName: document
            .getElementById("appointmentFacebookName")
            .value.trim(),
          deadline: selectedDate,
        };

        var submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        setStatus("Submitting your appointment request...", "info");

        fetch("appointments.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        })
          .then(function (res) {
            return res.json().then(function (data) {
              return { status: res.status, data: data };
            });
          })
          .then(function (result) {
            if (result.data && result.data.ok) {
              setStatus(
                "Appointment requested. It is pending approval — the date now shows as pending on the calendar.",
                result.data.emailWarning ? "info" : "success"
              );
              form.reset();
              setSelectedDate("");
              return loadAppointments();
            }
            setStatus(
              (result.data && result.data.error) ||
                "Could not submit the appointment. Please try again.",
              "error"
            );
          })
          .catch(function () {
            setStatus(
              "Network error. Please check your connection and try again.",
              "error"
            );
          })
          .then(function () {
            submitBtn.disabled = false;
          });
      });

      // Start with an empty selection so the display shows its placeholder.
      setSelectedDate("");
      loadAppointments();
    })();
  </script>
```

- [ ] **Step 2: Verify the JavaScript parses**

Run this Node command:

```bash
node -e "var fs=require('fs');var h=fs.readFileSync('index.html','utf8');var m='<!-- Appointments / Calendar View logic -->';var i=h.indexOf(m);var s=h.indexOf('<script>',i)+8;var e=h.indexOf('</script>',s);try{new Function(h.slice(s,e));console.log('PASS: appointments JS parses');}catch(err){console.error('FAIL: '+err.message);process.exit(1);}"
```

Expected: `PASS: appointments JS parses`

- [ ] **Step 3: Verify tap-to-select in the browser**

Ensure `appointments.json` does not exist (`del appointments.json`). Open `http://localhost/doc/index.html`, go to the Calendar View. Confirm:
- Hovering a future date shows a hover highlight (selectable).
- Clicking a future date puts a cyan ring on it and the "Appointment Date" field shows that date.
- Clicking a different future date moves the ring and updates the field.
- Past dates show no hover highlight and cannot be selected.

- [ ] **Step 4: Verify booking creates a pending (orange) tile**

In the form, fill Project Name, choose a Type, fill Facebook Name, tap a future date, click "Book Appointment". Confirm:
- Status shows the "pending approval" message.
- The tapped date turns into an orange (pending) tile.
- Clicking that orange tile shows the appointment detail with "Status: Pending approval".

- [ ] **Step 5: Verify approve turns the tile cyan**

Open the APPROVE link from the email that was sent in Step 4 (check the inbox). Reload `http://localhost/doc/index.html` and confirm the same date is now a cyan (approved) tile, and its detail panel shows "Status: Approved".

- [ ] **Step 6: Reset the data file**

Run: `del appointments.json`

- [ ] **Step 7: Commit**

```bash
git add index.html
git commit -m "Rewrite calendar JS for tap-to-select and status rendering"
```

---

### Task 8: Final verification pass

**Files:** none (verification only)

- [ ] **Step 1: Light/dark mode check**

Ensure at least one pending and (after approving via email) one approved appointment exist. Open the Calendar View and toggle the theme. Confirm in BOTH modes: selectable hover, the selected ring, the orange pending tile, and the cyan approved tile all render correctly, and the "Appointment Date" display is readable.

- [ ] **Step 2: Mobile layout check**

Narrow the browser to under ~600px. Confirm the calendar and booking form stack vertically, calendar tiles remain tappable, and the read-only date display is visible.

- [ ] **Step 3: Submit with no date selected**

With the form fields filled but no calendar tile tapped, click "Book Appointment". Confirm the status shows "Please tap a date on the calendar first." and no network request is made.

- [ ] **Step 4: Disapprove frees the date in the UI**

With a pending appointment showing orange, open its DISAPPROVE link. Reload the page and confirm that date is no longer orange — it renders as a normal selectable tile and can be tapped/booked again.

- [ ] **Step 5: Reset the data file**

Run: `del appointments.json`

- [ ] **Step 6: Final commit (only if a fix was needed)**

If steps above required no code changes, nothing to commit. If a fix was made:

```bash
git add index.html
git commit -m "Fix appointment approval issues found in final verification"
```

---

## Notes for the Implementer

- **`appointments.json` is a runtime file.** It is created by the first booking,
  must not be committed, and must not be hand-edited. Tasks delete it between
  verifications so each starts clean.
- **SMTP / `.env`:** The owner approval email needs SMTP credentials in `.env`
  (the same ones `gcash_notification.php` uses). If `.env` is missing or SMTP is
  unconfigured, bookings still succeed — the response carries a non-null
  `emailWarning` and the UI shows an info-style status. Approval links still
  work; you would just open them manually rather than from an email.
- **`APPROVAL_SECRET`:** Approval link tokens are HMAC-SHA256 over the
  appointment `id`, keyed by `APPROVAL_SECRET` from `.env` — the same secret
  `gcash_approval.php` uses. `generateAppointmentToken()` is defined identically
  in both `appointments.php` and `appointment_approval.php`; keep them in sync.
- **Status model:** A date is "booked" (blocks new bookings, rejected by the
  server, shown colored on the calendar) only when a `pending` or `approved`
  appointment holds it. A `disapproved` appointment is kept in
  `appointments.json` for history but frees its date.
- **Project root path:** Verification `curl`/URL commands assume the app is at
  `http://localhost/doc/`. Adjust if XAMPP serves it elsewhere.
- All HTML/CSS/JS for this feature live inline in `index.html`, consistent with
  the existing Payment and Calendar sections.
