# Calendar View & Appointment Booking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Calendar View section to `index.html` where the owner sees all client project appointments and clients can book one (Project Name, Type of Project, Facebook Name, Deadline), backed by a PHP endpoint that stores bookings and emails the owner.

**Architecture:** A new `<section id="appointments">` in `index.html` renders a JS-built monthly calendar plus a booking form, reusing the existing `client-payment-card` styling. A new `appointments.php` (modeled on `gcash_notification.php`) serves bookings via `GET` and accepts new ones via `POST`, persisting to `appointments.json` with an exclusive file lock and enforcing one appointment per date. New bookings email the owner via PHPMailer.

**Tech Stack:** Static HTML + Bootstrap 5, vanilla JavaScript (`fetch`), PHP 8 with PHPMailer (already installed in `vendor/`), JSON flat-file storage.

**Testing note:** This repo has no automated test runner (no PHPUnit, no JS test framework). Tasks are verified manually with `curl` (PHP endpoint) and browser checks, matching the project's existing workflow.

---

## File Structure

- **Create: `appointments.php`** — backend endpoint. `GET` returns all appointments; `POST` validates, enforces one-per-date, saves to `appointments.json`, emails the owner.
- **Create: `appointments.json`** — created at runtime by the first booking; not committed (empty data file). No task creates it manually.
- **Modify: `index.html`** — add navbar link, the `#appointments` section markup, its CSS, and its JavaScript.

`index.html` is a single large file by existing project convention; all HTML/CSS/JS for this feature goes inline into it, consistent with how the Payment section is built.

---

### Task 1: Create the `appointments.php` backend endpoint

**Files:**
- Create: `appointments.php`

- [ ] **Step 1: Write the full `appointments.php` file**

Create `appointments.php` with this exact content:

```php
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
    'Documentation Project',
    'Capstone Project',
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
    respond(422, ['ok' => false, 'error' => 'Please choose a valid Deadline date.']);
}
if ($deadline < date('Y-m-d')) {
    respond(422, ['ok' => false, 'error' => 'Deadline cannot be in the past.']);
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
    if (($existing['deadline'] ?? '') === $deadline) {
        flock($fp, LOCK_UN);
        fclose($fp);
        respond(409, [
            'ok' => false,
            'error' => 'That date is already booked. Please choose another date.',
        ]);
    }
}

$appointment = [
    'projectName' => $projectName,
    'projectType' => $projectType,
    'facebookName' => $facebookName,
    'deadline' => $deadline,
    'bookedAt' => date('Y-m-d H:i:s'),
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
    $mail->Subject = '📅 New Appointment - ' . $appointment['projectName'];
    $mail->Body = generateOwnerEmailBody($appointment);
    $mail->AltBody = strip_tags(str_replace('<br>', "\n", $mail->Body));

    $mail->send();
    error_log('Appointments: owner email sent for project ' . $appointment['projectName']);
}

function generateOwnerEmailBody(array $a): string
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
            <h1 style="margin:0; font-size:26px;">📅 New Appointment Booked</h1>
            <p style="margin:8px 0 0 0; font-size:14px;">A client has booked a project appointment</p>
        </div>
        <div style="padding:35px 30px;">
            <p style="color:#b0b8c8;">A new appointment has been booked through your portfolio website:</p>
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
                <span style="color:#7f8ea8; font-weight:600;">🕒 Booked On:</span>
                <span style="color:#ffffff; float:right; font-weight:600;">{$bookedAt}</span>
            </div>
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
```

- [ ] **Step 2: Lint the PHP file for syntax errors**

Run: `php -l appointments.php`
Expected: `No syntax errors detected in appointments.php`

- [ ] **Step 3: Verify GET works with no data file**

Make sure `appointments.json` does NOT exist yet (`del appointments.json` if it does), then with XAMPP Apache running:

Run: `curl -s http://localhost/doc/appointments.php`
Expected: `{"ok":true,"appointments":[]}`

- [ ] **Step 4: Verify POST validation rejects bad input**

Run: `curl -s -X POST http://localhost/doc/appointments.php -H "Content-Type: application/json" -d "{\"projectName\":\"\",\"projectType\":\"Capstone Project\",\"facebookName\":\"Test\",\"deadline\":\"2026-12-01\"}"`
Expected: JSON containing `"ok":false` and `"Project Name is required."`

- [ ] **Step 5: Verify POST rejects an invalid project type**

Run: `curl -s -X POST http://localhost/doc/appointments.php -H "Content-Type: application/json" -d "{\"projectName\":\"X\",\"projectType\":\"Bad Type\",\"facebookName\":\"Test\",\"deadline\":\"2026-12-01\"}"`
Expected: JSON containing `"ok":false` and `"Please choose a valid Type of Project."`

- [ ] **Step 6: Commit**

```bash
git add appointments.php
git commit -m "Add appointments.php endpoint for booking storage and notification"
```

---

### Task 2: Add the navbar link

**Files:**
- Modify: `index.html` (the `#navbarNav` list, around line 6109)

- [ ] **Step 1: Add the Appointments nav link**

Find this line in `index.html` (inside `<ul class="navbar-nav mx-auto align-items-center">`):

```html
            <li class="nav-item"><a class="nav-link text-lg-start" href="#payment">Payment</a></li>
            <li class="nav-item"><a class="nav-link text-lg-start" href="#gallery">Gallery</a></li>
```

Replace it with (adds the Appointments link between Payment and Gallery):

```html
            <li class="nav-item"><a class="nav-link text-lg-start" href="#payment">Payment</a></li>
            <li class="nav-item"><a class="nav-link text-lg-start" href="#appointments">Appointments</a></li>
            <li class="nav-item"><a class="nav-link text-lg-start" href="#gallery">Gallery</a></li>
```

- [ ] **Step 2: Verify in the browser**

Open `http://localhost/doc/index.html`. Confirm an "Appointments" link now appears in the navbar between "Payment" and "Gallery". Clicking it will not jump anywhere yet (the section is added in Task 3) — that is expected.

- [ ] **Step 3: Commit**

```bash
git add index.html
git commit -m "Add Appointments link to navbar"
```

---

### Task 3: Add the `#appointments` section CSS

**Files:**
- Modify: `index.html` (insert CSS immediately after the `body.light-mode .client-payment-status.is-error` rule, which ends at line 5793 — just before the closing `}` is followed by other rules; insert after that rule's closing brace)

- [ ] **Step 1: Insert the calendar CSS**

In `index.html`, find the end of the light-mode payment block:

```css
      body.light-mode .client-payment-status.is-error {
        color: #b71c1c !important;
      }
```

Immediately after that closing `}`, insert the following CSS block:

```css

      /* ===== Appointments / Calendar View ===== */
      .appointment-grid {
        display: grid;
        grid-template-columns: 1.1fr 1fr;
        gap: 20px;
        align-items: start;
      }
      .calendar-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
      }
      .calendar-title {
        font-size: 1rem;
        font-weight: 700;
        color: #ffffff;
      }
      .calendar-nav-btn {
        background: #10131f;
        border: 1px solid rgba(0, 255, 247, 0.3);
        color: #00fff7;
        width: 34px;
        height: 34px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.9rem;
      }
      .calendar-nav-btn:hover {
        background: rgba(0, 255, 247, 0.15);
      }
      .calendar-weekdays,
      .calendar-days {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 4px;
      }
      .calendar-weekday {
        text-align: center;
        font-size: 0.7rem;
        font-weight: 700;
        color: #7f8ea8;
        text-transform: uppercase;
        padding: 4px 0;
      }
      .calendar-day {
        aspect-ratio: 1 / 1;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.82rem;
        color: #b0b8c8;
        border-radius: 8px;
        border: 1px solid transparent;
        position: relative;
      }
      .calendar-day.empty {
        border: none;
      }
      .calendar-day.today {
        border-color: rgba(0, 255, 247, 0.5);
        color: #ffffff;
        font-weight: 700;
      }
      .calendar-day.booked {
        background: rgba(0, 255, 247, 0.16);
        border-color: #00fff7;
        color: #ffffff;
        font-weight: 700;
        cursor: pointer;
      }
      .calendar-day.booked::after {
        content: '';
        position: absolute;
        bottom: 5px;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #00fff7;
      }
      .calendar-day.booked:hover {
        background: rgba(0, 255, 247, 0.3);
      }
      .appointment-detail {
        margin-top: 14px;
        background: #10131f;
        border: 1px solid rgba(0, 255, 247, 0.3);
        border-radius: 10px;
        padding: 12px 14px;
        font-size: 0.84rem;
        color: #b0b8c8;
      }
      .appointment-detail strong {
        color: #ffffff;
      }
      .appointment-detail .detail-empty {
        color: #7f8ea8;
      }
      @media (max-width: 991px) {
        .appointment-grid {
          grid-template-columns: 1fr;
        }
      }
      body.light-mode #appointments {
        background: #f0f2f5 !important;
      }
      body.light-mode .calendar-title,
      body.light-mode .calendar-day.today,
      body.light-mode .calendar-day.booked,
      body.light-mode .appointment-detail strong {
        color: #1c1e21 !important;
      }
      body.light-mode .calendar-nav-btn {
        background: #ffffff !important;
        border-color: #dddfe2 !important;
        color: #1c1e21 !important;
      }
      body.light-mode .calendar-day {
        color: #444 !important;
      }
      body.light-mode .calendar-day.booked {
        background: rgba(28, 30, 33, 0.1) !important;
        border-color: #1c1e21 !important;
      }
      body.light-mode .calendar-day.booked::after {
        background: #1c1e21 !important;
      }
      body.light-mode .calendar-day.today {
        border-color: rgba(28, 30, 33, 0.5) !important;
      }
      body.light-mode .appointment-detail {
        background: #ffffff !important;
        border-color: #dddfe2 !important;
        color: #444 !important;
      }
```

- [ ] **Step 2: Verify the file still loads**

Open `http://localhost/doc/index.html`. The page should render exactly as before (no section added yet — that is Task 4). Confirm there are no visual breakages in the existing layout.

- [ ] **Step 3: Commit**

```bash
git add index.html
git commit -m "Add CSS for Calendar View / appointments section"
```

---

### Task 4: Add the `#appointments` section markup

**Files:**
- Modify: `index.html` (insert between the end of `<section id="payment">` at line 7025 and the `<!-- Photo Gallery Section -->` comment at line 7027)

- [ ] **Step 1: Insert the appointments section HTML**

In `index.html`, find:

```html
    </section>

    <!-- Photo Gallery Section -->
    <section id="gallery">
```

The `</section>` shown there closes the Payment section. Insert the new section between that `</section>` and the `<!-- Photo Gallery Section -->` comment, so it reads:

```html
    </section>

    <!-- Appointments / Calendar View Section -->
    <section id="appointments" class="py-5">
      <div class="container">
        <div class="text-center mb-5">
          <h2 class="roadmap-section-title">CALENDAR VIEW</h2>
          <p class="roadmap-desc" style="margin-top: 0.5rem">
            View client project appointments and book a new one below.
          </p>
        </div>

        <div class="client-payment-card">
          <div class="appointment-grid">
            <div>
              <div class="calendar-header">
                <button type="button" class="calendar-nav-btn" id="calendarPrevBtn" title="Previous month">
                  <i class="fas fa-chevron-left"></i>
                </button>
                <span class="calendar-title" id="calendarTitle">Month Year</span>
                <button type="button" class="calendar-nav-btn" id="calendarNextBtn" title="Next month">
                  <i class="fas fa-chevron-right"></i>
                </button>
              </div>
              <div class="calendar-weekdays">
                <div class="calendar-weekday">Sun</div>
                <div class="calendar-weekday">Mon</div>
                <div class="calendar-weekday">Tue</div>
                <div class="calendar-weekday">Wed</div>
                <div class="calendar-weekday">Thu</div>
                <div class="calendar-weekday">Fri</div>
                <div class="calendar-weekday">Sat</div>
              </div>
              <div class="calendar-days" id="calendarDays"></div>
              <div class="appointment-detail" id="appointmentDetail">
                <span class="detail-empty">Click a highlighted date to see the appointment.</span>
              </div>
            </div>

            <form id="appointmentForm" class="client-payment-form" novalidate style="display: flex; flex-direction: column;">
              <h3 class="client-payment-title" style="text-align: center;">Book an Appointment</h3>
              <div>
                <label class="form-label" for="appointmentProjectName">Project Name</label>
                <input id="appointmentProjectName" name="projectName" class="form-control" type="text" maxlength="120" required placeholder="e.g. Library Management System" />
              </div>
              <div>
                <label class="form-label" for="appointmentProjectType">Type of Project</label>
                <select id="appointmentProjectType" name="projectType" class="form-control" required>
                  <option value="" disabled selected>Select a type...</option>
                  <option value="Simple Project">Simple Project</option>
                  <option value="Documentation Project">Documentation Project</option>
                  <option value="Capstone Project">Capstone Project</option>
                  <option value="Business Project">Business Project</option>
                </select>
              </div>
              <div>
                <label class="form-label" for="appointmentFacebookName">Facebook Name</label>
                <input id="appointmentFacebookName" name="facebookName" class="form-control" type="text" maxlength="80" required placeholder="Your Facebook display name" />
              </div>
              <div>
                <label class="form-label" for="appointmentDeadline">Deadline of the Project</label>
                <input id="appointmentDeadline" name="deadline" class="form-control" type="date" required />
              </div>

              <button type="submit" class="btn btn-primary client-payment-btn" style="margin-top: auto;">
                <i class="fas fa-calendar-check me-2"></i>Book Appointment
              </button>
            </form>
          </div>

          <div id="appointmentStatus" class="client-payment-status" role="status" aria-live="polite"></div>
        </div>
      </div>
    </section>

    <!-- Photo Gallery Section -->
    <section id="gallery">
```

- [ ] **Step 2: Verify the section renders**

Open `http://localhost/doc/index.html` and click the "Appointments" navbar link. Confirm:
- The page scrolls to a "CALENDAR VIEW" section.
- An empty calendar grid area, weekday headers, and the booking form (4 fields + button) are visible.
- The calendar grid itself is empty and the title says "Month Year" — wiring happens in Task 5.

- [ ] **Step 3: Commit**

```bash
git add index.html
git commit -m "Add Calendar View section markup to index.html"
```

---

### Task 5: Add the calendar + booking JavaScript

**Files:**
- Modify: `index.html` (insert a new `<script>` block immediately before the closing `</body>` tag)

- [ ] **Step 1: Locate the insertion point**

In `index.html`, find the closing `</body>` tag near the end of the file (the file ends with `</body>` then `</html>`). The new script goes immediately before `</body>`.

- [ ] **Step 2: Insert the appointments script**

Immediately before `</body>`, insert this exact block:

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
        if (!calendarDays || !form) {
          return;
        }

        var MONTHS = [
          "January", "February", "March", "April", "May", "June",
          "July", "August", "September", "October", "November", "December",
        ];
        var viewDate = new Date();
        viewDate.setDate(1);
        // Map of "YYYY-MM-DD" -> appointment object.
        var appointmentsByDate = {};

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
          statusEl.className =
            "client-payment-status is-visible is-" + kind;
        }

        function clearStatus() {
          statusEl.textContent = "";
          statusEl.className = "client-payment-status";
        }

        function showDetail(key) {
          var a = appointmentsByDate[key];
          if (!a) {
            detail.innerHTML =
              '<span class="detail-empty">Click a highlighted date to see the appointment.</span>';
            return;
          }
          detail.innerHTML =
            "<div><strong>" + escapeHtml(a.projectName) + "</strong></div>" +
            "<div>Type: " + escapeHtml(a.projectType) + "</div>" +
            "<div>Facebook: " + escapeHtml(a.facebookName) + "</div>" +
            "<div>Deadline: <strong>" + escapeHtml(a.deadline) + "</strong></div>";
        }

        function escapeHtml(value) {
          var div = document.createElement("div");
          div.textContent = value == null ? "" : String(value);
          return div.innerHTML;
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
            if (appointmentsByDate[key]) {
              cell.classList.add("booked");
              cell.title = appointmentsByDate[key].projectName;
              (function (k) {
                cell.addEventListener("click", function () {
                  showDetail(k);
                });
              })(key);
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
              appointmentsByDate = {};
              if (data && data.ok && Array.isArray(data.appointments)) {
                data.appointments.forEach(function (a) {
                  if (a && a.deadline) {
                    appointmentsByDate[a.deadline] = a;
                  }
                });
              }
              renderCalendar();
            })
            .catch(function () {
              renderCalendar();
            });
        }

        // Set the deadline input's minimum to today.
        deadlineInput.min = todayKey();

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

          if (!form.checkValidity()) {
            form.reportValidity();
            return;
          }

          var deadline = deadlineInput.value;
          if (appointmentsByDate[deadline]) {
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
            projectType: document.getElementById("appointmentProjectType")
              .value,
            facebookName: document
              .getElementById("appointmentFacebookName")
              .value.trim(),
            deadline: deadline,
          };

          var submitBtn = form.querySelector('button[type="submit"]');
          submitBtn.disabled = true;
          setStatus("Booking your appointment...", "info");

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
                  result.data.message || "Appointment booked successfully.",
                  result.data.emailWarning ? "info" : "success"
                );
                form.reset();
                return loadAppointments();
              }
              setStatus(
                (result.data && result.data.error) ||
                  "Could not book the appointment. Please try again.",
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

        loadAppointments();
      })();
    </script>
  </body>
```

Note: the `</body>` is included above to show placement — match it to the existing closing tag; do not add a second `</body>`.

- [ ] **Step 3: Verify the calendar renders and loads data**

Make sure XAMPP Apache is running. Open `http://localhost/doc/index.html`, scroll to the Calendar View. Confirm:
- The calendar shows the current month and year in the title.
- Today's date cell has a highlighted border.
- Month `◀ ▶` buttons change the displayed month.

- [ ] **Step 4: Verify booking end-to-end**

In the booking form, fill Project Name, pick a Type of Project, enter a Facebook Name, and choose a future Deadline date. Click "Book Appointment". Confirm:
- Status shows "Appointment booked successfully." (or the email-warning variant if SMTP is not configured).
- The chosen deadline date becomes highlighted on the calendar.
- Clicking that highlighted date shows the appointment details in the panel below.
- `appointments.json` now exists in the project root and contains the record.

- [ ] **Step 5: Verify the one-per-date rule**

Try to book a second appointment using the **same Deadline date** as Step 4. Confirm the status shows "That date is already booked. Please choose another date." and no second record is added to `appointments.json`.

- [ ] **Step 6: Commit**

```bash
git add index.html
git commit -m "Add calendar rendering and appointment booking JavaScript"
```

---

### Task 6: Final verification pass

**Files:** none (verification only)

- [ ] **Step 1: Light/dark mode check**

Open `http://localhost/doc/index.html`, go to the Calendar View, and toggle the theme with the navbar theme button. Confirm the calendar grid, navigation buttons, booked-date highlight, and detail panel all look correct in BOTH light and dark mode.

- [ ] **Step 2: Mobile layout check**

Narrow the browser window to under ~600px wide (or use device emulation). Confirm the calendar and the booking form stack vertically and remain usable.

- [ ] **Step 3: Cross-month booked dates**

With at least one appointment booked, use the `◀ ▶` buttons to navigate to the month of that appointment. Confirm the booked date is highlighted in its correct month and not in others.

- [ ] **Step 4: Empty-field validation**

Submit the booking form with one field left blank. Confirm the browser blocks submission with its native validation message and no network request is made.

- [ ] **Step 5: Final commit (if any cleanup was needed)**

If steps above required no changes, nothing to commit. If a fix was made:

```bash
git add index.html
git commit -m "Fix Calendar View issues found in final verification"
```

---

## Notes for the Implementer

- **`appointments.json` is not committed.** It is created at runtime by the first booking. Do not create it by hand and do not `git add` it. If you want it ignored by git, that is optional and out of scope.
- **SMTP:** Email sending depends on SMTP credentials in `.env` (same ones `gcash_notification.php` uses). If `.env` is missing or SMTP is unconfigured, bookings still succeed — the response includes an `emailWarning` and the UI shows an info-style status instead of an error. This is intended.
- **Project root path:** Verification `curl` commands assume the app is served at `http://localhost/doc/`. Adjust the path if XAMPP serves it elsewhere.
- All HTML, CSS, and JS for this feature live inline in `index.html`, consistent with the existing Payment section and the rest of the file.
