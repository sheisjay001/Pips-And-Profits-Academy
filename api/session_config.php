<?php
/**
 * Unified Session & CSRF Configuration
 * Optimized for Serverless (Vercel) using Signed Cookie Persistence
 */

// Determine if we are using HTTPS
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Session configuration (Fallback for local dev)
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
 * GET SECURE USER ID
 * Checks both PHP Session and a Signed Secure Cookie (Stateless fallback)
 */
function get_authenticated_user_id() {
    // 1. Check traditional session
    if (isset($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }

    // 2. Fallback to Signed Cookie (for Vercel/Serverless)
    if (isset($_COOKIE['ppa_auth_session'])) {
        $data = $_COOKIE['ppa_auth_session'];
        $parts = explode('.', $data);
        
        if (count($parts) === 2) {
            $userId = $parts[0];
            $signature = $parts[1];
            
            // Verify signature using DB password as secret key
            $secret = getenv('DB_PASSWORD') ?: 'ppa_default_secret';
            $expectedSignature = hash_hmac('sha256', $userId, $secret);
            
            if (hash_equals($expectedSignature, $signature)) {
                // Restore to session for the rest of this request
                $_SESSION['user_id'] = $userId;
                return $userId;
            }
        }
    }
    
    return null;
}

/**
 * SET SECURE USER SESSION
 * Stores ID in session and a signed persistent cookie
 */
function set_authenticated_user_id($userId) {
    $_SESSION['user_id'] = $userId;
    
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    $secret = getenv('DB_PASSWORD') ?: 'ppa_default_secret';
    $signature = hash_hmac('sha256', $userId, $secret);
    $cookieValue = $userId . '.' . $signature;
    
    setcookie('ppa_auth_session', $cookieValue, [
        'expires' => time() + (3600 * 24 * 7), // 7 days
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * CSRF Management (Double Submit Cookie Pattern)
 */
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

if (!isset($_COOKIE['ppa_csrf_token']) || $_COOKIE['ppa_csrf_token'] !== $token) {
    setcookie('ppa_csrf_token', $token, [
        'expires' => time() + 3600 * 24, 
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => false, 
        'samesite' => 'Lax'
    ]);
}

function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expectedToken = $_SESSION['csrf_token'] ?? $_COOKIE['ppa_csrf_token'] ?? null;
        
        if (!$expectedToken || !$headerToken || !hash_equals($expectedToken, $headerToken)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
    }
}
?>
