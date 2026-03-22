<?php
/**
 * Unified Session & CSRF Configuration
 * Optimized for both Local (XAMPP) and Serverless (Vercel) environments
 */

// Determine if we are using HTTPS
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Session configuration
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * CSRF Management (Double Submit Cookie Pattern)
 * 
 * We use both Session and Cookie to ensure reliability on Vercel.
 * On serverless, $_SESSION might be lost between requests, so we 
 * fallback to a persistent cookie for validation.
 */

// 1. Generate token if missing from session or cookie
if (!isset($_SESSION['csrf_token']) && !isset($_COOKIE['ppa_csrf_token'])) {
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $token = md5(uniqid((string)mt_rand(), true));
    }
    $_SESSION['csrf_token'] = $token;
} else {
    $token = $_SESSION['csrf_token'] ?? $_COOKIE['ppa_csrf_token'];
}

// 2. Always sync the token to a cookie (httponly=false so JS can read it)
// This is our "Stateless" backup for Vercel
if (!isset($_COOKIE['ppa_csrf_token']) || $_COOKIE['ppa_csrf_token'] !== $token) {
    setcookie('ppa_csrf_token', $token, [
        'expires' => time() + 3600 * 24, // 24 hours
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => false, // Allowed for JS
        'samesite' => 'Lax'
    ]);
}

/**
 * Validate CSRF Token
 * Compares X-CSRF-Token header against Session or Cookie backup
 */
function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        
        // 1. Try to find the expected token (Session first, then Cookie)
        $expectedToken = $_SESSION['csrf_token'] ?? $_COOKIE['ppa_csrf_token'] ?? null;
        
        if (!$expectedToken || !$headerToken || !hash_equals($expectedToken, $headerToken)) {
            // Log for debugging if needed
            // error_log("CSRF Failure - Header: $headerToken, Expected: $expectedToken");
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid CSRF token',
                'debug_info' => [
                    'has_header' => !empty($headerToken),
                    'has_expected' => !empty($expectedToken),
                    'session_active' => isset($_SESSION['user_id'])
                ]
            ]);
            exit;
        }
    }
}
?>
