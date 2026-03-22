<?php
/**
 * Script to ensure soteriamaa@gmail.com has an affiliate application
 */
require_once 'db_connect.php';

$email = 'soteriamaa@gmail.com';

echo "<h2>Fixing Affiliate Request for: $email</h2>";

try {
    // 1. Get user ID
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die("<p style='color: red;'>User not found with email: $email</p>");
    }

    $userId = $user['id'];
    echo "<p>User ID found: $userId</p>";

    // 2. Check if already an affiliate
    $stmt = $conn->prepare("SELECT id, status FROM affiliate_users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['status'] === 'pending') {
            echo "<p style='color: orange;'>Request already exists and is pending approval.</p>";
        } else {
            echo "<p style='color: green;'>User is already an active affiliate.</p>";
        }
    } else {
        // 3. Create a pending affiliate request
        // First generate a code and link
        require_once 'affiliate.php';
        $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $protocol = $isHttps ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
        $link = $baseUrl . '/register.html?ref=' . $code;

        $stmt = $conn->prepare("
            INSERT INTO affiliate_users (user_id, affiliate_code, affiliate_link, commission_rate, status) 
            VALUES (?, ?, ?, 50.00, 'pending')
        ");
        $stmt->execute([$userId, $code, $link]);
        
        echo "<p style='color: green;'>✓ Successfully created a pending affiliate request for $email.</p>";
        echo "<p>It will now appear in the admin dashboard for approval.</p>";
    }

} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
