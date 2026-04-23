<?php
// Force no cache and test gcash_payment directly
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

opcache_reset();

echo "Testing gcash_payment.php directly at " . date('Y-m-d H:i:s') . "\n\n";

// Make internal request
$ch = curl_init('http://localhost/doc/gcash_payment.php?action=create');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'projectTitle' => 'Direct Test',
        'projectReference' => 'DIRECT-' . time(),
        'clientName' => 'Tester',
        'amount' => 10.00
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
