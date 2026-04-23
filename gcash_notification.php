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

// Generate unique payment ID for tracking
$paymentId = 'PAY-' . time() . '-' . strtoupper(substr(md5(uniqid()), 0, 8));

// Send email notification to admin
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
    
    // Recipients - Admin
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipientEmail, 'Jan Russel Penaflor');
    $mail->addReplyTo($clientEmail, $clientName);
    
    // Content - Admin Notification
    $mail->isHTML(true);
    $mail->Subject = "🔔 New Payment Submission - {$clientName} ({$paymentId})";
    
    $mail->Body = generateAdminEmailBody(
        $clientName,
        $clientEmail,
        $amount,
        $gcashReferenceNumber,
        $paymentId
    );
    
    $mail->AltBody = strip_tags(str_replace('<br>', "\n", $mail->Body));
    
    $mail->send();
    
    // Log successful email send
    error_log("GCash Notification: Admin email sent successfully to {$recipientEmail} for client {$clientName}, Payment ID: {$paymentId}");
    
    // Save payment data to cache for approval endpoint
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $paymentDataFile = $cacheDir . '/' . md5($paymentId) . '.json';
    $paymentData = [
        'paymentId' => $paymentId,
        'clientName' => $clientName,
        'clientEmail' => $clientEmail,
        'amount' => $amount,
        'gcashReferenceNumber' => $gcashReferenceNumber,
        'submittedAt' => date('Y-m-d H:i:s'),
        'status' => 'pending',
    ];
    
    @file_put_contents($paymentDataFile, json_encode($paymentData));
    
    // Send confirmation email to client
    try {
        $mailClient = new PHPMailer(true);
        
        // Server settings
        $mailClient->isSMTP();
        $mailClient->Host = $smtpHost;
        $mailClient->SMTPAuth = true;
        $mailClient->Username = $smtpUsername;
        $mailClient->Password = $smtpPassword;
        $mailClient->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailClient->Port = $smtpPort;
        $mailClient->SMTPDebug = 0;
        
        // Recipients - Client
        $mailClient->setFrom($fromEmail, $fromName);
        $mailClient->addAddress($clientEmail, $clientName);
        
        // Content - Client Confirmation
        $mailClient->isHTML(true);
        $mailClient->Subject = "✅ Payment Received - We're Reviewing Your Submission";
        
        $mailClient->Body = generateClientConfirmationEmail(
            $clientName,
            $amount,
            $gcashReferenceNumber,
            $paymentId
        );
        
        $mailClient->AltBody = strip_tags(str_replace('<br>', "\n", $mailClient->Body));
        
        $mailClient->send();
        error_log("GCash Notification: Client confirmation email sent to {$clientEmail}");
        
    } catch (Exception $e) {
        error_log("GCash Notification: Failed to send client confirmation: " . $e->getMessage());
        // Don't fail the whole request if client email fails
    }
    
    respond(200, [
        'ok' => true,
        'message' => 'Payment details submitted and notification sent successfully.',
        'paymentId' => $paymentId,
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

function generateAdminEmailBody(
    string $clientName,
    string $clientEmail,
    float $amount,
    string $gcashReferenceNumber,
    string $paymentId
): string {
    $formattedAmount = number_format($amount, 2);
    $timestamp = date('F j, Y g:i A');
    
    // Generate approval link
    $approvalLink = getBaseUrl() . '/gcash_approval.php?action=approve&payment_id=' . urlencode($paymentId) . '&token=' . generateApprovalToken($paymentId);
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        
        body {
            font-family: 'Inter', Arial, sans-serif;
            line-height: 1.6;
            color: #e0e6ed;
            background-color: #0a0e1a;
            margin: 0;
            padding: 20px;
        }
        .email-container {
            max-width: 650px;
            margin: 0 auto;
            background: linear-gradient(135deg, #181825 0%, #1a1c2e 100%);
            border: 1.5px solid rgba(0, 255, 247, 0.3);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 255, 247, 0.15);
        }
        .email-header {
            background: linear-gradient(135deg, #00fff7 0%, #00d4d4 100%);
            color: #0a0e1a;
            padding: 35px 30px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
        }
        .email-header p {
            margin: 8px 0 0 0;
            font-size: 14px;
            opacity: 0.8;
        }
        .email-body {
            padding: 35px 30px;
        }
        .payment-id-box {
            background: rgba(0, 255, 247, 0.1);
            border: 1px solid rgba(0, 255, 247, 0.3);
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
            text-align: center;
        }
        .payment-id-label {
            font-size: 12px;
            color: #7f8ea8;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .payment-id-value {
            font-size: 20px;
            font-weight: 700;
            color: #00fff7;
            font-family: 'Courier New', monospace;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #7f8ea8;
            font-size: 14px;
        }
        .detail-value {
            color: #ffffff;
            text-align: right;
            font-weight: 600;
        }
        .amount-value {
            color: #00fff7;
            font-size: 24px;
            font-weight: 700;
        }
        .reference-box {
            background: rgba(0, 255, 247, 0.05);
            border-left: 4px solid #00fff7;
            padding: 20px;
            margin: 25px 0;
            border-radius: 8px;
        }
        .reference-label {
            font-size: 12px;
            color: #7f8ea8;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .reference-value {
            font-size: 22px;
            color: #00fff7;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
        }
        .action-section {
            background: rgba(0, 255, 247, 0.08);
            border: 1px solid rgba(0, 255, 247, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            text-align: center;
        }
        .action-title {
            font-size: 18px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 15px;
        }
        .approve-btn {
            display: inline-block;
            background: linear-gradient(135deg, #00fff7 0%, #00d4d4 100%);
            color: #0a0e1a;
            padding: 15px 40px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            margin: 10px 5px;
            box-shadow: 0 4px 15px rgba(0, 255, 247, 0.4);
            transition: all 0.3s ease;
        }
        .approve-btn:hover {
            box-shadow: 0 6px 20px rgba(0, 255, 247, 0.6);
            transform: translateY(-2px);
        }
        .note-text {
            color: #7f8ea8;
            font-size: 13px;
            margin-top: 15px;
            line-height: 1.5;
        }
        .email-footer {
            background: rgba(0, 0, 0, 0.3);
            padding: 25px 30px;
            text-align: center;
            color: #7f8ea8;
            font-size: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        .email-footer p {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>💰 New Payment Submission</h1>
            <p>A client has submitted a GCash payment for review</p>
        </div>
        <div class="email-body">
            <div class="payment-id-box">
                <div class="payment-id-label">Payment ID</div>
                <div class="payment-id-value">{$paymentId}</div>
            </div>
            
            <p style="color: #b0b8c8; margin-bottom: 25px;">A new payment has been submitted through your portfolio website. Please review the details below and verify the payment in your GCash account.</p>
            
            <div class="reference-box">
                <div class="reference-label">GCash Reference Number</div>
                <div class="reference-value">{$gcashReferenceNumber}</div>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">👤 Client Name:</span>
                <span class="detail-value">{$clientName}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">📧 Client Email:</span>
                <span class="detail-value">{$clientEmail}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">💵 Amount Paid:</span>
                <span class="detail-value amount-value">₱{$formattedAmount}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">📅 Submitted On:</span>
                <span class="detail-value">{$timestamp}</span>
            </div>
            
            <div class="action-section">
                <div class="action-title">✅ Verify & Approve Payment</div>
                <p style="color: #b0b8c8; margin-bottom: 20px;">After verifying the payment in your GCash account, click the button below to approve and notify the client:</p>
                <a href="{$approvalLink}" class="approve-btn">
                    ✓ APPROVE PAYMENT
                </a>
                <p class="note-text">Clicking this will automatically send an approval confirmation email to the client.</p>
            </div>
            
            <p style="color: #7f8ea8; font-size: 13px; margin-top: 25px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                <strong>⚠️ Important:</strong> Please verify the payment in your GCash app before clicking approve. The client will receive an automatic confirmation email once approved.
            </p>
        </div>
        <div class="email-footer">
            <p>This is an automated notification from your Portfolio Payment System.</p>
            <p>&copy; " . date('Y') . " Jan Russel Penaflor. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

function generateClientConfirmationEmail(
    string $clientName,
    float $amount,
    string $gcashReferenceNumber,
    string $paymentId
): string {
    $formattedAmount = number_format($amount, 2);
    $timestamp = date('F j, Y g:i A');
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        
        body {
            font-family: 'Inter', Arial, sans-serif;
            line-height: 1.6;
            color: #e0e6ed;
            background-color: #0a0e1a;
            margin: 0;
            padding: 20px;
        }
        .email-container {
            max-width: 650px;
            margin: 0 auto;
            background: linear-gradient(135deg, #181825 0%, #1a1c2e 100%);
            border: 1.5px solid rgba(0, 255, 247, 0.3);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 255, 247, 0.15);
        }
        .email-header {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: #0a0e1a;
            padding: 35px 30px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
        }
        .email-header p {
            margin: 8px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .email-body {
            padding: 35px 30px;
        }
        .payment-id-box {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
            text-align: center;
        }
        .payment-id-label {
            font-size: 12px;
            color: #7f8ea8;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .payment-id-value {
            font-size: 20px;
            font-weight: 700;
            color: #ffc107;
            font-family: 'Courier New', monospace;
        }
        .status-badge {
            display: inline-block;
            background: rgba(255, 193, 7, 0.2);
            border: 1px solid rgba(255, 193, 7, 0.4);
            color: #ffc107;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
            margin: 15px 0;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #7f8ea8;
            font-size: 14px;
        }
        .detail-value {
            color: #ffffff;
            text-align: right;
            font-weight: 600;
        }
        .amount-value {
            color: #ffc107;
            font-size: 24px;
            font-weight: 700;
        }
        .info-box {
            background: rgba(0, 255, 247, 0.08);
            border: 1px solid rgba(0, 255, 247, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
        }
        .info-box h3 {
            color: #00fff7;
            margin: 0 0 15px 0;
            font-size: 18px;
        }
        .info-box p {
            color: #b0b8c8;
            margin: 10px 0;
            font-size: 14px;
        }
        .info-box ul {
            margin: 15px 0;
            padding-left: 20px;
        }
        .info-box li {
            color: #b0b8c8;
            margin: 8px 0;
            font-size: 14px;
        }
        .email-footer {
            background: rgba(0, 0, 0, 0.3);
            padding: 25px 30px;
            text-align: center;
            color: #7f8ea8;
            font-size: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        .email-footer p {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>📧 Payment Submission Received</h1>
            <p>Thank you for your payment, {$clientName}!</p>
        </div>
        <div class="email-body">
            <div class="payment-id-box">
                <div class="payment-id-label">Payment ID</div>
                <div class="payment-id-value">{$paymentId}</div>
            </div>
            
            <div style="text-align: center;">
                <div class="status-badge">⏳ UNDER REVIEW</div>
            </div>
            
            <p style="color: #b0b8c8; margin: 25px 0;">We have received your payment submission. Your payment is currently being reviewed and verified. You will receive another email once your payment has been approved.</p>
            
            <div style="background: rgba(255, 193, 7, 0.05); border-left: 4px solid #ffc107; padding: 20px; margin: 25px 0; border-radius: 8px;">
                <div style="font-size: 12px; color: #7f8ea8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">GCash Reference Number</div>
                <div style="font-size: 22px; color: #ffc107; font-weight: 700; font-family: 'Courier New', monospace; letter-spacing: 2px;">{$gcashReferenceNumber}</div>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">👤 Client Name:</span>
                <span class="detail-value">{$clientName}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">💵 Amount Paid:</span>
                <span class="detail-value amount-value">₱{$formattedAmount}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">📅 Submitted On:</span>
                <span class="detail-value">{$timestamp}</span>
            </div>
            
            <div class="info-box">
                <h3>📋 What Happens Next?</h3>
                <ul>
                    <li>We will verify your payment in our GCash account</li>
                    <li>You will receive an approval confirmation email once verified</li>
                    <li>The entire process typically takes 1-24 hours</li>
                    <li>If there are any issues, we will contact you at this email</li>
                </ul>
            </div>
            
            <p style="color: #7f8ea8; font-size: 13px; margin-top: 25px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                <strong>💡 Need Help?</strong> If you have any questions or concerns about your payment, please don't hesitate to contact us at <a href="mailto:janrusselpenafiel01172005@gmail.com" style="color: #00fff7;">janrusselpenafiel01172005@gmail.com</a>
            </p>
        </div>
        <div class="email-footer">
            <p>This is an automated confirmation from Portfolio Payment System.</p>
            <p>&copy; " . date('Y') . " Jan Russel Penaflor. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

function generateApprovalToken(string $paymentId): string {
    // Generate a simple token based on payment ID and secret
    $secret = envValue('APPROVAL_SECRET') ?: 'default-secret-change-me';
    return hash_hmac('sha256', $paymentId, $secret);
}

function getBaseUrl(): string {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    $basePath = dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = str_replace('\\', '/', $basePath);
    $basePath = rtrim($basePath, '/');
    
    return $scheme . '://' . $host . $basePath;
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
