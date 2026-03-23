<?php
error_reporting(0);
ini_set('display_errors', 0);
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
    // ALWAYS force the production URL for affiliate links to prevent localhost links in production
    return 'https://pips-and-profits-academy.vercel.app/register.html?ref=' . $code;
}

// Get commission rate for user plan
function getCommissionRate($conn, $userId) {
    try {
        $stmt = $conn->prepare("SELECT referral_count FROM affiliate_users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $count = $stmt->fetchColumn() ?: 0;
        if ($count >= 50) return 75.00;
        if ($count >= 10) return 60.00;
        return 50.00;
    } catch (Exception $e) {
        return 50.00;
    }
}

function runMigration($conn) {
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
            confirmation_date TIMESTAMP NULL,
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
            notes TEXT,
            FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE
        )");

        // Create affiliate_transactions table
        $conn->exec("CREATE TABLE IF NOT EXISTS affiliate_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT NOT NULL,
            referral_id INT NULL,
            payment_id INT NULL,
            transaction_type ENUM('commission', 'withdrawal', 'adjustment') NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE
        )");

        // Helper function to check column
        $checkCol = function($conn, $table, $column) {
            try {
                $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                $stmt->execute([$column]);
                return $stmt->fetch() !== false;
            } catch (Exception $e) { return false; }
        };

        // Migration updates
        if (!$checkCol($conn, 'affiliate_users', 'click_count')) $conn->exec("ALTER TABLE affiliate_users ADD COLUMN click_count INT DEFAULT 0");
        if (!$checkCol($conn, 'affiliate_users', 'total_withdrawn')) $conn->exec("ALTER TABLE affiliate_users ADD COLUMN total_withdrawn DECIMAL(10, 2) DEFAULT 0.00");
        if (!$checkCol($conn, 'affiliate_bank_accounts', 'updated_at')) $conn->exec("ALTER TABLE affiliate_bank_accounts ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        if (!$checkCol($conn, 'affiliate_payouts', 'notes')) $conn->exec("ALTER TABLE affiliate_payouts ADD COLUMN notes TEXT");
        if (!$checkCol($conn, 'affiliate_referrals', 'signup_date')) $conn->exec("ALTER TABLE affiliate_referrals ADD COLUMN signup_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        if (!$checkCol($conn, 'affiliate_referrals', 'commission_earned')) $conn->exec("ALTER TABLE affiliate_referrals ADD COLUMN commission_earned DECIMAL(10, 2) DEFAULT 0.00");
        if (!$checkCol($conn, 'affiliate_referrals', 'confirmation_date')) $conn->exec("ALTER TABLE affiliate_referrals ADD COLUMN confirmation_date TIMESTAMP NULL");
        if (!$checkCol($conn, 'affiliate_transactions', 'referral_id')) $conn->exec("ALTER TABLE affiliate_transactions ADD COLUMN referral_id INT NULL");
        if (!$checkCol($conn, 'affiliate_transactions', 'payment_id')) $conn->exec("ALTER TABLE affiliate_transactions ADD COLUMN payment_id INT NULL");
        if (!$checkCol($conn, 'users', 'referred_by_affiliate_id')) $conn->exec("ALTER TABLE users ADD COLUMN referred_by_affiliate_id INT NULL");
        if (!$checkCol($conn, 'users', 'referral_code_used')) $conn->exec("ALTER TABLE users ADD COLUMN referral_code_used VARCHAR(20) NULL");

        // Link Joy to Soteriamaa
        $conn->exec("
            INSERT IGNORE INTO affiliate_referrals (affiliate_id, referred_user_id, referral_code, status, signup_date)
            SELECT au.id, u.id, au.affiliate_code, 'pending', u.created_at
            FROM users u
            JOIN users ua ON ua.email = 'soteriamaa@gmail.com'
            JOIN affiliate_users au ON au.user_id = ua.id
            WHERE u.email = 'joyrobertauta@gmail.com'
            AND u.id NOT IN (SELECT referred_user_id FROM affiliate_referrals)
        ");

        // Sync counts
        $conn->exec("UPDATE affiliate_users au SET referral_count = (SELECT COUNT(*) FROM affiliate_referrals WHERE affiliate_id = au.id)");
        return true;
    } catch (Exception $e) { return false; }
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    // Add debug helper
    if ($action === 'debug_info') {
        echo json_encode([
            'success' => true,
            'php_version' => PHP_VERSION,
            'server' => $_SERVER['SERVER_SOFTWARE'],
            'user_id' => $_GET['user_id'] ?? 'not set'
        ]);
        exit;
    }

    $userId = $_GET['user_id'] ?? null;
    
    if ($action === 'migrate') {
        if (runMigration($conn)) {
            echo json_encode(['success' => true, 'message' => 'Migration successful']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Migration failed']);
        }
        exit;
    }

    if ($action === 'get_affiliate_info') {
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User ID required']);
            exit;
        }

        try {
            $nameCol = getUserNameColumn($conn);
            $nameCol = str_replace(['`', ' '], '', $nameCol);

            // 1. Get Affiliate Basic Info
            $stmt = $conn->prepare("SELECT au.*, u.$nameCol as name, u.email FROM affiliate_users au LEFT JOIN users u ON au.user_id = u.id WHERE au.user_id = ?");
            $stmt->execute([$userId]);
            $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$affiliate) {
                echo json_encode(['success' => true, 'affiliate' => null]);
                exit;
            }

            // 2. Force correct link if it's currently a localhost link
            if (strpos($affiliate['affiliate_link'] ?? '', '127.0.0.1') !== false || strpos($affiliate['affiliate_link'] ?? '', 'localhost') !== false) {
                $correctLink = generateAffiliateLink($affiliate['affiliate_code']);
                $conn->prepare("UPDATE affiliate_users SET affiliate_link = ? WHERE id = ?")->execute([$correctLink, $affiliate['id']]);
                $affiliate['affiliate_link'] = $correctLink;
            }

            // 3. Get referral statistics (Resilient)
            $stats = ['total_referrals' => 0, 'confirmed_referrals' => 0, 'pending_referrals' => 0];
            try {
                $stmtStats = $conn->prepare("
                    SELECT COUNT(*) as total_referrals,
                           SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_referrals,
                           SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_referrals
                    FROM affiliate_referrals 
                    WHERE affiliate_id = ?
                ");
                $stmtStats->execute([$affiliate['id']]);
                $res = $stmtStats->fetch(PDO::FETCH_ASSOC);
                if ($res) $stats = array_merge($stats, $res);
            } catch (Exception $e) {}

            // 4. Get recent referrals (Resilient)
            $recentReferrals = [];
            try {
                $stmtRecent = $conn->prepare("
                    SELECT ar.id, ar.status, IFNULL(ar.commission_earned, 0) as commission_amount, 
                           u.$nameCol as referred_name, u.email as referred_email, u.plan as referred_plan,
                           u.created_at as signup_date
                    FROM affiliate_referrals ar
                    JOIN users u ON ar.referred_user_id = u.id
                    WHERE ar.affiliate_id = ?
                    ORDER BY ar.id DESC
                    LIMIT 10
                ");
                $stmtRecent->execute([$affiliate['id']]);
                $recentReferrals = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            // 5. Get bank account info (Resilient)
            $bankAccount = null;
            try {
                $stmtBank = $conn->prepare("SELECT * FROM affiliate_bank_accounts WHERE affiliate_id = ?");
                $stmtBank->execute([$affiliate['id']]);
                $bankAccount = $stmtBank->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            // 6. Get payout history (Resilient)
            $payoutHistory = [];
            try {
                $stmtPayout = $conn->prepare("SELECT * FROM affiliate_payouts WHERE affiliate_id = ? ORDER BY id DESC LIMIT 10");
                $stmtPayout->execute([$affiliate['id']]);
                $payoutHistory = $stmtPayout->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            // Success response with all parts
            echo json_encode([
                'success' => true,
                'affiliate' => $affiliate,
                'stats' => $stats,
                'recentReferrals' => $recentReferrals,
                'bankAccount' => $bankAccount,
                'payoutHistory' => $payoutHistory
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false, 
                'message' => 'API Error: ' . $e->getMessage(),
                'line' => $e->getLine()
            ]);
        }
        exit;
    }

    if ($action === 'check_referral_code') {
        $code = $_GET['code'] ?? '';
        if (!$code) { echo json_encode(['success' => false, 'message' => 'Code required']); exit; }
        try {
            $nameCol = getUserNameColumn($conn);
            $stmt = $conn->prepare("SELECT au.*, u.$nameCol as affiliate_name FROM affiliate_users au LEFT JOIN users u ON au.user_id = u.id WHERE au.affiliate_code = ? AND au.status IN ('active', 'pending')");
            $stmt->execute([$code]);
            $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($affiliate) {
                $conn->prepare("UPDATE affiliate_users SET click_count = click_count + 1 WHERE id = ?")->execute([$affiliate['id']]);
                echo json_encode(['success' => true, 'affiliate' => $affiliate]);
            } else { echo json_encode(['success' => false, 'message' => 'Invalid code']); }
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    if ($action === 'get_leaderboard') {
        try {
            $nameCol = getUserNameColumn($conn);
            $stmt = $conn->prepare("SELECT u.$nameCol as name, IFNULL(au.referral_count, 0) as referral_count, IFNULL(au.total_earnings, 0) as total_earnings, u.profile_picture FROM affiliate_users au JOIN users u ON au.user_id = u.id WHERE au.status = 'active' ORDER BY au.total_earnings DESC LIMIT 10");
            $stmt->execute();
            $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($leaderboard as &$entry) {
                $names = explode(' ', $entry['name']);
                $entry['display_name'] = count($names) > 1 ? $names[0] . ' ' . substr($names[1], 0, 1) . '.' : $entry['name'];
                unset($entry['name']);
            }
            echo json_encode(['success' => true, 'leaderboard' => $leaderboard]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }
} elseif ($method === 'POST') {
    $params = getPostParams();
    $action = $params['action'] ?? '';
    validate_csrf();
    
    if ($action === 'become_affiliate') {
        $userId = $params['user_id'] ?? null;
        if (!$userId) { echo json_encode(['success' => false, 'message' => 'ID required']); exit; }
        try {
            $stmt = $conn->prepare("SELECT id, status FROM affiliate_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                echo json_encode(['success' => false, 'message' => $existing['status'] === 'pending' ? 'Application pending.' : 'Already an affiliate.']);
                exit;
            }
            $stmt = $conn->prepare("SELECT plan FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userPlan = $stmt->fetchColumn();
            $isStudent = in_array($userPlan, ['pro', 'elite', 'premium']);
            $status = $isStudent ? 'active' : 'pending';
            $rate = getCommissionRate($conn, $userId);
            $code = generateAffiliateCode($conn);
            $link = generateAffiliateLink($code);
            $stmt = $conn->prepare("INSERT INTO affiliate_users (user_id, affiliate_code, affiliate_link, commission_rate, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $code, $link, $rate, $status]);
            echo json_encode(['success' => true, 'message' => $isStudent ? 'Success! Partner account active.' : 'Success! Application pending.']);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }
}
?>