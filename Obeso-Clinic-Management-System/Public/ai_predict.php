<?php
/**
 * AI Prediction Proxy
 * 
 * This endpoint acts as a secure bridge between the frontend (PHP/browser)
 * and the Python AI service running on Render (or local 127.0.0.1:8000).
 * 
 * Configuration:
 * - For local testing: AI_API_URL = 'http://127.0.0.1:8000/predict'
 * - For production (Render): AI_API_URL = 'https://your-render-app.onrender.com/predict'
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// ────────────────────────────────────────────────
// Configuration (update for production)
// ────────────────────────────────────────────────
define('AI_API_URL', getenv('AI_API_URL') ?: 'http://127.0.0.1:8000/predict');
define('AI_TIMEOUT', 15); // seconds

// ────────────────────────────────────────────────
// Validate request method
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ────────────────────────────────────────────────
// Get and validate input
// ────────────────────────────────────────────────
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

// ────────────────────────────────────────────────
// Forward to Python AI using cURL
// ────────────────────────────────────────────────
$ch = curl_init(AI_API_URL);

if (!$ch) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL initialization failed']);
    exit;
}

// Configure cURL
curl_setopt_array($ch, [
    CURLOPT_POST           => 1,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => AI_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($data),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

// Execute request
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// ────────────────────────────────────────────────
// Handle response
// ────────────────────────────────────────────────
if ($error) {
    error_log("AI API Error: $error (URL: " . AI_API_URL . ")");
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

// Pass through the AI response with proper status code
http_response_code($http_code);
echo $response;
