<?php
echo "Testing PayMongo LIVE Payment Integration...\n\n";

// Load .env
loadDotEnv(__DIR__ . '/.env');

$secretKey = getenv('PAYMONGO_SECRET_KEY');
$publicKey = getenv('PAYMONGO_PUBLIC_KEY');
$environment = getenv('PAYMONGO_ENV') ?: 'test';

echo "Environment: " . strtoupper($environment) . "\n";
echo "Secret Key: " . ($secretKey ? substr($secretKey, 0, 10) . '...' . substr($secretKey, -5) : 'NOT SET') . "\n";
echo "Public Key: " . ($publicKey ? substr($publicKey, 0, 10) . '...' . substr($publicKey, -5) : 'NOT SET') . "\n\n";

if (!$secretKey) {
    echo "❌ ERROR: Missing PAYMONGO_SECRET_KEY!\n";
    exit(1);
}

if (strpos($secretKey, 'sk_live_') !== 0) {
    echo "⚠️  WARNING: Secret key doesn't appear to be a live key (should start with sk_live_)\n\n";
}

$apiBase = 'https://api.paymongo.com/v1';

echo "PayMongo LIVE API Base URL: " . $apiBase . "\n\n";

// Test PayMongo Source API for GCash
$ch = curl_init($apiBase . '/sources');
if ($ch === false) {
    echo "ERROR: Could not initialize cURL\n";
    exit(1);
}

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Basic ' . base64_encode($secretKey . ':'),
];

$testPayload = [
    'data' => [
        'attributes' => [
            'amount' => 1000, // 10.00 PHP
            'currency' => 'PHP',
            'redirect' => [
                'success' => 'https://yourdomain.com/index.html?payment_ref=LIVE-TEST&payment_status=success#payment',
                'failed' => 'https://yourdomain.com/index.html?payment_ref=LIVE-TEST&payment_status=failed#payment',
            ],
            'statement_descriptor' => 'Live Test Payment',
            'type' => 'gcash',
        ],
    ],
];

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($testPayload),
]);

echo "Making LIVE PayMongo Source request for GCash payment...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Status Code: " . $httpCode . "\n";

if ($curlError) {
    echo "cURL Error: " . $curlError . "\n";
}

echo "\nResponse:\n";
$responseData = json_decode($response, true);
if ($responseData) {
    echo json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
    
    if (isset($responseData['data']['attributes']['redirect']['checkout_url'])) {
        echo "\n✅ SUCCESS! LIVE checkout URL: " . $responseData['data']['attributes']['redirect']['checkout_url'] . "\n";
        echo "Source ID: " . $responseData['data']['id'] . "\n";
        echo "\n🎉 Your PayMongo LIVE integration is working!\n";
    } elseif (isset($responseData['errors'])) {
        echo "\n❌ API Error:\n";
        foreach ($responseData['errors'] as $error) {
            echo "  - " . ($error['detail'] ?? $error['message'] ?? 'Unknown error') . "\n";
        }
    } else {
        echo "\n❌ No checkout URL found in response.\n";
    }
} else {
    echo $response . "\n";
}

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
