<?php
session_start();

/* Unset all session variables */
$_SESSION = [];

/* Destroy session */
session_destroy();

/* Delete session cookie(s): default + role-specific */
$params = session_get_cookie_params();
$names = [session_name(), 'obeso_doctor', 'obeso_staff'];
foreach ($names as $sname) {
    // Remove cookie if present
    if (isset($_COOKIE[$sname])) {
        setcookie($sname, '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? false);
        unset($_COOKIE[$sname]);
    }
}

/* Prevent caching */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* Redirect */
header("Location: /index.php");
exit;
