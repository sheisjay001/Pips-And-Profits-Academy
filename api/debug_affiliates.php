<?php
require_once 'api/db_connect.php';

echo "<h2>Affiliate Debug Tool</h2>";

try {
    // 1. Show all affiliate_users
    echo "<h3>1. affiliate_users table content:</h3>";
    $stmt = $conn->query("SELECT * FROM affiliate_users");
    $affiliates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($affiliates)) {
        echo "<p>No records in affiliate_users table.</p>";
    } else {
        echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>User ID</th><th>Code</th><th>Status</th></tr>";
        foreach ($affiliates as $row) {
            echo "<tr><td>{$row['id']}</td><td>{$row['user_id']}</td><td>{$row['affiliate_code']}</td><td>{$row['status']}</td></tr>";
        }
        echo "</table>";
    }

    // 2. Show the specific user we are looking for
    $email = 'soteriamaa@gmail.com';
    echo "<h3>2. Searching for user: $email</h3>";
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "<p>User Found! ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}</p>";
        
        // 3. Test the exact query used in the admin dashboard
        echo "<h3>3. Testing Dashboard JOIN Query:</h3>";
        $stmt = $conn->prepare("
            SELECT au.*, u.name, u.email 
            FROM affiliate_users au 
            JOIN users u ON au.user_id = u.id 
            WHERE au.user_id = ?
        ");
        $stmt->execute([$user['id']]);
        $joined = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($joined) {
            echo "<p style='color: green;'>✓ JOIN Success! Record should appear in dashboard.</p>";
            echo "<pre>" . print_r($joined, true) . "</pre>";
        } else {
            echo "<p style='color: red;'>✗ JOIN Failed! Check if user_id in affiliate_users matches users table ID.</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ User not found in 'users' table with email: $email</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
