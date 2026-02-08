<?php
// chat.php
header('Content-Type: application/json');

$apiKey = "AIzaSyASxDPQbVLIrKuLLSl5yTJ-hJhuiFP_mzY"; // Secure API key on server-side
$model = "gemini-2.5-flash";  // Using Gemini Pro - universally supported stable model

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

$response = curl_exec($ch);
curl_close($ch);

// Return JSON to frontend
echo $response;
