<?php
/**
 * Manual AI Model Retraining Endpoint
 * 
 * This page triggers a manual retraining of the AI model with the latest database records.
 * Access: /Public/admin_retrain.php
 * 
 * Configuration:
 * - For local testing: AI_RETRAIN_URL = 'http://127.0.0.1:8000/retrain'
 * - For production (Render): AI_RETRAIN_URL = 'https://your-render-app.onrender.com/retrain'
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ────────────────────────────────────────────────
// Security check (optional: add session validation)
// ────────────────────────────────────────────────
session_name('obeso_doctor');
session_start();

// Uncomment to restrict access:
// if (!isset($_SESSION['doc_id'])) {
//     die('Unauthorized access');
// }

// ────────────────────────────────────────────────
// Configuration
// ────────────────────────────────────────────────
define('AI_RETRAIN_URL', getenv('AI_RETRAIN_URL') ?: 'http://127.0.0.1:8000/retrain');
define('AI_TIMEOUT', 30); // Retraining can take longer

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Model Retraining</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; }
        .status-box { min-height: 100px; }
        .spinner-border { width: 3rem; height: 3rem; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">AI Model Retraining</h5>
                    </div>
                    <div class="card-body">
                        <div id="status" class="status-box alert alert-info">
                            <p>Ready to retrain the AI model with latest patient records.</p>
                            <button id="retrainBtn" class="btn btn-primary" onclick="startRetrain()">
                                Start Retraining
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const AI_RETRAIN_URL = '<?= htmlspecialchars(AI_RETRAIN_URL) ?>';
        
        async function startRetrain() {
            const btn = document.getElementById('retrainBtn');
            const status = document.getElementById('status');
            
            btn.disabled = true;
            status.innerHTML = `
                <div class="d-flex align-items-center">
                    <div class="spinner-border text-primary me-3" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div>
                        <p class="mb-0"><strong>Retraining in progress...</strong></p>
                        <small class="text-muted">This may take 1-2 minutes</small>
                    </div>
                </div>
            `;
            
            try {
                const response = await fetch('ai_retrain_proxy.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                });
                
                const data = await response.json();
                
                if (response.ok && data.success) {
                    status.className = 'status-box alert alert-success';
                    status.innerHTML = `
                        <h6>✅ Retraining Complete</h6>
                        <p><strong>Message:</strong> ${escapeHtml(data.message)}</p>
                        <p><strong>Active Features:</strong> ${escapeHtml((data.active_features || []).join(', '))}</p>
                        <button class="btn btn-success" onclick="location.reload()">Done</button>
                    `;
                } else {
                    status.className = 'status-box alert alert-danger';
                    status.innerHTML = `
                        <h6>❌ Retraining Failed</h6>
                        <p><strong>Error:</strong> ${escapeHtml(data.error || 'Unknown error')}</p>
                        <button class="btn btn-danger" onclick="startRetrain()" style="margin-top: 10px;">Try Again</button>
                    `;
                }
            } catch (err) {
                status.className = 'status-box alert alert-danger';
                status.innerHTML = `
                    <h6>❌ Connection Error</h6>
                    <p><strong>Error:</strong> ${escapeHtml(err.message)}</p>
                    <small>Make sure the AI service is running.</small><br>
                    <button class="btn btn-danger mt-2" onclick="startRetrain()">Try Again</button>
                `;
            }
            
            btn.disabled = false;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
