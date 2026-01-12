<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self' https: data:; img-src 'self' https: data:; script-src 'self' https:; style-src 'self' https: 'unsafe-inline'; connect-src 'self' https:; frame-ancestors 'self';");

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// For CLI testing (allows simulation of request method)
if (php_sapi_name() === 'cli') {
    if (!isset($_SERVER['REQUEST_METHOD'])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connect.php';

// CLI Support: Read from stdin if php://input is empty (common in PowerShell pipes)
$input = file_get_contents("php://input");
// Handle Multipart Form Data (File Uploads)
if (isset($_POST['action'])) {
    $data = (object) $_POST;
} elseif (empty($input) && php_sapi_name() === 'cli') {
    $input = file_get_contents("php://stdin");
    $data = json_decode($input);
} else {
    $data = json_decode($input);
}

if (!isset($data->action)) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit;
}

$action = $data->action;

// Helper: Base URL for reset links
$baseUrl = getenv('BASE_URL') ?: (
    isset($_SERVER['HTTP_HOST']) 
    ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'])
    : 'http://localhost:8000'
);

if (!isset($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = md5(uniqid((string)mt_rand(), true));
    }
}
function require_csrf($action) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (in_array($action, ['login','csrf'])) return;
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $header)) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
    }
}

function send_email($to, $subject, $textBody, $htmlBody = null) {
    $host = getenv('BREVO_SMTP_HOST') ?: getenv('SMTP_HOST');
    $port = (int)(getenv('BREVO_SMTP_PORT') ?: getenv('SMTP_PORT') ?: 0);
    $user = getenv('BREVO_SMTP_USER') ?: getenv('SMTP_USER');
    $pass = getenv('BREVO_SMTP_PASS') ?: getenv('SMTP_PASS');
    $from = getenv('SMTP_FROM') ?: 'no-reply@pipsandprofitsacademy.com';
    $fromName = getenv('SMTP_FROM_NAME') ?: 'Pips & Profit Academy';
    $useSmtp = $host && $port && $user && $pass;

    $body = $htmlBody !== null ? $htmlBody : $textBody;
    $isHtml = $htmlBody !== null;

    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $from . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8';
    $headersText = implode("\r\n", $headers);

    if (!$useSmtp) {
        return @mail($to, $subject, $body, $headersText);
    }

    $fp = @fsockopen($host, $port, $errno, $errstr, 15);
    if (!$fp) return false;

    $read = function() use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (preg_match('/^\d{3} /', $line)) break;
        }
        return $data;
    };
    $expect = function($codes) use ($read) {
        $resp = $read();
        $code = (int)substr($resp, 0, 3);
        return in_array($code, (array)$codes, true);
    };
    $send = function($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    if (!$expect([220])) { fclose($fp); return false; }
    $send('EHLO ' . (getenv('SMTP_HELO') ?: 'localhost'));
    if (!$expect([250])) { fclose($fp); return false; }

    $send('STARTTLS');
    if (!$expect([220])) { fclose($fp); return false; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }

    $send('EHLO ' . (getenv('SMTP_HELO') ?: 'localhost'));
    if (!$expect([250])) { fclose($fp); return false; }

    $send('AUTH LOGIN');
    if (!$expect([334])) { fclose($fp); return false; }
    $send(base64_encode($user));
    if (!$expect([334])) { fclose($fp); return false; }
    $send(base64_encode($pass));
    if (!$expect([235])) { fclose($fp); return false; }

    $send('MAIL FROM:<' . $from . '>');
    if (!$expect([250])) { fclose($fp); return false; }
    $send('RCPT TO:<' . $to . '>');
    if (!$expect([250, 251])) { fclose($fp); return false; }
    $send('DATA');
    if (!$expect([354])) { fclose($fp); return false; }

    $msg = 'To: <' . $to . ">\r\n" .
           'Subject: ' . $subject . "\r\n" .
           $headersText . "\r\n\r\n" .
           $body;

    $msg = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $msg);
    $msg = str_replace("\n", "\r\n", $msg);

    fwrite($fp, $msg . "\r\n.\r\n");
    if (!$expect([250])) { fclose($fp); return false; }
    $send('QUIT');
    fclose($fp);
    return true;
}

if ($action === 'csrf') {
    echo json_encode(['token' => $_SESSION['csrf_token']]);
    exit;
}

if ($action === 'register') {
    $name = $data->name;
    $email = $data->email;
    $password = $data->password;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password too short']);
        exit;
    }

    // Check if email exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        exit;
    }

    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'user'; // Default role
    $plan = 'free'; // Default plan

    // Create email verification token
    try {
        $verification_token = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $verification_token = md5(uniqid((string)mt_rand(), true));
    }
    $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, role, plan, email_verified, verification_token, verification_sent_at) VALUES (?, ?, ?, ?, ?, 0, ?, NOW())");
    if ($stmt->execute([$name, $email, $password_hash, $role, $plan, $verification_token])) {
        $verifyLink = $baseUrl . "/verify_email.html?token=" . urlencode($verification_token) . "&email=" . urlencode($email);
        send_email($email, "Verify your email - Pips & Profit Academy", "Please verify your email by visiting: " . $verifyLink);
        echo json_encode(['success' => true, 'message' => 'Registration successful. Please verify your email.', 'verify_link' => $verifyLink]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Registration failed']);
    }
} elseif ($action === 'login') {
    $now = time();
    $bucket = $_SESSION['login_bucket'] ?? ['count' => 0, 'reset' => $now + 900];
    if ($now > ($bucket['reset'] ?? $now)) {
        $bucket = ['count' => 0, 'reset' => $now + 900];
    }
    if ($bucket['count'] >= 5) {
        echo json_encode(['success' => false, 'message' => 'Too many attempts. Please wait and try again.']);
        exit;
    }
    $bucket['count']++;
    $_SESSION['login_bucket'] = $bucket;

    $email = $data->email;
    $password = $data->password;

    $stmt = $conn->prepare("SELECT id, name, email, password_hash, role, plan, profile_picture, bio, created_at, COALESCE(email_verified, 0) as email_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        unset($user['password_hash']); // Don't send password hash back
        $_SESSION['user_id'] = $user['id'];
        
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
} elseif ($action === 'google_login') {
    $idToken = $data->id_token ?? '';
    if (!$idToken) {
        echo json_encode(['success' => false, 'message' => 'Missing token']);
        exit;
    }
    $clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $resp = @file_get_contents($verifyUrl);
    if ($resp === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to verify token']);
        exit;
    }
    $payload = json_decode($resp, true);
    if (!is_array($payload) || !isset($payload['aud'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid token payload']);
        exit;
    }
    if (!$clientId || $payload['aud'] !== $clientId) {
        echo json_encode(['success' => false, 'message' => 'Server not configured for Google Sign-In']);
        exit;
    }
    $email = $payload['email'] ?? '';
    $emailVerified = ($payload['email_verified'] ?? 'false') === 'true';
    $name = $payload['name'] ?? '';
    $picture = $payload['picture'] ?? null;
    if (!$email || !$emailVerified) {
        echo json_encode(['success' => false, 'message' => 'Email not verified']);
        exit;
    }
    $stmt = $conn->prepare("SELECT id, name, email, role, plan, profile_picture, bio, created_at, COALESCE(email_verified, 0) as email_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        if ($picture && empty($user['profile_picture'])) {
            $up = $conn->prepare("UPDATE users SET profile_picture = ?, email_verified = 1 WHERE id = ?");
            try { $up->execute([$picture, $user['id']]); } catch (Exception $e) {}
            $stmt = $conn->prepare("SELECT id, name, email, role, plan, profile_picture, bio, created_at, COALESCE(email_verified, 0) as email_verified FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $up = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
            try { $up->execute([$user['id']]); } catch (Exception $e) {}
        }
    } else {
        $ins = $conn->prepare("INSERT INTO users (name, email, role, plan, email_verified, profile_picture, created_at) VALUES (?, ?, 'user', 'free', 1, ?, NOW())");
        $ins->execute([$name ?: $email, $email, $picture]);
        $stmt = $conn->prepare("SELECT id, name, email, role, plan, profile_picture, bio, created_at, 1 as email_verified FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Login failed']);
    }
} elseif ($action === 'update_profile') {
    require_csrf($action);
    $id = $data->id;
    $name = $data->name;
    $email = $data->email;
    $bio = $data->bio ?? '';
    $profile_picture = $data->profile_picture ?? null;

    if ($profile_picture) {
        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, bio = ?, profile_picture = ? WHERE id = ?");
        $params = [$name, $email, $bio, $profile_picture, $id];
    } else {
        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, bio = ? WHERE id = ?");
        $params = [$name, $email, $bio, $id];
    }

    if ($stmt->execute($params)) {
         // Fetch updated user
         $stmt = $conn->prepare("SELECT id, name, email, role, plan, profile_picture, bio, created_at FROM users WHERE id = ?");
         $stmt->execute([$id]);
         $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

         echo json_encode(['success' => true, 'message' => 'Profile updated', 'user' => $updatedUser]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Update failed']);
    }
} elseif ($action === 'upload_avatar') {
    require_csrf($action);
    if (isset($_FILES['avatar']) && isset($_POST['id'])) {
        $userId = $_POST['id'];
        $file = $_FILES['avatar'];
        
        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Validate size (max 2MB)
        if (!isset($file['size']) || $file['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large. Max 2MB']);
            exit;
        }

        // Validate MIME type using finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        if (!isset($allowed[$mime])) {
            echo json_encode(['success' => false, 'message' => 'Invalid image type']);
            exit;
        }

        // Generate a random safe filename
        try {
            $ext = $allowed[$mime];
            $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
        } catch (Exception $e) {
            $fileName = time() . '_' . uniqid() . '.' . $allowed[$mime];
        }
        $targetFile = $uploadDir . $fileName;
        $dbPath = 'uploads/avatars/' . $fileName; // Path relative to web root

        // Optional: Basic image sanity check
        $check = @getimagesize($file['tmp_name']);
        if ($check === false) {
            echo json_encode(['success' => false, 'message' => 'File is not a valid image']);
            exit;
        }

        $source = $file['tmp_name'];
        $img = null;
        if ($mime === 'image/jpeg') {
            $img = imagecreatefromjpeg($source);
        } elseif ($mime === 'image/png') {
            $img = imagecreatefrompng($source);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $img = imagecreatefromwebp($source);
        }
        $saved = false;
        if ($img) {
            if ($mime === 'image/jpeg') {
                $saved = imagejpeg($img, $targetFile, 85);
            } elseif ($mime === 'image/png') {
                $saved = imagepng($img, $targetFile, 8);
            } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
                $saved = imagewebp($img, $targetFile, 80);
            }
            imagedestroy($img);
        } else {
            $saved = move_uploaded_file($source, $targetFile);
        }

        if ($saved) {
            try {
                $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->execute([$dbPath, $userId]);
                
                echo json_encode(['success' => true, 'message' => 'Avatar uploaded', 'url' => $dbPath]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No file or ID provided']);
    }
} elseif ($action === 'forgot_password') {
    $email = $data->email ?? '';
    // Find user by email (do not reveal existence)
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Create token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
        $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $token, $expires]);

        // Send email (best-effort)
        $resetLink = $baseUrl . "/reset_password.html?token=" . urlencode($token);
        $subject = "Password Reset - Pips and Profit Academy";
        $message = "Hello " . ($user['name'] ?: 'User') . ",\n\n"
                 . "We received a request to reset your password.\n"
                 . "Use the link below to set a new password. This link expires in 1 hour.\n\n"
                 . $resetLink . "\n\n"
                 . "If you did not request this, please ignore this email.";
        send_email($email, $subject, $message);
    }

    // Always return success to avoid user enumeration
    echo json_encode(['success' => true, 'message' => 'If the email exists, a reset link was sent']);
} elseif ($action === 'reset_password') {
    $token = $data->token ?? '';
    $newPassword = $data->password ?? '';
    if (!$token || !$newPassword) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    // Validate token
    $stmt = $conn->prepare("SELECT pr.user_id, pr.expires_at FROM password_resets pr WHERE pr.token = ?");
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reset) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
        exit;
    }
    if (strtotime($reset['expires_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'Token expired']);
        exit;
    }
    // Update password
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$hash, $reset['user_id']]);
    // Remove used token(s)
    $conn->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$reset['user_id']]);
    echo json_encode(['success' => true, 'message' => 'Password updated']);
} elseif ($action === 'get_users') {
    // Fetch all users (admin only ideally)
    $stmt = $conn->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($users);
} elseif ($action === 'delete_user') {
    require_csrf($action);
    $id = $data->id;
    // Check if user exists and is not admin (simple check)
    // Ideally check if requester is admin
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    if ($stmt->execute([$id])) {
        echo json_encode(['success' => true, 'message' => 'User deleted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Deletion failed']);
    }
} elseif ($action === 'update_plan') {
    require_csrf($action);
    $id = $data->id ?? null;
    $plan = $data->plan ?? '';
    $allowed = ['free', 'pro', 'elite'];
    if (!$id || !in_array($plan, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid plan update']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE users SET plan = ? WHERE id = ?");
    if ($stmt->execute([$plan, $id])) {
        echo json_encode(['success' => true, 'message' => 'Plan updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update plan']);
    }
} elseif ($action === 'verify_email') {
    $email = $data->email ?? '';
    $token = $data->token ?? '';
    if (!$email || !$token) {
        echo json_encode(['success' => false, 'message' => 'Invalid verification request']);
        exit;
    }
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND verification_token = ?");
    $stmt->execute([$email, $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?");
    if ($stmt->execute([$user['id']])) {
        echo json_encode(['success' => true, 'message' => 'Email verified']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Verification failed']);
    }
} elseif ($action === 'resend_verification') {
    require_csrf($action);
    $email = $data->email ?? '';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email']);
        exit;
    }
    $stmt = $conn->prepare("SELECT id, email_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    if ((int)($user['email_verified'] ?? 0) === 1) {
        echo json_encode(['success' => false, 'message' => 'Email already verified']);
        exit;
    }
    try {
        $verification_token = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $verification_token = md5(uniqid((string)mt_rand(), true));
    }
    $stmt = $conn->prepare("UPDATE users SET verification_token = ?, verification_sent_at = NOW() WHERE id = ?");
    if ($stmt->execute([$verification_token, $user['id']])) {
        $verifyLink = $baseUrl . "/verify_email.html?token=" . urlencode($verification_token) . "&email=" . urlencode($email);
        send_email($email, "Verify your email - Pips & Profit Academy", "Please verify your email by visiting: " . $verifyLink);
        echo json_encode(['success' => true, 'message' => 'Verification email resent', 'verify_link' => $verifyLink]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to resend verification']);
    }
} elseif ($action === 'me') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
    $stmt = $conn->prepare("SELECT id, name, email, role, plan, profile_picture, bio, COALESCE(email_verified, 0) as email_verified, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        echo json_encode(['success' => true, 'user' => $u]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
}
?>
