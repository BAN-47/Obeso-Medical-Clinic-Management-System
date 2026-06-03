<?php
/**
 * AI Model Retrain Proxy
 * 
 * Forwards retrain requests to the Python AI service via cURL.
 * Configuration:
 * - For local: AI_RETRAIN_URL = 'http://127.0.0.1:8000/retrain'
 * - For production (Render): AI_RETRAIN_URL = 'https://your-render-app.onrender.com/retrain'
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// ────────────────────────────────────────────────
// Configuration
// ────────────────────────────────────────────────
define('AI_RETRAIN_URL', getenv('AI_RETRAIN_URL') ?: 'http://127.0.0.1:8000/retrain');
define('AI_TIMEOUT', 30);

// ────────────────────────────────────────────────
// Validate request
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ────────────────────────────────────────────────
// Call Python retrain endpoint via cURL
// ────────────────────────────────────────────────
$ch = curl_init(AI_RETRAIN_URL);

if (!$ch) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL initialization failed']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_POST           => 1,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => AI_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([]),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// ────────────────────────────────────────────────
// Handle response
// ────────────────────────────────────────────────
if ($error) {
    error_log("AI Retrain Error: $error (URL: " . AI_RETRAIN_URL . ")");
    http_response_code(503);
    echo json_encode([
        'error' => 'AI service unavailable',
        'details' => $error
    ]);
    exit;
}

if (!$response) {
    http_response_code(503);
    echo json_encode(['error' => 'AI service returned no response']);
    exit;
}

http_response_code($http_code);
echo $response;
