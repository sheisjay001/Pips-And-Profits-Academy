<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
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

// Helper to get POST data
function getPostParams() {
    if (!empty($_POST)) return $_POST;
    $input = file_get_contents("php://input");
    return json_decode($input, true) ?? [];
}

// Calculate commission based on payment amount and user plan
function calculateCommission($conn, $affiliateId, $paymentAmount, $referredUserId) {
    // Check if referred user is 'pro' or 'elite'
    $stmt = $conn->prepare("SELECT plan FROM users WHERE id = ?");
    $stmt->execute([$referredUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !in_array($user['plan'], ['pro', 'elite', 'premium'])) {
        return 0; // Only pro and elite/premium plans generate commission
    }

    // Get affiliate's commission rate
    $stmt = $conn->prepare("SELECT commission_rate FROM affiliate_users WHERE id = ?");
    $stmt->execute([$affiliateId]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$affiliate) {
        return 0;
    }
    
    $commissionRate = $affiliate['commission_rate'];
    $commissionAmount = ($paymentAmount * $commissionRate) / 100;
    
    return $commissionAmount;
}

// Update affiliate earnings
function updateAffiliateEarnings($conn, $affiliateId, $commissionAmount) {
    $stmt = $conn->prepare("
        UPDATE affiliate_users 
        SET total_earnings = total_earnings + ?, 
            current_balance = current_balance + ?,
            referral_count = referral_count + 1
        WHERE id = ?
    ");
    $stmt->execute([$commissionAmount, $commissionAmount, $affiliateId]);
}

if ($method === 'POST') {
    $params = getPostParams();
    $action = $params['action'] ?? '';
    
    // CSRF protection
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    if ($action === 'process_commission') {
        // Process commission when a referred user makes a payment
        $referredUserId = $params['user_id'] ?? null;
        $paymentAmount = $params['amount'] ?? 0;
        $paymentId = $params['payment_id'] ?? null;
        
        if (!$referredUserId || !$paymentAmount || $paymentAmount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid user ID and payment amount required']);
            exit;
        }
        
        try {
            // Check if user was referred
            $stmt = $conn->prepare("
                SELECT ar.id as referral_id, ar.affiliate_id, ar.status as referral_status
                FROM affiliate_referrals ar
                WHERE ar.referred_user_id = ?
            ");
            $stmt->execute([$referredUserId]);
            $referral = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$referral) {
                echo json_encode(['success' => true, 'message' => 'No referral found for this user']);
                exit;
            }
            
            if ($referral['referral_status'] !== 'pending') {
                echo json_encode(['success' => true, 'message' => 'Referral already processed']);
                exit;
            }
            
            // Calculate commission
            $commissionAmount = calculateCommission($conn, $referral['affiliate_id'], $paymentAmount, $referredUserId);
            
            if ($commissionAmount <= 0) {
                echo json_encode(['success' => false, 'message' => 'Commission calculation failed']);
                exit;
            }
            
            // Start transaction
            $conn->beginTransaction();
            
            // Update referral status and commission amount
            $stmt = $conn->prepare("
                UPDATE affiliate_referrals 
                SET status = 'confirmed', 
                    commission_amount = ?,
                    confirmation_date = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$commissionAmount, $referral['referral_id']]);
            
            // Update affiliate earnings
            updateAffiliateEarnings($conn, $referral['affiliate_id'], $commissionAmount);
            
            // Record commission transaction
            $stmt = $conn->prepare("
                INSERT INTO affiliate_transactions 
                (affiliate_id, referral_id, transaction_type, amount, description, payment_id, created_at)
                VALUES (?, ?, 'commission', ?, 'Commission from payment', ?, NOW())
            ");
            $stmt->execute([$referral['affiliate_id'], $referral['referral_id'], $commissionAmount, $paymentId]);
            
            $conn->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Commission processed successfully',
                'commission_amount' => $commissionAmount
            ]);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'process_monthly_payouts') {
        // Process monthly payouts (scheduled for 21st of each month)
        $adminKey = $params['admin_key'] ?? '';
        if ($adminKey !== 'pips_admin_2024') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        try {
            $today = date('Y-m-d');
            $payoutDate = date('Y-m-21');
            
            if ($today !== $payoutDate) {
                echo json_encode(['success' => false, 'message' => 'Payouts can only be processed on the 21st of each month']);
                exit;
            }
            
            // Get all pending payouts
            $stmt = $conn->prepare("
                SELECT ap.*, au.user_id, u.name, u.email, aba.*
                FROM affiliate_payouts ap
                JOIN affiliate_users au ON ap.affiliate_id = au.id
                JOIN users u ON au.user_id = u.id
                LEFT JOIN affiliate_bank_accounts aba ON au.id = aba.affiliate_id
                WHERE ap.status = 'pending' AND ap.payout_date <= ?
            ");
            $stmt->execute([$payoutDate]);
            $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $processedCount = 0;
            $totalAmount = 0;
            
            foreach ($payouts as $payout) {
                // Start transaction for each payout
                $conn->beginTransaction();
                
                try {
                    // Update payout status
                    $stmt = $conn->prepare("
                        UPDATE affiliate_payouts 
                        SET status = 'processing', 
                            processed_date = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$payout['id']]);
                    
                    // Deduct from affiliate balance
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
                        VALUES (?, 'withdrawal', ?, 'Monthly payout', NOW())
                    ");
                    $stmt->execute([$payout['affiliate_id'], $payout['amount']]);
                    
                    // Mark as completed (in real implementation, you'd integrate with payment gateway)
                    $stmt = $conn->prepare("
                        UPDATE affiliate_payouts 
                        SET status = 'completed',
                            transaction_id = 'BANK_TRANSFER_' . uniqid()
                        WHERE id = ?
                    ");
                    $stmt->execute([$payout['id']]);
                    
                    $conn->commit();
                    $processedCount++;
                    $totalAmount += $payout['amount'];
                    
                } catch (Exception $e) {
                    $conn->rollBack();
                    // Mark as failed
                    $stmt = $conn->prepare("
                        UPDATE affiliate_payouts 
                        SET status = 'cancelled', notes = CONCAT('Failed: ', ?)
                        WHERE id = ?
                    ");
                    $stmt->execute([$e->getMessage(), $payout['id']]);
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => "Processed $processedCount payouts totaling $" . number_format($totalAmount, 2),
                'processed_count' => $processedCount,
                'total_amount' => $totalAmount
            ]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
} elseif ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'get_affiliate_stats') {
        $affiliateId = $_GET['affiliate_id'] ?? null;
        if (!$affiliateId) {
            echo json_encode(['success' => false, 'message' => 'Affiliate ID required']);
            exit;
        }
        
        try {
            // Get comprehensive stats
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
            
            // Get monthly earnings
            $stmt = $conn->prepare("
                SELECT DATE_FORMAT(created_at, '%Y-%m') as month, 
                       SUM(amount) as earnings
                FROM affiliate_transactions 
                WHERE affiliate_id = ? AND transaction_type = 'commission'
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month DESC
                LIMIT 12
            ");
            $stmt->execute([$affiliateId]);
            $monthlyEarnings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'monthly_earnings' => $monthlyEarnings
            ]);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
}
?>
