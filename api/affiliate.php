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

// Generate unique affiliate code
function generateAffiliateCode($conn) {
    do {
        $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $stmt = $conn->prepare("SELECT COUNT(*) FROM affiliate_users WHERE affiliate_code = ?");
        $stmt->execute([$code]);
        $count = $stmt->fetchColumn();
    } while ($count > 0);
    return $code;
}

// Generate affiliate link
function generateAffiliateLink($code) {
    // Force use the Vercel live link for affiliate links
    $protocol = 'https';
    $host = 'pips-and-profits-academy.vercel.app';
    
    return $protocol . '://' . $host . '/register.html?ref=' . $code;
}

// Get commission rate for user plan
function getCommissionRate($conn, $userId) {
    // Check referral count for tiered commissions
    try {
        $stmt = $conn->prepare("SELECT referral_count FROM affiliate_users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $count = $stmt->fetchColumn() ?: 0;
        
        if ($count >= 50) return 75.00; // Elite: 75%
        if ($count >= 10) return 60.00; // Pro: 60%
        return 50.00; // Standard: 50%
    } catch (Exception $e) {
        return 50.00;
    }
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $userId = $_GET['user_id'] ?? null;
    
    // Ensure table structure is updated
    static $migrationRun = false;
    if (!$migrationRun) {
        try {
            // Create affiliate_users table
            $conn->exec("CREATE TABLE IF NOT EXISTS affiliate_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                affiliate_code VARCHAR(20) NOT NULL UNIQUE,
                affiliate_link VARCHAR(255) NOT NULL,
                commission_rate DECIMAL(5, 2) DEFAULT 50.00,
                status ENUM('active', 'pending', 'inactive') DEFAULT 'pending',
                total_earnings DECIMAL(10, 2) DEFAULT 0.00,
                current_balance DECIMAL(10, 2) DEFAULT 0.00,
                referral_count INT DEFAULT 0,
                click_count INT DEFAULT 0,
                total_withdrawn DECIMAL(10, 2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");

            // Create affiliate_referrals table
            $conn->exec("CREATE TABLE IF NOT EXISTS affiliate_referrals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                affiliate_id INT NOT NULL,
                referred_user_id INT NOT NULL UNIQUE,
                referral_code VARCHAR(20) NOT NULL,
                status ENUM('pending', 'confirmed', 'rejected') DEFAULT 'pending',
                commission_earned DECIMAL(10, 2) DEFAULT 0.00,
                signup_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE,
                FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE
            )");

            // Create affiliate_bank_accounts table
            $conn->exec("CREATE TABLE IF NOT EXISTS affiliate_bank_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                affiliate_id INT NOT NULL UNIQUE,
                bank_name VARCHAR(100) NOT NULL,
                account_name VARCHAR(100) NOT NULL,
                account_number VARCHAR(50) NOT NULL,
                routing_number VARCHAR(50),
                swift_code VARCHAR(50),
                country VARCHAR(100) NOT NULL,
                currency VARCHAR(10) DEFAULT 'USD',
                is_verified TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE
            )");

            // Create affiliate_payouts table
            $conn->exec("CREATE TABLE IF NOT EXISTS affiliate_payouts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                affiliate_id INT NOT NULL,
                amount DECIMAL(10, 2) NOT NULL,
                status ENUM('pending', 'processed', 'failed') DEFAULT 'pending',
                payout_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_date TIMESTAMP NULL,
                transaction_id VARCHAR(100),
                FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE
            )");

            // Helper function to check if column exists
            $checkColumn = function($conn, $table, $column) {
                try {
                    $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                    $stmt->execute([$column]);
                    return $stmt->fetch() !== false;
                } catch (Exception $e) {
                    return false;
                }
            };

            // Add missing columns if tables already existed
            if (!$checkColumn($conn, 'affiliate_users', 'click_count')) {
                $conn->exec("ALTER TABLE affiliate_users ADD COLUMN click_count INT DEFAULT 0");
            }
            if (!$checkColumn($conn, 'affiliate_users', 'total_withdrawn')) {
                $conn->exec("ALTER TABLE affiliate_users ADD COLUMN total_withdrawn DECIMAL(10, 2) DEFAULT 0.00");
            }
            if (!$checkColumn($conn, 'affiliate_bank_accounts', 'updated_at')) {
                $conn->exec("ALTER TABLE affiliate_bank_accounts ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        } catch (Exception $e) {
            // Log error or ignore if tables don't exist yet
        }
        $migrationRun = true;
    }
    if ($action === 'get_affiliate_info') {
        // Get affiliate information for a user
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User ID required']);
            exit;
        }
        
        try {
            // Check if affiliate_users table exists
            try {
                $conn->query("SELECT 1 FROM affiliate_users LIMIT 1");
            } catch (PDOException $e) {
                // Table doesn't exist, return a friendly message instead of a crash
                echo json_encode([
                    'success' => true, 
                    'affiliate' => null, 
                    'message' => 'Database tables not setup yet. Please run migration.',
                    'setup_required' => true
                ]);
                exit;
            }

            $nameCol = getUserNameColumn($conn);
            $stmt = $conn->prepare("
                SELECT au.*, u.$nameCol as name, u.email 
                FROM affiliate_users au 
                LEFT JOIN users u ON au.user_id = u.id 
                WHERE au.user_id = ?
            ");
            $stmt->execute([$userId]);
            $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($affiliate) {
                // Dynamically fix old affiliate links if necessary
                $correctLink = generateAffiliateLink($affiliate['affiliate_code']);
                if ($affiliate['affiliate_link'] !== $correctLink) {
                    $affiliate['affiliate_link'] = $correctLink;
                    // Silently update database
                    try {
                        $stmtUpdate = $conn->prepare("UPDATE affiliate_users SET affiliate_link = ? WHERE id = ?");
                        $stmtUpdate->execute([$correctLink, $affiliate['id']]);
                    } catch (Exception $e) {}
                }

                // Get referral statistics
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as total_referrals,
                           SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_referrals,
                           SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_referrals
                    FROM affiliate_referrals 
                    WHERE affiliate_id = ?
                ");
                $stmt->execute([$affiliate['id']]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Get recent referrals
                $stmt = $conn->prepare("
                    SELECT ar.*, u.$nameCol as referred_name, u.email as referred_email, u.signup_date
                    FROM affiliate_referrals ar
                    JOIN users u ON ar.referred_user_id = u.id
                    WHERE ar.affiliate_id = ?
                    ORDER BY ar.signup_date DESC
                    LIMIT 10
                ");
                $stmt->execute([$affiliate['id']]);
                $recentReferrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get bank account info
                $stmt = $conn->prepare("
                    SELECT * FROM affiliate_bank_accounts 
                    WHERE affiliate_id = ?
                ");
                $stmt->execute([$affiliate['id']]);
                $bankAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Get payout history
                $stmt = $conn->prepare("
                    SELECT * FROM affiliate_payouts 
                    WHERE affiliate_id = ?
                    ORDER BY payout_date DESC
                    LIMIT 10
                ");
                $stmt->execute([$affiliate['id']]);
                $payoutHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'affiliate' => $affiliate,
                    'stats' => $stats,
                    'recentReferrals' => $recentReferrals,
                    'bankAccount' => $bankAccount,
                    'payoutHistory' => $payoutHistory
                ]);
            } else {
                echo json_encode(['success' => true, 'affiliate' => null]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'error_info' => $e->errorInfo]);
        }
    } elseif ($action === 'check_referral_code') {
        // Check if referral code is valid
        $code = $_GET['code'] ?? '';
        if (!$code) {
            echo json_encode(['success' => false, 'message' => 'Referral code required']);
            exit;
        }
        
        try {
            $nameCol = getUserNameColumn($conn);
            $stmt = $conn->prepare("
                SELECT au.*, u.$nameCol as affiliate_name 
                FROM affiliate_users au 
                LEFT JOIN users u ON au.user_id = u.id 
                WHERE au.affiliate_code = ? AND au.status = 'active'
            ");
            $stmt->execute([$code]);
            $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($affiliate) {
                // Increment click count
                $stmt = $conn->prepare("UPDATE affiliate_users SET click_count = click_count + 1 WHERE id = ?");
                $stmt->execute([$affiliate['id']]);
                
                echo json_encode(['success' => true, 'affiliate' => $affiliate]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid referral code']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'get_leaderboard') {
        try {
            $nameCol = getUserNameColumn($conn);
            $stmt = $conn->prepare("
                SELECT u.$nameCol as name, au.referral_count, au.total_earnings, u.profile_picture
                FROM affiliate_users au
                JOIN users u ON au.user_id = u.id
                WHERE au.status = 'active'
                ORDER BY au.total_earnings DESC
                LIMIT 10
            ");
            $stmt->execute();
            $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mask names for privacy if needed, but let's keep it first name + initial
            foreach ($leaderboard as &$entry) {
                $names = explode(' ', $entry['name']);
                if (count($names) > 1) {
                    $entry['display_name'] = $names[0] . ' ' . substr($names[1], 0, 1) . '.';
                } else {
                    $entry['display_name'] = $entry['name'];
                }
                unset($entry['name']); // Hide full name
            }
            
            echo json_encode(['success' => true, 'leaderboard' => $leaderboard]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
} elseif ($method === 'POST') {
    $params = getPostParams();
    $action = $params['action'] ?? '';
    
    // CSRF protection
    validate_csrf();
    
    if ($action === 'become_affiliate') {
        // User requests to become an affiliate
        $userId = $params['user_id'] ?? null;
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User ID required']);
            exit;
        }
        
        try {
            // Check if user is already an affiliate
            $stmt = $conn->prepare("SELECT id, status FROM affiliate_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if ($existing['status'] === 'pending') {
                    echo json_encode(['success' => false, 'message' => 'Your affiliate application is already pending approval.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'You are already an affiliate']);
                }
                exit;
            }
            
            // Check if user is a student (has a paid plan)
            $stmt = $conn->prepare("SELECT plan FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userPlan = $stmt->fetchColumn();
            $isStudent = in_array($userPlan, ['pro', 'elite', 'premium']);
            $status = $isStudent ? 'active' : 'pending';

            // Get commission rate for user
            $commissionRate = getCommissionRate($conn, $userId);
            $affiliateCode = generateAffiliateCode($conn);
            $affiliateLink = generateAffiliateLink($affiliateCode);
            
            // Create affiliate account
            $stmt = $conn->prepare("
                INSERT INTO affiliate_users 
                (user_id, affiliate_code, affiliate_link, commission_rate, status) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $affiliateCode, $affiliateLink, $commissionRate, $status]);
            
            $message = $isStudent 
                ? 'Success! You are now an affiliate partner. Your unique code and link are ready!' 
                : 'Success! Your affiliate application has been submitted and is pending approval. You will get access to your code once an admin approves you.';

            echo json_encode([
                'success' => true,
                'message' => $message,
                'status' => $status
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'track_referral') {
        // Track a referral when someone signs up with referral code
        $referredUserId = $params['user_id'] ?? null;
        $referralCode = $params['referral_code'] ?? null;
        
        if (!$referredUserId || !$referralCode) {
            echo json_encode(['success' => false, 'message' => 'User ID and referral code required']);
            exit;
        }
        
        try {
            // Get affiliate info
            $stmt = $conn->prepare("
                SELECT id, user_id FROM affiliate_users 
                WHERE affiliate_code = ? AND status = 'active'
            ");
            $stmt->execute([$referralCode]);
            $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$affiliate) {
                echo json_encode(['success' => false, 'message' => 'Invalid referral code']);
                exit;
            }
            
            // Check if referral already exists
            $stmt = $conn->prepare("
                SELECT id FROM affiliate_referrals 
                WHERE affiliate_id = ? AND referred_user_id = ?
            ");
            $stmt->execute([$affiliate['id'], $referredUserId]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Referral already tracked']);
                exit;
            }
            
            // Create referral record
            $stmt = $conn->prepare("
                INSERT INTO affiliate_referrals 
                (affiliate_id, referred_user_id, referral_code, status) 
                VALUES (?, ?, ?, 'pending')
            ");
            $stmt->execute([$affiliate['id'], $referredUserId, $referralCode]);
            
            // Update user record with referral info
            $stmt = $conn->prepare("
                UPDATE users 
                SET referred_by_affiliate_id = ?, referral_code_used = ?
                WHERE id = ?
            ");
            $stmt->execute([$affiliate['id'], $referralCode, $referredUserId]);
            
            echo json_encode(['success' => true, 'message' => 'Referral tracked successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'add_bank_account') {
        // Add bank account for affiliate
        $affiliateId = $params['affiliate_id'] ?? null;
        $bankName = $params['bank_name'] ?? '';
        $accountName = $params['account_name'] ?? '';
        $accountNumber = $params['account_number'] ?? '';
        $routingNumber = $params['routing_number'] ?? '';
        $swiftCode = $params['swift_code'] ?? '';
        $country = $params['country'] ?? '';
        $currency = $params['currency'] ?? 'USD';
        
        if (!$affiliateId || !$bankName || !$accountName || !$accountNumber || !$country) {
            echo json_encode(['success' => false, 'message' => 'Required fields missing']);
            exit;
        }
        
        try {
            // Check if user is a student (has a paid plan)
            $stmt = $conn->prepare("
                SELECT u.plan 
                FROM users u 
                JOIN affiliate_users au ON u.id = au.user_id 
                WHERE au.id = ?
            ");
            $stmt->execute([$affiliateId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $isStudent = $user && in_array($user['plan'], ['pro', 'elite', 'premium']);
            $isVerified = $isStudent ? 1 : 0;

            // Check if bank account already exists
            $stmt = $conn->prepare("SELECT id FROM affiliate_bank_accounts WHERE affiliate_id = ?");
            $stmt->execute([$affiliateId]);
            if ($stmt->fetch()) {
                // Update existing account
                $stmt = $conn->prepare("
                    UPDATE affiliate_bank_accounts 
                    SET bank_name = ?, account_name = ?, account_number = ?, 
                        routing_number = ?, swift_code = ?, country = ?, currency = ?,
                        is_verified = ?
                    WHERE affiliate_id = ?
                ");
                $stmt->execute([$bankName, $accountName, $accountNumber, $routingNumber, $swiftCode, $country, $currency, $isVerified, $affiliateId]);
            } else {
                // Insert new account
                $stmt = $conn->prepare("
                    INSERT INTO affiliate_bank_accounts 
                    (affiliate_id, bank_name, account_name, account_number, routing_number, swift_code, country, currency, is_verified) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$affiliateId, $bankName, $accountName, $accountNumber, $routingNumber, $swiftCode, $country, $currency, $isVerified]);
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $isStudent ? 'Bank account added and automatically verified!' : 'Bank account added and pending verification.',
                'is_verified' => $isStudent
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } elseif ($action === 'request_withdrawal') {
        // Request withdrawal
        $affiliateId = $params['affiliate_id'] ?? null;
        $amount = $params['amount'] ?? 0;
        $notes = $params['notes'] ?? '';
        
        if (!$affiliateId || !$amount || $amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid affiliate ID and amount required']);
            exit;
        }
        
        try {
            // Check affiliate balance
            $stmt = $conn->prepare("SELECT current_balance FROM affiliate_users WHERE id = ?");
            $stmt->execute([$affiliateId]);
            $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$affiliate) {
                echo json_encode(['success' => false, 'message' => 'Affiliate not found']);
                exit;
            }
            
            if ($affiliate['current_balance'] < $amount) {
                echo json_encode(['success' => false, 'message' => 'Insufficient balance']);
                exit;
            }
            
            // Check if bank account exists
            $stmt = $conn->prepare("SELECT id FROM affiliate_bank_accounts WHERE affiliate_id = ?");
            $stmt->execute([$affiliateId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Please add a bank account first']);
                exit;
            }
            
            // Create withdrawal request
            $payoutDate = date('Y-m-21'); // Next 21st of the month
            if (date('d') > 21) {
                $payoutDate = date('Y-m-21', strtotime('+1 month'));
            }
            
            $stmt = $conn->prepare("
                INSERT INTO affiliate_payouts 
                (affiliate_id, amount, status, payout_date, notes) 
                VALUES (?, ?, 'pending', ?, ?)
            ");
            $stmt->execute([$affiliateId, $amount, $payoutDate, $notes]);
            
            // Update affiliate balance (will be processed on payout date)
            // Note: We don't deduct from balance now, only when payout is processed
            
            echo json_encode([
                'success' => true, 
                'message' => 'Withdrawal request submitted successfully',
                'payout_date' => $payoutDate
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
}
?>
