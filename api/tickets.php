<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Helper to get POST data
function getPostData() {
    $input = file_get_contents("php://input");
    return json_decode($input, true);
}

if ($method === 'GET') {
    // Get tickets
    // Optional: filter by user_id
    $userId = $_GET['user_id'] ?? null;
    
    try {
        if ($userId) {
            $stmt = $conn->prepare("
                SELECT t.*, u.name as userName, u.email as userEmail 
                FROM tickets t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.user_id = ? 
                ORDER BY t.created_at DESC
            ");
            $stmt->execute([$userId]);
        } else {
            // Admin: Get all tickets
            $stmt = $conn->query("
                SELECT t.*, u.name as userName, u.email as userEmail 
                FROM tickets t 
                JOIN users u ON t.user_id = u.id 
                ORDER BY t.created_at DESC
            ");
        }
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format dates
        foreach ($tickets as &$ticket) {
            $ticket['date'] = $ticket['created_at']; // Alias for frontend compatibility
        }
        
        echo json_encode($tickets);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }

} elseif ($method === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    $data = getPostData();
    $action = $data['action'] ?? '';

    if ($action === 'create') {
        $userId = $data['user_id'] ?? null;
        $subject = $data['subject'] ?? '';
        $message = $data['message'] ?? '';
        
        if (!$userId || !$subject || !$message) {
            echo json_encode(['success' => false, 'message' => 'Missing fields']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("INSERT INTO tickets (user_id, subject, message, status) VALUES (?, ?, ?, 'Open')");
            $stmt->execute([$userId, $subject, $message]);
            
            echo json_encode(['success' => true, 'message' => 'Ticket created', 'id' => $conn->lastInsertId()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to create ticket: ' . $e->getMessage()]);
        }

    } elseif ($action === 'update_status') {
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? null;
        
        if (!$id || !$status) {
            echo json_encode(['success' => false, 'message' => 'ID and Status required']);
            exit;
        }
        
        $validStatuses = ['Open', 'Pending', 'Resolved', 'Closed'];
        if (!in_array($status, $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("UPDATE tickets SET status = ? WHERE id = ?");
            if ($stmt->execute([$status, $id])) {
                try {
                    $stmt = $conn->prepare("SELECT t.*, u.email, u.name FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
                    $stmt->execute([$id]);
                    $t = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($t && $t['email']) {
                        $msg = "Hello " . ($t['name'] ?: 'Trader') . ",\n\nYour support ticket status was updated to: " . $status . ".";
                        @mail($t['email'], "Ticket Status Update", $msg, "From: support@pips-and-profits-academy");
                    }
                } catch (Exception $e) {}
                echo json_encode(['success' => true, 'message' => 'Status updated']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update status']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
?>
