<?php
// Determine if we are using HTTPS
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps, // True if HTTPS, False otherwise
    'httponly' => true,
    'samesite' => $isHttps ? 'None' : 'Lax' // None for HTTPS (Cross-Site), Lax for HTTP (Same-Site/Localhost)
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure CSRF token is set
if (!isset($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = md5(uniqid((string)mt_rand(), true));
    }
}

// Function to validate CSRF token
function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $headerToken)) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
    }
}
?>
