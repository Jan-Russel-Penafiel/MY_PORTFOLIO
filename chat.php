<?php
// chat.php
// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => ['message' => 'Method not allowed']]);
    exit;
}

// Load configuration from JSON file
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    echo json_encode(['error' => ['message' => 'Configuration file not found']]);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);
if (!$config || !isset($config['gemini']['apiKey'])) {
    echo json_encode(['error' => ['message' => 'Invalid configuration']]);
    exit;
}

$apiKey = $config['gemini']['apiKey'];
$model = $config['gemini']['model'] ?? "gemini-2.5-flash";

$msg = $_POST['msg'] ?? '';
$systemPrompt = $_POST['systemPrompt'] ?? '';
$historyJson = $_POST['history'] ?? '[]';
$history = json_decode($historyJson, true) ?? [];

$url = "https://generativelanguage.googleapis.com/v1/models/$model:generateContent?key=$apiKey";

// Build contents array
$contents = [];

// Add system prompt as first user/model exchange
if (!empty($systemPrompt)) {
    $contents[] = [
        "role" => "user",
        "parts" => [["text" => $systemPrompt]]
    ];
    $contents[] = [
        "role" => "model",
        "parts" => [["text" => "Understood! I will act as Russel AI and answer questions about Jan Russel based on the portfolio information provided, detecting and responding in the language of each message."]]
    ];
}

// Add conversation history
foreach ($history as $historyItem) {
    $contents[] = [
        "role" => $historyItem['role'],
        "parts" => [["text" => $historyItem['content']]]
    ];
}

// Add current message
$contents[] = [
    "role" => "user",
    "parts" => [["text" => $msg]]
];

$data = [
    "contents" => $contents,
    "generationConfig" => [
        "temperature" => 0.7,
        "maxOutputTokens" => 1000,
        "topP" => 0.9
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle curl errors
if ($response === false || !empty($curlError)) {
    echo json_encode(['error' => ['message' => 'Connection error: ' . $curlError]]);
    exit;
}

// Handle HTTP errors
if ($httpCode !== 200) {
    echo json_encode(['error' => ['message' => 'API returned error code: ' . $httpCode, 'response' => $response]]);
    exit;
}

// Return JSON to frontend
echo $response;
