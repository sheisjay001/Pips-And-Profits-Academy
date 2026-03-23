<?php
require_once 'db_connect.php';

try {
    // 1. Find soteriamaa@gmail.com
    $email_affiliate = 'soteriamaa@gmail.com';
    $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE email = ?");
    $stmt->execute([$email_affiliate]);
    $affiliate_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$affiliate_user) {
        die("Affiliate user ($email_affiliate) not found in users table.\n");
    }
    echo "Affiliate User: {$affiliate_user['name']} (ID: {$affiliate_user['id']})\n";

    // 2. Find affiliate record
    $stmt = $conn->prepare("SELECT id, affiliate_code, status, referral_count FROM affiliate_users WHERE user_id = ?");
    $stmt->execute([$affiliate_user['id']]);
    $affiliate_record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$affiliate_record) {
        die("User is not registered as an affiliate.\n");
    }
    echo "Affiliate Record ID: {$affiliate_record['id']}, Code: {$affiliate_record['affiliate_code']}, Status: {$affiliate_record['status']}, Referral Count: {$affiliate_record['referral_count']}\n";

    // 3. Find joyrobertauta@gmail.com
    $email_referral = 'joyrobertauta@gmail.com';
    $stmt = $conn->prepare("SELECT id, name, email, referral_code_used, referred_by_affiliate_id FROM users WHERE email = ?");
    $stmt->execute([$email_referral]);
    $referral_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$referral_user) {
        echo "Referral User ($email_referral) NOT FOUND in users table.\n";
    } else {
        echo "Referral User: {$referral_user['name']} (ID: {$referral_user['id']}), Code Used: {$referral_user['referral_code_used']}, Referred By ID: {$referral_user['referred_by_affiliate_id']}\n";
        
        // Check if there's a record in affiliate_referrals
        $stmt = $conn->prepare("SELECT * FROM affiliate_referrals WHERE referred_user_id = ?");
        $stmt->execute([$referral_user['id']]);
        $referral_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($referral_record) {
            echo "Referral Record in affiliate_referrals: ID: {$referral_record['id']}, Affiliate ID: {$referral_record['affiliate_id']}, Status: {$referral_record['status']}\n";
        } else {
            echo "NO RECORD found in affiliate_referrals for this user.\n";
        }
    }

    // 4. List all referrals for this affiliate
    echo "\nAll Referrals for this Affiliate in affiliate_referrals table:\n";
    $stmt = $conn->prepare("SELECT ar.id, u.email, ar.status, ar.signup_date FROM affiliate_referrals ar JOIN users u ON ar.referred_user_id = u.id WHERE ar.affiliate_id = ?");
    $stmt->execute([$affiliate_record['id']]);
    $all_referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($all_referrals)) {
        echo "No referrals found.\n";
    } else {
        foreach ($all_referrals as $r) {
            echo "ID: {$r['id']} | Email: {$r['email']} | Status: {$r['status']} | Signup: {$r['signup_date']}\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>