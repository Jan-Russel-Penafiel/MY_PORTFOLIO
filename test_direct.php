<?php
// Direct test of gcash_payment.php logic
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'create';

// Simulate POST body
$testInput = [
    'projectTitle' => 'Direct Test Project',
    'projectReference' => 'DIRECT-TEST-' . time(),
    'clientName' => 'Test User',
    'amount' => 10.00
];

// Override file_get_contents for php://input
$GLOBALS['test_input'] = json_encode($testInput);

// Include the gcash_payment.php file
include __DIR__ . '/gcash_payment.php';
