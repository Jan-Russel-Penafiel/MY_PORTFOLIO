<?php
/**
 * Test script to verify PHPMailer and SMTP configuration
 * Run this from browser: http://localhost/doc/test_phpmailer.php
 */

header('Content-Type: text/html; charset=UTF-8');

echo "<h1>PHPMailer & SMTP Configuration Test</h1>";

// Load Composer autoloader
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "<p style='color: red;'>❌ ERROR: vendor/autoload.php not found. Run: composer require phpmailer/phpmailer</p>";
    exit;
}

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load .env
loadDotEnv(__DIR__ . '/.env');

echo "<h2>Configuration Check</h2>";

$smtpHost = envValue('SMTP_HOST') ?: 'not set';
$smtpPort = envValue('SMTP_PORT') ?: 'not set';
$smtpUsername = envValue('SMTP_USERNAME') ?: 'not set';
$smtpPassword = envValue('SMTP_PASSWORD');
$smtpPasswordDisplay = $smtpPassword && $smtpPassword !== 'not set' ? '**** (set)' : '❌ NOT SET';

echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Setting</th><th>Value</th><th>Status</th></tr>";
echo "<tr><td>SMTP_HOST</td><td>{$smtpHost}</td><td>" . ($smtpHost !== 'not set' ? '✅' : '❌') . "</td></tr>";
echo "<tr><td>SMTP_PORT</td><td>{$smtpPort}</td><td>" . ($smtpPort !== 'not set' ? '✅' : '❌') . "</td></tr>";
echo "<tr><td>SMTP_USERNAME</td><td>{$smtpUsername}</td><td>" . ($smtpUsername !== 'not set' ? '✅' : '❌') . "</td></tr>";
echo "<tr><td>SMTP_PASSWORD</td><td>{$smtpPasswordDisplay}</td><td>" . ($smtpPassword && $smtpPassword !== 'not set' ? '✅' : '❌') . "</td></tr>";
echo "</table>";

if (empty($smtpUsername) || empty($smtpPassword) || $smtpUsername === 'not set' || !$smtpPassword) {
    echo "<p style='color: red;'><strong>❌ ERROR:</strong> SMTP credentials are not configured in .env file</p>";
    echo "<p>Please edit <code>.env</code> and set your SMTP credentials.</p>";
    exit;
}

echo "<h2>Testing Email Connection</h2>";

try {
    $mail = new PHPMailer(true);
    
    // Server settings
    $mail->isSMTP();
    $mail->Host = envValue('SMTP_HOST');
    $mail->SMTPAuth = true;
    $mail->Username = envValue('SMTP_USERNAME');
    $mail->Password = envValue('SMTP_PASSWORD');
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) envValue('SMTP_PORT');
    
    // Enable debug output
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) {
        echo "<pre style='background: #f5f5f5; padding: 5px;'>Debug Level {$level}: " . htmlspecialchars($str) . "</pre>";
    };
    
    // Recipients
    $mail->setFrom(envValue('SMTP_USERNAME'), 'Test Sender');
    $mail->addAddress(envValue('SMTP_USERNAME'), 'Test Recipient');
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'PHPMailer Test Email';
    $mail->Body = '<h2>Test Email</h2><p>If you received this, PHPMailer is working correctly!</p><p>Time: ' . date('Y-m-d H:i:s') . '</p>';
    $mail->AltBody = 'This is a test email from PHPMailer. Time: ' . date('Y-m-d H:i:s');
    
    echo "<p>📤 Sending test email to: <strong>" . htmlspecialchars(envValue('SMTP_USERNAME')) . "</strong></p>";
    
    $mail->send();
    
    echo "<p style='color: green; font-size: 1.2em;'><strong>✅ SUCCESS!</strong> Email sent successfully!</p>";
    echo "<p>Check your inbox for the test email.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red; font-size: 1.2em;'><strong>❌ FAILED!</strong></p>";
    echo "<p><strong>Error Message:</strong> " . htmlspecialchars($mail->ErrorInfo) . "</p>";
    echo "<p><strong>Exception:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    
    echo "<h3>Troubleshooting Tips:</h3>";
    echo "<ul>";
    echo "<li>Make sure SMTP credentials in <code>.env</code> are correct</li>";
    echo "<li>For Gmail, you need to use an <strong>App Password</strong> (not your regular password)</li>";
    echo "<li>Check if your hosting allows outbound SMTP connections</li>";
    echo "<li>Verify firewall settings allow connection to {$smtpHost}:{$smtpPort}</li>";
    echo "</ul>";
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
