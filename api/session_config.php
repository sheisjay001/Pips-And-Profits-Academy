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
    'samesite' => 'Lax' // Use 'Lax' for same-domain requests (fixes Vercel cookie issues)
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
        
        // Debug: Log token mismatch if it occurs
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $headerToken)) {
            // Optional: Log mismatch to help identify session loss issues
            // error_log("CSRF Mismatch: SessionToken=" . ($_SESSION['csrf_token'] ?? 'NULL') . ", HeaderToken=" . ($headerToken ?: 'NULL'));
            
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid CSRF token',
                'session_active' => isset($_SESSION['user_id']),
                'token_mismatch' => true
            ]);
            exit;
        }
    }
}
?>
