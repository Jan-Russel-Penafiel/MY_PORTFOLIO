<?php
echo "Testing Adyen API Connection...\n\n";

// Load .env
loadDotEnv(__DIR__ . '/.env');

$apiKey = getenv('ADYEN_API_KEY');
$merchantAccount = getenv('ADYEN_MERCHANT_ACCOUNT');
$environment = getenv('ADYEN_ENV') ?: 'test';

echo "API Key: " . ($apiKey ? substr($apiKey, 0, 20) . '...' : 'NOT SET') . "\n";
echo "Merchant Account: " . ($merchantAccount ?: 'NOT SET') . "\n";
echo "Environment: " . $environment . "\n\n";

if (!$apiKey || !$merchantAccount) {
    echo "ERROR: Missing API configuration!\n";
    exit(1);
}

$apiBase = $environment === 'live'
    ? 'https://checkout-live.adyen.com/v71'
    : 'https://checkout-test.adyen.com/v71';

echo "API Base URL: " . $apiBase . "\n\n";

// Test connection
$ch = curl_init($apiBase . '/sessions');
if ($ch === false) {
    echo "ERROR: Could not initialize cURL\n";
    exit(1);
}

$headers = [
    'Content-Type: application/json',
    'x-api-key: ' . $apiKey,
];

$testPayload = [
    'amount' => [
        'currency' => 'PHP',
        'value' => 1000, // 10.00 PHP
    ],
    'reference' => 'TEST-' . time(),
    'merchantAccount' => $merchantAccount,
    'countryCode' => 'PH',
    'shopperLocale' => 'en-PH',
    'channel' => 'Web',
    'returnUrl' => 'http://localhost/doc/index.html?payment_ref=TEST#payment',
    'allowedPaymentMethods' => ['gcash'],
];

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($testPayload),
]);

echo "Making request to Adyen...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Status Code: " . $httpCode . "\n";

if ($curlError) {
    echo "cURL Error: " . $curlError . "\n";
}

echo "\nResponse:\n";
echo $response . "\n";

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
