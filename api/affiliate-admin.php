<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self' https: data:; img-src 'self' https: data:; script-src 'self' https:; style-src 'self' https: 'unsafe-inline'; connect-src 'self' https:; frame-ancestors 'self';");

require_once 'session_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connect.php';
require_once 'db_helper.php';

$method = $_SERVER['REQUEST_METHOD'];

// Helper to get POST data
function getPostParams() {
    if (!empty($_POST)) return $_POST;
    $input = file_get_contents("php://input");
    return json_decode($input, true) ?? [];
}

// Check admin access
function checkAdminAccess() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
    
    global $conn;
    $roleCol = getRoleColumn($conn);
    
    $stmt = $conn->prepare("SELECT $roleCol as role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if admin (role_id 1 or role 'admin')
    $isAdmin = $user && ($user['role'] == 1 || $user['role'] === 'admin');
    
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
        exit;
    }
}

if ($method === 'GET') {
    checkAdminAccess();
    
    // Check if affiliate_users table exists
    try {
        $conn->query("SELECT 1 FROM affiliate_users LIMIT 1");
    } catch (PDOException $e) {
        echo json_encode([
            'success' => true, 
            'affiliates' => [], 
            'referrals' => [], 
            'payouts' => [],
            'message' => 'Database tables not setup yet.',
            'setup_required' => true
        ]);
        exit;
    }

    $action = $_GET['action'] ?? '';
    $nameCol = getUserNameColumn($conn);
    
    if ($action === 'get_all_affiliates') {
        try {
            $stmt = $conn->prepare("
                SELECT au.*, u.$nameCol as name, u.email 
                FROM affiliate_users au 
                JOIN users u ON au.user_id = u.id 
                ORDER BY au.created_at DESC
            ");
            $stmt->execute();
            $affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'affiliates' => $affiliates]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'get_all_referrals') {
        try {
            $stmt = $conn->prepare("
                SELECT ar.*, 
                       u1.$nameCol as referred_name, u1.email as referred_email,
                       u2.$nameCol as affiliate_name
                FROM affiliate_referrals ar
                JOIN users u1 ON ar.referred_user_id = u1.id
                JOIN affiliate_users au ON ar.affiliate_id = au.id
                JOIN users u2 ON au.user_id = u2.id
                ORDER BY ar.signup_date DESC
            ");
            $stmt->execute();
            $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'referrals' => $referrals]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'get_all_payouts') {
        try {
            $stmt = $conn->prepare("
                SELECT ap.*, 
                       au.user_id,
                       u.$nameCol as affiliate_name
                FROM affiliate_payouts ap
                JOIN affiliate_users au ON ap.affiliate_id = au.id
                JOIN users u ON au.user_id = u.id
                ORDER BY ap.created_at DESC
            ");
            $stmt->execute();
            $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'payouts' => $payouts]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
} elseif ($method === 'POST') {
    checkAdminAccess();
    validate_csrf();
    $params = getPostParams();
    $action = $params['action'] ?? '';
    
    // CSRF protection
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    if ($action === 'update_affiliate_status') {
        $affiliateId = $params['affiliate_id'] ?? null;
        $newStatus = $params['status'] ?? null;
        
        if (!$affiliateId || !$newStatus) {
            echo json_encode(['success' => false, 'message' => 'Affiliate ID and status required']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("
                UPDATE affiliate_users 
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $affiliateId]);
            
            echo json_encode(['success' => true, 'message' => 'Affiliate status updated successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'update_payout_status') {
        $payoutId = $params['payout_id'] ?? null;
        $newStatus = $params['status'] ?? null;
        
        if (!$payoutId || !$newStatus) {
            echo json_encode(['success' => false, 'message' => 'Payout ID and status required']);
            exit;
        }
        
        try {
            $conn->beginTransaction();
            
            if ($newStatus === 'completed') {
                // Get payout details
                $stmt = $conn->prepare("
                    SELECT affiliate_id, amount 
                    FROM affiliate_payouts 
                    WHERE id = ? AND status = 'processing'
                ");
                $stmt->execute([$payoutId]);
                $payout = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$payout) {
                    throw new Exception('Payout not found or not in processing status');
                }
                
                // Update affiliate balance
                $stmt = $conn->prepare("
                    UPDATE affiliate_users 
                    SET current_balance = current_balance - ?,
                        total_withdrawn = total_withdrawn + ?
                    WHERE id = ?
                ");
                $stmt->execute([$payout['amount'], $payout['amount'], $payout['affiliate_id']]);
                
                // Record transaction
                $stmt = $conn->prepare("
                    INSERT INTO affiliate_transactions 
                    (affiliate_id, transaction_type, amount, description, created_at)
                    VALUES (?, 'withdrawal', ?, 'Admin processed payout', NOW())
                ");
                $stmt->execute([$payout['affiliate_id'], $payout['amount']]);
            }
            
            // Update payout status
            $processedDate = $newStatus === 'completed' ? 'NOW()' : 'NULL';
            $transactionId = $newStatus === 'completed' ? 'ADMIN_' . uniqid() : 'NULL';
            
            $stmt = $conn->prepare("
                UPDATE affiliate_payouts 
                SET status = ?, 
                    processed_date = $processedDate,
                    transaction_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $transactionId, $payoutId]);
            
            $conn->commit();
            
            echo json_encode(['success' => true, 'message' => 'Payout status updated successfully']);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'get_affiliate_stats') {
        $affiliateId = $params['affiliate_id'] ?? null;
        
        if (!$affiliateId) {
            echo json_encode(['success' => false, 'message' => 'Affiliate ID required']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("
                SELECT 
                    au.*,
                    (SELECT COUNT(*) FROM affiliate_referrals WHERE affiliate_id = au.id) as total_referrals,
                    (SELECT COUNT(*) FROM affiliate_referrals WHERE affiliate_id = au.id AND status = 'confirmed') as confirmed_referrals,
                    (SELECT COUNT(*) FROM affiliate_referrals WHERE affiliate_id = au.id AND status = 'pending') as pending_referrals,
                    (SELECT SUM(amount) FROM affiliate_transactions WHERE affiliate_id = au.id AND transaction_type = 'commission') as total_commissions,
                    (SELECT SUM(amount) FROM affiliate_transactions WHERE affiliate_id = au.id AND transaction_type = 'withdrawal') as total_withdrawals
                FROM affiliate_users au
                WHERE au.id = ?
            ");
            $stmt->execute([$affiliateId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stats) {
                echo json_encode(['success' => true, 'stats' => $stats]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Affiliate not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
}
?>
