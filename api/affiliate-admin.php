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

require_once 'session_config.php';
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
                LEFT JOIN users u ON au.user_id = u.id
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
                SELECT ar.*, ar.commission_earned as commission_amount,
                       u1.$nameCol as referred_name, u1.email as referred_email,
                       u2.$nameCol as affiliate_name
                FROM affiliate_referrals ar
                LEFT JOIN users u1 ON ar.referred_user_id = u1.id
                LEFT JOIN affiliate_users au ON ar.affiliate_id = au.id
                LEFT JOIN users u2 ON au.user_id = u2.id
                ORDER BY ar.id DESC
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
                LEFT JOIN affiliate_users au ON ap.affiliate_id = au.id
                LEFT JOIN users u ON au.user_id = u.id
                ORDER BY ap.created_at DESC
            ");
            $stmt->execute();
            $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'payouts' => $payouts]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'get_pending_bank_accounts') {
        try {
            $stmt = $conn->prepare("
                SELECT aba.*, u.$nameCol as affiliate_name, u.email, au.affiliate_code
                FROM affiliate_bank_accounts aba
                JOIN affiliate_users au ON aba.affiliate_id = au.id
                JOIN users u ON au.user_id = u.id
                WHERE aba.is_verified = FALSE
                ORDER BY aba.created_at DESC
            ");
            $stmt->execute();
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'accounts' => $accounts]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'get_affiliate_details') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Affiliate ID required']);
            exit;
        }

        try {
            // Get affiliate info
            $stmt = $conn->prepare("
                SELECT au.*, u.$nameCol as name, u.email, u.profile_picture, u.bio, u.created_at as user_created_at
                FROM affiliate_users au 
                LEFT JOIN users u ON au.user_id = u.id 
                WHERE au.id = ?
            ");
            $stmt->execute([$id]);
            $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$affiliate) {
                echo json_encode(['success' => false, 'message' => 'Affiliate not found']);
                exit;
            }

            // Get bank account
            $stmt = $conn->prepare("SELECT * FROM affiliate_bank_accounts WHERE affiliate_id = ?");
            $stmt->execute([$id]);
            $bank = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get recent referrals
            $stmt = $conn->prepare("
                SELECT ar.*, ar.commission_earned as commission_amount, u.$nameCol as referred_name, u.email as referred_email
                FROM affiliate_referrals ar
                LEFT JOIN users u ON ar.referred_user_id = u.id
                WHERE ar.affiliate_id = ?
                ORDER BY ar.signup_date DESC
                LIMIT 5
            ");
            $stmt->execute([$id]);
            $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'affiliate' => $affiliate,
                'bank' => $bank,
                'referrals' => $referrals
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'get_payout_details') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Payout ID required']);
            exit;
        }

        try {
            $stmt = $conn->prepare("
                SELECT ap.*, au.affiliate_code, u.$nameCol as affiliate_name, u.email as affiliate_email,
                       ba.bank_name, ba.account_number, ba.account_name, ba.country, ba.swift_code
                FROM affiliate_payouts ap
                JOIN affiliate_users au ON ap.affiliate_id = au.id
                JOIN users u ON au.user_id = u.id
                LEFT JOIN affiliate_bank_accounts ba ON au.id = ba.affiliate_id
                WHERE ap.id = ?
            ");
            $stmt->execute([$id]);
            $payout = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                echo json_encode(['success' => false, 'message' => 'Payout not found']);
                exit;
            }

            echo json_encode(['success' => true, 'payout' => $payout]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
} elseif ($method === 'POST') {
    checkAdminAccess();
    validate_csrf();
    $params = getPostParams();
    $action = $params['action'] ?? '';
    
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
    } elseif ($action === 'verify_bank_account') {
        $bankAccountId = $params['bank_account_id'] ?? null;
        
        if (!$bankAccountId) {
            echo json_encode(['success' => false, 'message' => 'Bank account ID required']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("
                UPDATE affiliate_bank_accounts 
                SET is_verified = TRUE, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$bankAccountId]);
            
            echo json_encode(['success' => true, 'message' => 'Bank account verified successfully']);
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
