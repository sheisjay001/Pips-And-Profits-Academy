<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

// Helper to get POST data (handling both JSON and FormData)
function getPostParams() {
    if (!empty($_POST)) return $_POST;
    $input = file_get_contents("php://input");
    return json_decode($input, true) ?? [];
}

if ($method === 'GET') {
    // Admin: Get all pending payments (or all)
    try {
        $stmt = $conn->query("
            SELECT p.*, u.name as userName, u.email as userEmail 
            FROM payments p 
            JOIN users u ON p.user_id = u.id 
            ORDER BY p.created_at DESC
        ");
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ensure full URL for proofs if stored relatively
        foreach ($payments as &$p) {
            if ($p['proof_url'] && !filter_var($p['proof_url'], FILTER_VALIDATE_URL)) {
                // Assuming api/ is root for this script, but images are relative to site root usually
                // If stored as 'uploads/proofs/file.jpg', and we are in /api/, we need to return full path or correct relative
                // Let's just return what's in DB and let frontend handle relative path
            }
            // Frontend expects 'date' alias
            $p['date'] = $p['created_at'];
        }
        
        echo json_encode($payments);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }

} elseif ($method === 'POST') {
    $params = getPostParams();
    $action = $params['action'] ?? '';

    // Handle File Upload (Submit Payment)
    if (isset($_FILES['proof'])) {
        $userId = $_POST['user_id'] ?? null;
        $amount = $_POST['amount'] ?? 0;
        $plan = $_POST['plan'] ?? 'pro';
        
        if (!$userId || !$amount) {
            echo json_encode(['success' => false, 'message' => 'Missing user or amount']);
            exit;
        }

        $uploadDir = '../uploads/proofs/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = time() . '_' . basename($_FILES['proof']['name']);
        $targetFile = $uploadDir . $fileName;
        $dbPath = 'uploads/proofs/' . $fileName; // Path relative to web root

        if (move_uploaded_file($_FILES['proof']['tmp_name'], $targetFile)) {
            try {
                $stmt = $conn->prepare("INSERT INTO payments (user_id, amount, plan, proof_url, status) VALUES (?, ?, ?, ?, 'Pending')");
                $stmt->execute([$userId, $amount, $plan, $dbPath]);
                echo json_encode(['success' => true, 'message' => 'Payment submitted successfully']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
        }
        exit;
    }

    // Handle Actions (Approve/Reject)
    if ($action === 'update_status') {
        $id = $params['id'] ?? null;
        $status = $params['status'] ?? null; // Approved, Rejected
        
        if (!$id || !$status) {
            echo json_encode(['success' => false, 'message' => 'ID and Status required']);
            exit;
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("UPDATE payments SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);

            // If Approved, update user plan
            if ($status === 'Approved') {
                // Get payment details to know which user and plan
                $stmt = $conn->prepare("SELECT user_id, plan FROM payments WHERE id = ?");
                $stmt->execute([$id]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($payment) {
                    $stmt = $conn->prepare("UPDATE users SET plan = ? WHERE id = ?");
                    $stmt->execute([$payment['plan'], $payment['user_id']]);
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Payment status updated']);

        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
}
?>