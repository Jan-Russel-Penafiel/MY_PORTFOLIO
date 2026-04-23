<?php
// Debug version of gcash_payment.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=UTF-8');

// Load .env
loadDotEnv(__DIR__ . '/.env');

$apiKey = getenv('ADYEN_API_KEY');
$merchantAccount = getenv('ADYEN_MERCHANT_ACCOUNT');
$environment = getenv('ADYEN_ENV') ?: 'test';

$apiBase = $environment === 'live'
    ? 'https://checkout-live.adyen.com/v71'
    : 'https://checkout-test.adyen.com/v71';

$input = json_decode(file_get_contents('php://input'), true);

$requestBody = [
    'amount' => [
        'currency' => 'PHP',
        'value' => 1000,
    ],
    'reference' => 'DEBUG-' . time(),
    'merchantAccount' => $merchantAccount,
    'countryCode' => 'PH',
    'shopperLocale' => 'en-PH',
    'channel' => 'Web',
    'returnUrl' => 'http://localhost/doc/index.html?payment_ref=DEBUG#payment',
    'allowedPaymentMethods' => ['gcash'],
];

$ch = curl_init($apiBase . '/sessions');

$headers = [
    'Content-Type: application/json',
    'x-api-key: ' . $apiKey,
];

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($requestBody),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$responseData = json_decode($response, true);

echo json_encode([
    'debug' => true,
    'httpCode' => $httpCode,
    'curlError' => $curlError,
    'apiKeySet' => !empty($apiKey),
    'merchantAccount' => $merchantAccount,
    'apiBase' => $apiBase,
    'rawResponse' => $response,
    'parsedResponse' => $responseData,
    'hasUrl' => isset($responseData['url']),
    'hasId' => isset($responseData['id']),
    'responseKeys' => array_keys($responseData ?? []),
], JSON_PRETTY_PRINT);

function loadDotEnv($path) {
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
