<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

if ($action === 'register') {
    $name = $data->name;
    $email = $data->email;
    $password = $data->password;

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

    $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, role, plan) VALUES (?, ?, ?, ?, ?)");
    if ($stmt->execute([$name, $email, $password_hash, $role, $plan])) {
        echo json_encode(['success' => true, 'message' => 'Registration successful']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Registration failed']);
    }
} elseif ($action === 'login') {
    $email = $data->email;
    $password = $data->password;

    $stmt = $conn->prepare("SELECT id, name, email, password_hash, role, plan, profile_picture, bio, created_at FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        unset($user['password_hash']); // Don't send password hash back
        
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
} elseif ($action === 'update_profile') {
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
    if (isset($_FILES['avatar']) && isset($_POST['id'])) {
        $userId = $_POST['id'];
        $file = $_FILES['avatar'];
        
        $uploadDir = '../uploads/avatars/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = time() . '_' . basename($file['name']);
        $targetFile = $uploadDir . $fileName;
        $dbPath = 'uploads/avatars/' . $fileName; // Path relative to web root

        // Validate image
        $check = getimagesize($file['tmp_name']);
        if ($check === false) {
             echo json_encode(['success' => false, 'message' => 'File is not an image']);
             exit;
        }

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            // Update DB
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
        @mail($email, $subject, $message, "From: no-reply@pips-and-profits-academy");
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
}
?>
