<?php
/**
 * Script to update affiliate link for Joy O Auta (ID: 30002)
 */
require_once 'db_connect.php';

$userId = 30002;
$code = '923E7966';

echo "<h2>Updating Affiliate Link for Joy O Auta</h2>";

try {
    // Generate the correct link
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $protocol = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'pips-and-profits-academy.vercel.app';
    $newLink = $protocol . '://' . $host . '/register.html?ref=' . $code;

    echo "<p>New Link generated: <code>$newLink</code></p>";

    // Update the database
    $stmt = $conn->prepare("UPDATE affiliate_users SET affiliate_link = ? WHERE user_id = ? AND affiliate_code = ?");
    $stmt->execute([$newLink, $userId, $code]);

    if ($stmt->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Successfully updated the affiliate link in the database!</p>";
    } else {
        echo "<p style='color: orange;'>No changes made. The link might already be correct or the user/code was not found.</p>";
    }

    // Verify
    $stmt = $conn->prepare("SELECT affiliate_link FROM affiliate_users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $current = $stmt->fetchColumn();
    echo "<p>Current link in DB: <code>$current</code></p>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
