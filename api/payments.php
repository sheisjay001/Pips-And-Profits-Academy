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

// Helper to get POST data (handling both JSON and FormData)
function getPostParams() {
    if (!empty($_POST)) return $_POST;
    $input = file_get_contents("php://input");
    return json_decode($input, true) ?? [];
}

if ($method === 'GET') {
    // Admin: Get all pending payments (or all)
    try {
        $userId = $_GET['user_id'] ?? null;
        if ($userId) {
            $stmt = $conn->prepare("
                SELECT p.*, u.name as userName, u.email as userEmail 
                FROM payments p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.user_id = ?
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$userId]);
        } else {
            $stmt = $conn->query("
                SELECT p.*, u.name as userName, u.email as userEmail 
                FROM payments p 
                JOIN users u ON p.user_id = u.id 
                ORDER BY p.created_at DESC
            ");
        }
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
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
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
        
        $stmt = $conn->prepare("SELECT id, role, COALESCE(email_verified, 0) as email_verified FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        if ((int)($u['email_verified'] ?? 0) !== 1) {
            echo json_encode(['success' => false, 'message' => 'Please verify your email before submitting payments.']);
            exit;
        }
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] !== (int)$userId) {
            $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$_SESSION['user_id']]);
            $sessionUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sessionUser || ($sessionUser['role'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Not authorized']);
                exit;
            }
        }

        if (!isset($_FILES['proof']['size']) || $_FILES['proof']['size'] > 4 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large']);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['proof']['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type']);
            exit;
        }

        $uploadDir = '../uploads/proofs/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = $allowed[$mime];
        $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetFile = $uploadDir . $fileName;
        $dbPath = 'uploads/proofs/' . $fileName; // Path relative to web root

        $source = $_FILES['proof']['tmp_name'];
        $img = null;
        if ($mime === 'image/jpeg') $img = imagecreatefromjpeg($source);
        elseif ($mime === 'image/png') $img = imagecreatefrompng($source);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $img = imagecreatefromwebp($source);
        $saved = false;
        if ($img) {
            if ($mime === 'image/jpeg') $saved = imagejpeg($img, $targetFile, 85);
            elseif ($mime === 'image/png') $saved = imagepng($img, $targetFile, 8);
            elseif ($mime === 'image/webp' && function_exists('imagewebp')) $saved = imagewebp($img, $targetFile, 80);
            imagedestroy($img);
        } else {
            $saved = move_uploaded_file($source, $targetFile);
        }

        if ($saved) {
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
            try {
                $stmt = $conn->prepare("SELECT p.*, u.email, u.name FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
                $stmt->execute([$id]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($p && $p['email']) {
                    $msg = "Hello " . ($p['name'] ?: 'Trader') . ",\n\nYour payment for the " . strtoupper($p['plan']) . " plan was updated to: " . $status . ".\n\nThank you.";
                    @mail($p['email'], "Payment Status Update", $msg, "From: no-reply@pips-and-profits-academy");
                }
            } catch (Exception $e) {}
            echo json_encode(['success' => true, 'message' => 'Payment status updated']);

        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
}
?>
