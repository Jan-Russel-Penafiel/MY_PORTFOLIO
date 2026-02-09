<?php
// chat.php - Handles both chat API and TTS proxy

// ========== TTS PROXY HANDLER ==========
// Handle TTS proxy requests (GET with 'text' parameter)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['text'])) {
    header('Content-Type: audio/mpeg');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours

    // Get text and language from query parameters
    $text = isset($_GET['text']) ? $_GET['text'] : '';
    $lang = isset($_GET['lang']) ? $_GET['lang'] : 'en';

    // Validate and sanitize
    $text = trim($text);
    $lang = preg_replace('/[^a-z-]/', '', strtolower($lang));

    if (empty($text)) {
        http_response_code(400);
        echo "Error: No text provided";
        exit;
    }

    // Limit text length to prevent abuse
    if (strlen($text) > 200) {
        $text = substr($text, 0, 200);
    }

    // Build Google Translate TTS URL
    $url = 'https://translate.google.com/translate_tts?ie=UTF-8'
         . '&q=' . urlencode($text)
         . '&tl=' . urlencode($lang)
         . '&ttsspeed=3.0'    // ← Add this line (0.24-3.0, default is 1.0)
        . '&client=tw-ob';

    // Fetch audio with proper headers to mimic browser request
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n" .
                        "Accept: */*\r\n" .
                        "Referer: https://translate.google.com/\r\n"
        ]
    ];

    $context = stream_context_create($options);
    $audio = @file_get_contents($url, false, $context);

    if ($audio === false) {
        http_response_code(500);
        echo "Error: Failed to fetch audio";
        exit;
    }

    echo $audio;
    exit;
}

// ========== CHAT API HANDLER ==========
// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests for chat
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
$defaultModel = $config['gemini']['defaultModel'] ?? 'gemini-2.5-flash';
$models = $config['gemini']['models'] ?? [$defaultModel];
$cacheConfig = $config['gemini']['cache'] ?? ['enabled' => true, 'ttlSeconds' => 3600, 'maxEntries' => 200, 'directory' => 'cache'];

// ========== CACHE MANAGEMENT ==========
$cacheDir = __DIR__ . '/' . ($cacheConfig['directory'] ?? 'cache');
$cacheEnabled = $cacheConfig['enabled'] ?? true;
$cacheTTL = $cacheConfig['ttlSeconds'] ?? 3600;
$cacheMaxEntries = $cacheConfig['maxEntries'] ?? 200;

// Create cache directory if needed
if ($cacheEnabled && !is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Generate cache key from message + recent history context
function getCacheKey($msg, $history) {
    $contextParts = array_slice($history, -2);
    $contextStr = '';
    foreach ($contextParts as $item) {
        $contextStr .= ($item['role'] ?? '') . ':' . ($item['content'] ?? '') . '|';
    }
    return md5(strtolower(trim($msg)) . '|' . $contextStr);
}

// Read from cache
function cacheGet($cacheDir, $cacheKey, $cacheTTL) {
    $file = $cacheDir . '/' . $cacheKey . '.json';
    if (!file_exists($file)) return null;
    
    $data = json_decode(file_get_contents($file), true);
    if (!$data) return null;
    
    // Check TTL
    if (time() - ($data['timestamp'] ?? 0) > $cacheTTL) {
        @unlink($file);
        return null;
    }
    
    return $data;
}

// Write to cache
function cachePut($cacheDir, $cacheKey, $response, $model, $cacheMaxEntries) {
    $file = $cacheDir . '/' . $cacheKey . '.json';
    $data = [
        'timestamp' => time(),
        'model' => $model,
        'response' => $response
    ];
    @file_put_contents($file, json_encode($data));
    
    // Prune old entries if over limit
    cacheCleanup($cacheDir, $cacheMaxEntries);
}

// Cleanup oldest cache entries
function cacheCleanup($cacheDir, $maxEntries) {
    $files = glob($cacheDir . '/*.json');
    if (!$files || count($files) <= $maxEntries) return;
    
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    $toRemove = count($files) - $maxEntries;
    for ($i = 0; $i < $toRemove; $i++) {
        @unlink($files[$i]);
    }
}

// ========== MODEL HEALTH TRACKING ==========
$modelStatusFile = $cacheDir . '/_model_status.json';

function getModelStatus($statusFile) {
    if (!file_exists($statusFile)) return [];
    $data = json_decode(file_get_contents($statusFile), true);
    return is_array($data) ? $data : [];
}

function updateModelStatus($statusFile, $model, $success, $httpCode = 200) {
    $status = getModelStatus($statusFile);
    
    if (!isset($status[$model])) {
        $status[$model] = ['fails' => 0, 'lastFail' => 0, 'lastSuccess' => 0, 'cooldownUntil' => 0];
    }
    
    if ($success) {
        $status[$model]['fails'] = 0;
        $status[$model]['lastSuccess'] = time();
        $status[$model]['cooldownUntil'] = 0;
    } else {
        $status[$model]['fails']++;
        $status[$model]['lastFail'] = time();
        $status[$model]['lastHttpCode'] = $httpCode;
        
        // Exponential cooldown: 30s, 60s, 120s, 240s, max 300s
        $cooldown = min(30 * pow(2, $status[$model]['fails'] - 1), 300);
        $status[$model]['cooldownUntil'] = time() + $cooldown;
    }
    
    @file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
    return $status;
}

// Get ordered model list (healthy first, skip models in cooldown)
function getOrderedModels($models, $defaultModel, $statusFile, $preferredModel = null) {
    $status = getModelStatus($statusFile);
    $now = time();
    
    $primary = $preferredModel ?: $defaultModel;
    
    $ordered = [$primary];
    foreach ($models as $m) {
        if ($m !== $primary && !in_array($m, $ordered)) {
            $ordered[] = $m;
        }
    }
    
    $available = [];
    $cooldownModels = [];
    
    foreach ($ordered as $m) {
        $cooldownUntil = $status[$m]['cooldownUntil'] ?? 0;
        if ($cooldownUntil <= $now) {
            $available[] = $m;
        } else {
            $cooldownModels[] = $m;
        }
    }
    
    // If all models in cooldown, return all (try anyway)
    if (empty($available)) {
        return $ordered;
    }
    
    return array_merge($available, $cooldownModels);
}

// ========== PROCESS CHAT REQUEST ==========
$msg = $_POST['msg'] ?? '';
$systemPrompt = $_POST['systemPrompt'] ?? '';
$historyJson = $_POST['history'] ?? '[]';
$history = json_decode($historyJson, true) ?? [];
$preferredModel = $_POST['preferredModel'] ?? null;

// Check cache first
if ($cacheEnabled && !empty($msg)) {
    $cacheKey = getCacheKey($msg, $history);
    $cached = cacheGet($cacheDir, $cacheKey, $cacheTTL);
    
    if ($cached) {
        $cachedResponse = $cached['response'];
        if (is_string($cachedResponse)) {
            $cachedResponse = json_decode($cachedResponse, true);
        }
        if ($cachedResponse) {
            $cachedResponse['_cached'] = true;
            $cachedResponse['_model'] = $cached['model'] ?? 'unknown';
            echo json_encode($cachedResponse);
            exit;
        }
    }
}

// Build contents array
$contents = [];

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

foreach ($history as $historyItem) {
    $contents[] = [
        "role" => $historyItem['role'],
        "parts" => [["text" => $historyItem['content']]]
    ];
}

$contents[] = [
    "role" => "user",
    "parts" => [["text" => $msg]]
];

$data = [
    "contents" => $contents,
    "generationConfig" => [
        "temperature" => 1.0,
        "maxOutputTokens" => 229376,
        "topP" => 0.95
    ]
];

$jsonPayload = json_encode($data);

// ========== MULTI-MODEL FALLBACK WITH GRACEFUL ROTATION ==========
$orderedModels = getOrderedModels($models, $defaultModel, $modelStatusFile, $preferredModel);
$lastError = null;
$lastHttpCode = 0;
$usedModel = null;

foreach ($orderedModels as $model) {
    $url = "https://generativelanguage.googleapis.com/v1/models/$model:generateContent?key=$apiKey";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Connection error — try next model
    if ($response === false || !empty($curlError)) {
        updateModelStatus($modelStatusFile, $model, false, 0);
        $lastError = 'Connection error: ' . $curlError;
        continue;
    }
    
    // Rate limit (429) or server errors (500, 503) — try next model
    if ($httpCode === 429 || $httpCode >= 500) {
        updateModelStatus($modelStatusFile, $model, false, $httpCode);
        $lastError = "Model $model returned HTTP $httpCode";
        $lastHttpCode = $httpCode;
        continue;
    }
    
    // Auth error (403) — same key for all, no point continuing
    if ($httpCode === 403) {
        updateModelStatus($modelStatusFile, $model, false, $httpCode);
        echo json_encode([
            'error' => ['message' => 'Invalid API key. Please check your config.json.', 'code' => 403]
        ]);
        exit;
    }
    
    // Other client errors (400, 404) — model may not exist, try next
    if ($httpCode >= 400 && $httpCode < 500) {
        updateModelStatus($modelStatusFile, $model, false, $httpCode);
        $errorResponse = json_decode($response, true);
        $lastError = "Model $model error ($httpCode): " . ($errorResponse['error']['message'] ?? 'Unknown');
        $lastHttpCode = $httpCode;
        continue;
    }
    
    // Success (200)
    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            updateModelStatus($modelStatusFile, $model, true);
            $usedModel = $model;
            
            // Cache successful response
            if ($cacheEnabled && !empty($msg)) {
                $cacheKey = $cacheKey ?? getCacheKey($msg, $history);
                cachePut($cacheDir, $cacheKey, $response, $model, $cacheMaxEntries);
            }
            
            $responseData['_model'] = $model;
            $responseData['_cached'] = false;
            echo json_encode($responseData);
            exit;
        } else {
            updateModelStatus($modelStatusFile, $model, false, $httpCode);
            $lastError = "Model $model returned empty response";
            continue;
        }
    }
}

// All models failed
$errorMessage = 'All Gemini models are currently unavailable. ';
if ($lastHttpCode === 429) {
    $errorMessage .= 'Rate limit exceeded across all models. Please wait a moment and try again.';
} else {
    $errorMessage .= 'Last error: ' . ($lastError ?? 'Unknown error');
}

echo json_encode([
    'error' => [
        'message' => $errorMessage,
        'code' => $lastHttpCode ?: 500,
        'modelsAttempted' => $orderedModels
    ]
]);
