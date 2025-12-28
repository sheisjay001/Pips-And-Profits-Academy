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
if (empty($input) && php_sapi_name() === 'cli') {
    $input = file_get_contents("php://stdin");
}
$data = json_decode($input);

if (!isset($data->action)) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit;
}

$action = $data->action;

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

    $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
    if ($stmt->execute([$name, $email, $password_hash, $role])) {
        echo json_encode(['success' => true, 'message' => 'Registration successful']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Registration failed']);
    }
} elseif ($action === 'login') {
    $email = $data->email;
    $password = $data->password;

    $stmt = $conn->prepare("SELECT id, name, email, password_hash, role, created_at FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        unset($user['password_hash']); // Don't send password hash back
        
        // Add avatar field (mock for now, or could be in DB)
        $user['avatar'] = ''; 
        
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
} elseif ($action === 'update_profile') {
    $id = $data->id;
    $name = $data->name;
    $email = $data->email;
    $bio = $data->bio ?? '';

    // Update query
    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
    if ($stmt->execute([$name, $email, $id])) {
         // Return updated user data
         echo json_encode(['success' => true, 'message' => 'Profile updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Update failed']);
    }
} elseif ($action === 'forgot_password') {
    // In a real application, you would generate a token and email it.
    // For this prototype, we'll just return success to simulate the flow.
    // We do NOT confirm if the email exists or not to prevent user enumeration.
    echo json_encode(['success' => true, 'message' => 'Reset link sent']);
}
?>