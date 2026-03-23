<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once 'session_config.php';
require_once 'db_connect.php';
require_once 'db_helper.php';

// Check admin access
function checkAdminAccess() {
    $userId = get_authenticated_user_id();
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
    
    global $conn;
    $roleCol = getRoleColumn($conn);
    $stmt = $conn->prepare("SELECT $roleCol as role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $isAdmin = $user && ($user['role'] == 1 || $user['role'] === 'admin');
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Admin access denied']);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    checkAdminAccess();
    
    try {
        $stmt = $conn->query("SELECT * FROM settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        echo json_encode(['success' => true, 'settings' => $settings]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

} elseif ($method === 'POST') {
    checkAdminAccess();
    validate_csrf();
    
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);
    $action = $data['action'] ?? '';

    if ($action === 'update_settings') {
        $updates = $data['settings'] ?? [];
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            
            foreach ($updates as $key => $value) {
                $stmt->execute([$key, $value, $value]);
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
?>