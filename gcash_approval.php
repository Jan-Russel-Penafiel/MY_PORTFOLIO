<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

// Load Composer autoloader for PHPMailer
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('<h1>Error</h1><p>PHPMailer not installed. Run: composer require phpmailer/phpmailer</p>');
}
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load local .env values
loadDotEnv(__DIR__ . '/.env');

// Get action and parameters
$action = $_GET['action'] ?? '';
$paymentId = $_GET['payment_id'] ?? '';
$token = $_GET['token'] ?? '';

// Validate required parameters
if ($action !== 'approve' || empty($paymentId) || empty($token)) {
    displayErrorPage('Invalid approval link. Please check the URL and try again.');
    exit;
}

// Verify token
$expectedToken = generateApprovalToken($paymentId);
if (!hash_equals($expectedToken, $token)) {
    displayErrorPage('Invalid or expired approval token. Please request a new approval link.');
    exit;
}

// Extract payment details from payment ID (in production, you'd fetch from database)
// For now, we'll use a simple file-based storage
$paymentDataFile = __DIR__ . '/cache/' . md5($paymentId) . '.json';

if (!file_exists($paymentDataFile)) {
    // If no cached data, show a generic success page
    displaySuccessPage($paymentId, 'Unknown Client', 'unknown@example.com', 0.00, 'N/A');
    exit;
}

$paymentData = json_decode(file_get_contents($paymentDataFile), true);

if (!$paymentData) {
    displayErrorPage('Payment data not found or corrupted.');
    exit;
}

$clientName = $paymentData['clientName'] ?? 'Client';
$clientEmail = $paymentData['clientEmail'] ?? '';
$amount = $paymentData['amount'] ?? 0;
$gcashReferenceNumber = $paymentData['gcashReferenceNumber'] ?? 'N/A';

// Send approval email to client
try {
    $smtpHost = envValue('SMTP_HOST') ?: 'smtp.gmail.com';
    $smtpPort = (int) (envValue('SMTP_PORT') ?: 587);
    $smtpUsername = envValue('SMTP_USERNAME') ?: '';
    $smtpPassword = envValue('SMTP_PASSWORD') ?: '';
    $fromEmail = envValue('SMTP_FROM_EMAIL') ?: 'noreply@yourdomain.com';
    $fromName = envValue('SMTP_FROM_NAME') ?: 'Portfolio Payment System';
    
    if (empty($smtpUsername) || empty($smtpPassword)) {
        throw new Exception('SMTP credentials not configured');
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
    $mail->SMTPDebug = 0;
    
    // Recipients
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($clientEmail, $clientName);
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = "🎉 Payment Approved - Thank You!";
    
    $mail->Body = generateApprovalEmailBody(
        $clientName,
        $amount,
        $gcashReferenceNumber,
        $paymentId
    );
    
    $mail->AltBody = strip_tags(str_replace('<br>', "\n", $mail->Body));
    
    $mail->send();
    
    // Mark payment as approved in cache
    $paymentData['status'] = 'approved';
    $paymentData['approvedAt'] = date('Y-m-d H:i:s');
    file_put_contents($paymentDataFile, json_encode($paymentData));
    
    // Display success page
    displaySuccessPage($paymentId, $clientName, $clientEmail, $amount, $gcashReferenceNumber);
    
} catch (Exception $e) {
    error_log("Approval Error: " . $e->getMessage());
    displayErrorPage('Failed to send approval email. Please contact the administrator.');
}

function generateApprovalEmailBody(
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
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
        }
        .email-header p {
            margin: 10px 0 0 0;
            font-size: 16px;
            opacity: 0.95;
        }
        .email-body {
            padding: 40px 30px;
        }
        .success-badge {
            display: inline-block;
            background: rgba(40, 167, 69, 0.2);
            border: 2px solid #28a745;
            color: #28a745;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 18px;
            margin: 20px 0;
        }
        .payment-id-box {
            background: rgba(0, 255, 247, 0.1);
            border: 1px solid rgba(0, 255, 247, 0.3);
            border-radius: 10px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }
        .payment-id-label {
            font-size: 12px;
            color: #7f8ea8;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .payment-id-value {
            font-size: 22px;
            font-weight: 700;
            color: #00fff7;
            font-family: 'Courier New', monospace;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 18px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #7f8ea8;
            font-size: 15px;
        }
        .detail-value {
            color: #ffffff;
            text-align: right;
            font-weight: 600;
        }
        .amount-value {
            color: #28a745;
            font-size: 28px;
            font-weight: 700;
        }
        .message-box {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.3);
            border-radius: 12px;
            padding: 30px;
            margin: 30px 0;
            text-align: center;
        }
        .message-box h2 {
            color: #28a745;
            margin: 0 0 15px 0;
            font-size: 24px;
        }
        .message-box p {
            color: #b0b8c8;
            margin: 10px 0;
            font-size: 15px;
            line-height: 1.6;
        }
        .next-steps {
            background: rgba(0, 255, 247, 0.08);
            border: 1px solid rgba(0, 255, 247, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
        }
        .next-steps h3 {
            color: #00fff7;
            margin: 0 0 20px 0;
            font-size: 20px;
        }
        .next-steps ul {
            margin: 15px 0;
            padding-left: 25px;
        }
        .next-steps li {
            color: #b0b8c8;
            margin: 12px 0;
            font-size: 14px;
            line-height: 1.5;
        }
        .email-footer {
            background: rgba(0, 0, 0, 0.3);
            padding: 30px;
            text-align: center;
            color: #7f8ea8;
            font-size: 13px;
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
            <h1>🎉 Payment Approved!</h1>
            <p>Your payment has been successfully verified and approved</p>
        </div>
        <div class="email-body">
            <div style="text-align: center;">
                <div class="success-badge">✓ APPROVED</div>
            </div>
            
            <p style="color: #b0b8c8; font-size: 16px; margin: 25px 0;">Dear {$clientName},</p>
            
            <p style="color: #b0b8c8; font-size: 15px; line-height: 1.8;">
                Great news! Your payment has been successfully verified and approved. Thank you for your prompt payment. We appreciate your business!
            </p>
            
            <div class="payment-id-box">
                <div class="payment-id-label">Payment ID</div>
                <div class="payment-id-value">{$paymentId}</div>
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
                <span class="detail-label">🔖 GCash Reference:</span>
                <span class="detail-value" style="font-family: 'Courier New', monospace; color: #00fff7;">{$gcashReferenceNumber}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">✅ Approved On:</span>
                <span class="detail-value">{$timestamp}</span>
            </div>
            
            <div class="message-box">
                <h2>Thank You for Your Payment!</h2>
                <p>Your project work will now proceed as scheduled. We're committed to delivering high-quality results and will keep you updated on the progress.</p>
            </div>
            
            <div class="next-steps">
                <h3>📋 What Happens Next?</h3>
                <ul>
                    <li>We'll begin/continue working on your project immediately</li>
                    <li>You'll receive regular progress updates via email</li>
                    <li>If we need any additional information, we'll contact you promptly</li>
                    <li>Final deliverables will be shared according to the agreed timeline</li>
                </ul>
            </div>
            
            <p style="color: #7f8ea8; font-size: 14px; margin-top: 30px; padding-top: 25px; border-top: 1px solid rgba(255, 255, 255, 0.1); line-height: 1.6;">
                <strong>💬 Questions?</strong> If you have any questions or need assistance, feel free to reach out to us at <a href="mailto:janrusselpenafiel01172005@gmail.com" style="color: #00fff7; text-decoration: none;">janrusselpenafiel01172005@gmail.com</a> or call <strong>+63 967 772 6912</strong>.
            </p>
        </div>
        <div class="email-footer">
            <p>This is an automated approval confirmation from Portfolio Payment System.</p>
            <p>&copy; " . date('Y') . " Jan Russel Penaflor. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

function displaySuccessPage(string $paymentId, string $clientName, string $clientEmail, float $amount, string $gcashReferenceNumber): void {
    $formattedAmount = number_format($amount, 2);
    
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Approved - Success</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', Arial, sans-serif;
            background: linear-gradient(135deg, #0a0e1a 0%, #1a1c2e 100%);
            color: #e0e6ed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .success-container {
            max-width: 700px;
            width: 100%;
            background: linear-gradient(135deg, #181825 0%, #1a1c2e 100%);
            border: 1.5px solid rgba(0, 255, 247, 0.3);
            border-radius: 20px;
            padding: 50px 40px;
            box-shadow: 0 10px 40px rgba(0, 255, 247, 0.2);
            text-align: center;
        }
        
        .success-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            font-size: 60px;
            box-shadow: 0 8px 30px rgba(40, 167, 69, 0.4);
            animation: scaleIn 0.5s ease-out;
        }
        
        @keyframes scaleIn {
            0% { transform: scale(0); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        h1 {
            font-size: 36px;
            font-weight: 700;
            color: #28a745;
            margin-bottom: 15px;
        }
        
        .subtitle {
            font-size: 18px;
            color: #b0b8c8;
            margin-bottom: 40px;
        }
        
        .details-box {
            background: rgba(0, 255, 247, 0.05);
            border: 1px solid rgba(0, 255, 247, 0.2);
            border-radius: 15px;
            padding: 30px;
            margin: 30px 0;
            text-align: left;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #7f8ea8;
            font-weight: 600;
        }
        
        .detail-value {
            color: #ffffff;
            font-weight: 600;
        }
        
        .payment-id {
            color: #00fff7;
            font-family: 'Courier New', monospace;
            font-size: 18px;
        }
        
        .amount {
            color: #28a745;
            font-size: 28px;
            font-weight: 700;
        }
        
        .message {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.3);
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            color: #b0b8c8;
            line-height: 1.6;
        }
        
        .home-btn {
            display: inline-block;
            background: linear-gradient(135deg, #00fff7 0%, #00d4d4 100%);
            color: #0a0e1a;
            padding: 15px 40px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            margin-top: 30px;
            box-shadow: 0 6px 20px rgba(0, 255, 247, 0.4);
            transition: all 0.3s ease;
        }
        
        .home-btn:hover {
            box-shadow: 0 8px 25px rgba(0, 255, 247, 0.6);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">✓</div>
        <h1>Payment Approved!</h1>
        <p class="subtitle">The approval confirmation email has been sent to the client.</p>
        
        <div class="details-box">
            <div class="detail-row">
                <span class="detail-label">Payment ID:</span>
                <span class="detail-value payment-id">{$paymentId}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Client:</span>
                <span class="detail-value">{$clientName}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Email:</span>
                <span class="detail-value">{$clientEmail}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount:</span>
                <span class="detail-value amount">₱{$formattedAmount}</span>
            </div>
        </div>
        
        <div class="message">
            <strong>✅ Success!</strong> The client has been notified that their payment has been approved. You can now proceed with the project work.
        </div>
        
        <a href="index.html" class="home-btn">← Back to Portfolio</a>
    </div>
</body>
</html>
HTML;
}

function displayErrorPage(string $message): void {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Approval Failed</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', Arial, sans-serif;
            background: linear-gradient(135deg, #0a0e1a 0%, #1a1c2e 100%);
            color: #e0e6ed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            max-width: 600px;
            width: 100%;
            background: linear-gradient(135deg, #181825 0%, #1a1c2e 100%);
            border: 1.5px solid rgba(255, 71, 87, 0.3);
            border-radius: 20px;
            padding: 50px 40px;
            box-shadow: 0 10px 40px rgba(255, 71, 87, 0.2);
            text-align: center;
        }
        
        .error-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #ff4757 0%, #ff6b81 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            font-size: 60px;
            box-shadow: 0 8px 30px rgba(255, 71, 87, 0.4);
        }
        
        h1 {
            font-size: 32px;
            font-weight: 700;
            color: #ff4757;
            margin-bottom: 20px;
        }
        
        .message {
            background: rgba(255, 71, 87, 0.1);
            border: 1px solid rgba(255, 71, 87, 0.3);
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            color: #b0b8c8;
            line-height: 1.6;
        }
        
        .home-btn {
            display: inline-block;
            background: linear-gradient(135deg, #00fff7 0%, #00d4d4 100%);
            color: #0a0e1a;
            padding: 15px 40px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            margin-top: 30px;
            box-shadow: 0 6px 20px rgba(0, 255, 247, 0.4);
            transition: all 0.3s ease;
        }
        
        .home-btn:hover {
            box-shadow: 0 8px 25px rgba(0, 255, 247, 0.6);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">✕</div>
        <h1>Approval Failed</h1>
        <div class="message">
            {$message}
        </div>
        <a href="index.html" class="home-btn">← Back to Portfolio</a>
    </div>
</body>
</html>
HTML;
}

function generateApprovalToken(string $paymentId): string {
    $secret = envValue('APPROVAL_SECRET') ?: 'default-secret-change-me';
    return hash_hmac('sha256', $paymentId, $secret);
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
