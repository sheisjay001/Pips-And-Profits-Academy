<?php
/**
 * Comprehensive Live Diagnostic & Repair Tool
 */
require_once 'session_config.php';
require_once 'db_connect.php';
require_once 'db_helper.php';

echo "<html><body style='font-family: sans-serif; line-height: 1.6; padding: 20px;'>";
echo "<h1 style='color: #6f42c1;'>Academy Diagnostic Tool</h1>";

try {
    $targetEmail = 'soteriamaa@gmail.com';
    $nameCol = getUserNameColumn($conn);
    $roleCol = getRoleColumn($conn);

    echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>";
    echo "<strong>System Info:</strong><br>";
    echo "• Name Column Detected: <code>$nameCol</code><br>";
    echo "• Role Column Detected: <code>$roleCol</code><br>";
    echo "</div>";

    // 1. Check for the specific user
    echo "<h3>1. Checking User: $targetEmail</h3>";
    $stmt = $conn->prepare("SELECT id, $nameCol as name, email, $roleCol as role FROM users WHERE email = ?");
    $stmt->execute([$targetEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo "<p style='color: green;'>✓ User found in database!</p>";
        echo "<ul><li>ID: {$user['id']}</li><li>Name: {$user['name']}</li><li>Role: {$user['role']}</li></ul>";
        
        $userId = $user['id'];

        // 2. Check for affiliate record
        echo "<h3>2. Checking Affiliate Record</h3>";
        $stmt = $conn->prepare("SELECT * FROM affiliate_users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $aff = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($aff) {
            echo "<p style='color: green;'>✓ Affiliate record exists!</p>";
            echo "<ul><li>Affiliate ID: {$aff['id']}</li><li>Code: {$aff['affiliate_code']}</li><li>Status: <strong>{$aff['status']}</strong></li></ul>";
            
            if ($aff['status'] !== 'pending' && $aff['status'] !== 'active') {
                echo "<p style='color: orange;'>Repairing status to 'pending'...</p>";
                $conn->prepare("UPDATE affiliate_users SET status = 'pending' WHERE id = ?")->execute([$aff['id']]);
                echo "<p style='color: green;'>✓ Repaired!</p>";
            }
        } else {
            echo "<p style='color: red;'>✗ No affiliate record found. Creating one now...</p>";
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $link = "https://pips-and-profits-academy.vercel.app/register.html?ref=$code";
            
            $stmt = $conn->prepare("INSERT INTO affiliate_users (user_id, affiliate_code, affiliate_link, commission_rate, status) VALUES (?, ?, ?, 50.00, 'pending')");
            $stmt->execute([$userId, $code, $link]);
            echo "<p style='color: green;'>✓ Successfully created pending application for $targetEmail.</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ User '$targetEmail' NOT FOUND in the main users table.</p>";
        echo "<p>Please ensure the user has actually registered on the live site first.</p>";
    }

    // 3. Show Current Admin
    echo "<h3>3. Current Session Status</h3>";
    if (isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT id, $nameCol as name, $roleCol as role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $curr = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Logged in as: <strong>" . ($curr['name'] ?? 'Unknown') . "</strong> (ID: {$_SESSION['user_id']})</p>";
        echo "<p>Role: <strong>" . ($curr['role'] ?? 'None') . "</strong></p>";
        
        $isAdmin = $curr && ($curr['role'] == 1 || $curr['role'] === 'admin');
        if (!$isAdmin) {
            echo "<p style='color: red;'>Warning: You are logged in, but your account is NOT an admin.</p>";
        }
    } else {
        echo "<p style='color: orange;'>Not logged in to any account in this browser session.</p>";
    }

    // 4. List All Affiliates
    echo "<h3>4. All Affiliates in DB</h3>";
    $stmt = $conn->query("SELECT au.id, au.status, u.$nameCol as name FROM affiliate_users au LEFT JOIN users u ON au.user_id = u.id");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($all) {
        echo "<ul>";
        foreach ($all as $row) {
            echo "<li>" . ($row['name'] ?: 'Unknown User') . " - Status: <strong>{$row['status']}</strong></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No records found in affiliate_users table.</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Diagnostic Error: " . $e->getMessage() . "</p>";
}

echo "<hr><p style='font-size: 0.8em; color: #666;'>Academy Repair Tool v1.0</p>";
echo "</body></html>";
?>
