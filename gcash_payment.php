<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'ok' => false,
        'error' => 'Method not allowed. Use POST.',
    ]);
}

if (!extension_loaded('curl')) {
    respond(500, [
        'ok' => false,
        'error' => 'cURL extension is required on the server.',
    ]);
}

// Load local .env values for development (server env vars still take priority).
loadDotEnv(__DIR__ . '/.env');

$action = strtolower((string) ($_GET['action'] ?? 'create'));
$input = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(400, [
        'ok' => false,
        'error' => 'Invalid JSON payload.',
    ]);
}

$secretKey = envValue('PAYMONGO_SECRET_KEY');
$environment = strtolower(envValue('PAYMONGO_ENV') ?: 'test');
$apiBase = 'https://api.paymongo.com/v1';

if ($secretKey === '') {
    respond(500, [
        'ok' => false,
        'error' => 'Missing server payment configuration. Set PAYMONGO_SECRET_KEY.',
    ]);
}

if ($action === 'create') {
    createCheckoutSession($input, $apiBase, $secretKey, $environment);
}

if ($action === 'status') {
    fetchCheckoutStatus($input, $apiBase, $secretKey);
}

respond(400, [
    'ok' => false,
    'error' => 'Unsupported action.',
]);

function createCheckoutSession(array $payload, string $apiBase, string $secretKey, string $environment): void
{
    $projectTitle = trim((string) ($payload['projectTitle'] ?? ''));
    $projectReference = trim((string) ($payload['projectReference'] ?? ''));
    $clientName = trim((string) ($payload['clientName'] ?? ''));
    $clientEmail = trim((string) ($payload['clientEmail'] ?? ''));

    $projectTitle = sanitizeText($projectTitle, 120);
    $projectReference = sanitizeReference($projectReference, 64);
    $clientName = sanitizeText($clientName, 80);
    $clientEmail = filter_var($clientEmail, FILTER_VALIDATE_EMAIL) ? $clientEmail : '';

    if ($projectTitle === '') {
        respond(422, [
            'ok' => false,
            'error' => 'Project title is required.',
        ]);
    }

    // Auto-generate reference if not provided
    if ($projectReference === '') {
        $projectReference = 'PRJ-' . time();
    }

    if ($clientEmail === '') {
        respond(422, [
            'ok' => false,
            'error' => 'Valid client email is required for GCash payment.',
        ]);
    }

    $amountMinor = parsePhpAmountToMinor($payload['amount'] ?? null);

    if ($amountMinor < 100) {
        respond(422, [
            'ok' => false,
            'error' => 'Minimum amount is 1.00 PHP.',
        ]);
    }

    // Use PayMongo Source API for GCash redirect checkout
    $requestBody = [
        'data' => [
            'attributes' => [
                'amount' => $amountMinor,
                'currency' => 'PHP',
                'redirect' => [
                    'success' => buildReturnUrl($projectReference, 'success'),
                    'failed' => buildReturnUrl($projectReference, 'failed'),
                ],
                'statement_descriptor' => substr($projectTitle, 0, 20),
                'type' => 'gcash',
                'billing' => [
                    'name' => $clientName,
                    'email' => $clientEmail,
                ],
            ],
        ],
    ];

    [$statusCode, $responseBody] = paymongoRequest(
        $apiBase . '/sources',
        $secretKey,
        'POST',
        $requestBody
    );

    $responseData = json_decode($responseBody, true);
    if (!is_array($responseData)) {
        $responseData = [];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        respond(502, [
            'ok' => false,
            'error' => paymongoErrorMessage($responseData, 'Failed to create payment link.'),
            'statusCode' => $statusCode,
        ]);
    }

    // PayMongo Source API returns checkout URL in data.attributes.redirect.checkout_url
    $attributes = (array) ($responseData['data']['attributes'] ?? []);
    $redirect = (array) ($attributes['redirect'] ?? []);
    $checkoutUrl = (string) ($redirect['checkout_url'] ?? '');
    $sourceId = (string) ($responseData['data']['id'] ?? '');

    if ($checkoutUrl === '') {
        respond(502, [
            'ok' => false,
            'error' => 'Payment link response was incomplete.',
        ]);
    }

    respond(200, [
        'ok' => true,
        'checkoutUrl' => $checkoutUrl,
        'sessionId' => $sourceId,
        'reference' => $projectReference,
        'mode' => 'redirect',
    ]);
}

function fetchCheckoutStatus(array $payload, string $apiBase, string $secretKey): void
{
    $sourceId = trim((string) ($payload['sessionId'] ?? ''));

    if ($sourceId === '') {
        respond(422, [
            'ok' => false,
            'error' => 'sessionId is required.',
        ]);
    }

    $url = $apiBase . '/sources/' . rawurlencode($sourceId);

    [$statusCode, $responseBody] = paymongoRequest($url, $secretKey, 'GET');

    $responseData = json_decode($responseBody, true);
    if (!is_array($responseData)) {
        $responseData = [];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        respond(502, [
            'ok' => false,
            'error' => paymongoErrorMessage($responseData, 'Failed to retrieve payment status.'),
            'statusCode' => $statusCode,
        ]);
    }

    $attributes = (array) ($responseData['data']['attributes'] ?? []);
    $status = (string) ($attributes['status'] ?? '');

    respond(200, [
        'ok' => true,
        'status' => $status,
        'resultCode' => $status === 'paid' ? 'authorised' : $status,
        'reference' => (string) ($attributes['reference_number'] ?? ''),
    ]);
}

function paymongoRequest(string $url, string $secretKey, string $method, ?array $payload = null): array
{
    $curl = curl_init($url);

    if ($curl === false) {
        respond(500, [
            'ok' => false,
            'error' => 'Could not initialize HTTP client.',
        ]);
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    // PayMongo uses Basic Auth with secret key as username and empty password
    $authHeader = 'Authorization: Basic ' . base64_encode($secretKey . ':');
    $headers[] = $authHeader;

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];

    if ($method === 'POST' && $payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            respond(500, [
                'ok' => false,
                'error' => 'Failed to encode payment request payload.',
            ]);
        }
        $options[CURLOPT_POSTFIELDS] = $encoded;
    }

    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($responseBody === false) {
        $error = curl_error($curl);
        curl_close($curl);
        respond(502, [
            'ok' => false,
            'error' => 'Payment provider request failed: ' . $error,
        ]);
    }

    curl_close($curl);

    return [$statusCode, (string) $responseBody];
}

function buildReturnUrl(string $projectReference, string $status = 'success'): string
{
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $basePath = dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = str_replace('\\', '/', $basePath);
    $basePath = rtrim($basePath, '/');

    $indexPath = ($basePath === '' ? '' : $basePath) . '/index.html';

    return $scheme
        . '://'
        . $host
        . $indexPath
        . '?payment_ref='
        . rawurlencode($projectReference)
        . '&payment_status='
        . $status
        . '#payment';
}

function parsePhpAmountToMinor(mixed $amount): int
{
    if (is_string($amount)) {
        $amount = trim($amount);
    }

    if (!is_numeric($amount)) {
        return 0;
    }

    $amountFloat = (float) $amount;
    if ($amountFloat <= 0) {
        return 0;
    }

    return (int) round($amountFloat * 100, 0, PHP_ROUND_HALF_UP);
}

function sanitizeText(string $value, int $maxLength): string
{
    $clean = preg_replace('/[^a-zA-Z0-9 .,:()\-]/', '', $value);
    if ($clean === null) {
        return '';
    }

    return substr(trim($clean), 0, $maxLength);
}

function sanitizeReference(string $value, int $maxLength): string
{
    $clean = preg_replace('/[^a-zA-Z0-9._:-]/', '-', $value);
    if ($clean === null) {
        return '';
    }

    $clean = trim($clean, '-');
    return substr($clean, 0, $maxLength);
}

function paymongoErrorMessage(array $response, string $fallback): string
{
    // PayMongo returns errors in data.errors array
    $errors = $response['errors'] ?? [];
    if (is_array($errors) && count($errors) > 0) {
        $firstError = $errors[0] ?? [];
        $message = $firstError['detail'] ?? $firstError['message'] ?? '';
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }
    }

    $candidates = [
        $response['message'] ?? null,
        $response['detail'] ?? null,
        $response['error'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return $fallback;
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

        // Do not overwrite already-present environment variables.
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

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

