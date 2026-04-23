<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'ok' => false,
        'error' => 'Method not allowed. Use POST.',
    ]);
}

// Load Composer autoloader for PHPMailer
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    respond(500, [
        'ok' => false,
        'error' => 'PHPMailer not installed. Run: composer require phpmailer/phpmailer',
    ]);
}
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load local .env values
loadDotEnv(__DIR__ . '/.env');

$input = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(400, [
        'ok' => false,
        'error' => 'Invalid JSON payload.',
    ]);
}

// Extract and sanitize input data
$clientName = sanitizeText(trim((string) ($input['clientName'] ?? '')), 80);
$clientEmail = filter_var(trim((string) ($input['clientEmail'] ?? '')), FILTER_VALIDATE_EMAIL) 
    ? trim((string) ($input['clientEmail'])) 
    : '';
$amount = (float) ($input['amount'] ?? 0);
$gcashReferenceNumber = sanitizeText(trim((string) ($input['gcashReferenceNumber'] ?? '')), 50);

// Validate required fields
if ($clientName === '') {
    respond(422, ['ok' => false, 'error' => 'Client name is required.']);
}

if ($clientEmail === '') {
    respond(422, ['ok' => false, 'error' => 'Valid client email is required.']);
}

if ($amount <= 0) {
    respond(422, ['ok' => false, 'error' => 'Amount must be greater than zero.']);
}

if ($gcashReferenceNumber === '') {
    respond(422, ['ok' => false, 'error' => 'GCash Reference Number is required.']);
}

// Validate GCash Reference Number is exactly 13 digits
if (!preg_match('/^\d{13}$/', $gcashReferenceNumber)) {
    respond(422, ['ok' => false, 'error' => 'GCash Reference Number must be exactly 13 digits.']);
}

// Email configuration
$recipientEmail = 'janrusselpenafiel01172005@gmail.com';
$fromEmail = envValue('SMTP_FROM_EMAIL') ?: 'noreply@yourdomain.com';
$fromName = envValue('SMTP_FROM_NAME') ?: 'Portfolio Payment System';

// SMTP Configuration (configure these in .env)
$smtpHost = envValue('SMTP_HOST') ?: 'smtp.gmail.com';
$smtpPort = (int) (envValue('SMTP_PORT') ?: 587);
$smtpUsername = envValue('SMTP_USERNAME') ?: '';
$smtpPassword = envValue('SMTP_PASSWORD') ?: '';
$smtpEncryption = envValue('SMTP_ENCRYPTION') ?: 'tls';

// Send email notification
try {
    // Check if SMTP credentials are configured
    if (empty($smtpUsername) || empty($smtpPassword)) {
        error_log('GCash Notification: SMTP credentials not configured in .env file');
        respond(500, [
            'ok' => false,
            'error' => 'Email service not configured. Please contact the administrator.',
        ]);
    }
    
    $mail = new PHPMailer(true);
    
    // Server settings
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $smtpPort;
    
    // Enable verbose debug mode (0 = off, 1 = client, 2 = client and server)
    $mail->SMTPDebug = 0;
    $mail->Debugoutput = function($str, $level) {
        error_log("SMTP Debug level $level: $str");
    };
    
    // Recipients
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipientEmail, 'Jan Russel Penaflor');
    $mail->addReplyTo($clientEmail, $clientName);
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = "New GCash Payment Received - {$clientName}";
    
    $mail->Body = generateEmailBody(
        $clientName,
        $clientEmail,
        $amount,
        $gcashReferenceNumber
    );
    
    $mail->AltBody = strip_tags(str_replace('<br>', "\n", $mail->Body));
    
    $mail->send();
    
    // Log successful email send
    error_log("GCash Notification: Email sent successfully to {$recipientEmail} for client {$clientName}");
    
    respond(200, [
        'ok' => true,
        'message' => 'Payment details submitted and notification sent successfully.',
    ]);
    
} catch (Exception $e) {
    // Log the error with detailed information
    $errorMessage = "Email sending failed: {$mail->ErrorInfo}";
    error_log("GCash Notification Error: {$errorMessage}");
    error_log("GCash Notification Exception: " . $e->getMessage());
    
    respond(500, [
        'ok' => false,
        'error' => 'Failed to send notification email. Please try again later.',
        'debug' => envValue('APP_DEBUG') === 'true' ? $e->getMessage() : null,
    ]);
}

function generateEmailBody(
    string $clientName,
    string $clientEmail,
    float $amount,
    string $gcashReferenceNumber
): string {
    $formattedAmount = number_format($amount, 2);
    $timestamp = date('F j, Y g:i A');
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 24px;
        }
        .email-body {
            padding: 30px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        .detail-label {
            font-weight: bold;
            color: #555;
        }
        .detail-value {
            color: #333;
            text-align: right;
        }
        .amount-value {
            color: #28a745;
            font-size: 20px;
            font-weight: bold;
        }
        .email-footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        .reference-box {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>💰 New GCash Payment Received</h1>
        </div>
        <div class="email-body">
            <p>A new payment has been submitted through your portfolio website.</p>
            
            <div class="reference-box">
                <strong>GCash Reference Number:</strong><br>
                <span style="font-size: 18px; color: #007bff;">{$gcashReferenceNumber}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Client Name:</span>
                <span class="detail-value">{$clientName}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Client Email:</span>
                <span class="detail-value">{$clientEmail}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount Paid:</span>
                <span class="detail-value amount-value">₱{$formattedAmount}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Submitted On:</span>
                <span class="detail-value">{$timestamp}</span>
            </div>
            
            <p style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #eee;">
                <strong>Action Required:</strong> Please verify the payment in your GCash account and follow up with the client accordingly.
            </p>
        </div>
        <div class="email-footer">
            <p>This is an automated notification from your Portfolio Payment System.</p>
            <p>&copy; {$timestamp} Jan Russel Penaflor. All rights reserved.</p>
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

        // Do not overwrite already-present environment variables.
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
