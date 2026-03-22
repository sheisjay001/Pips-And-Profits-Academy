<?php
require_once 'api/db_connect.php';

echo "<h2>Updating Commission Rates to 50%</h2>";

try {
    // 1. Update existing commission_rates table
    $stmt = $conn->prepare("UPDATE commission_rates SET commission_rate = 50.00");
    $stmt->execute();
    echo "<p style='color: green;'>✓ Updated all rows in <strong>commission_rates</strong> table to 50%.</p>";

    // 2. Update existing affiliate_users who might have been created with old rates
    $stmt = $conn->prepare("UPDATE affiliate_users SET commission_rate = 50.00");
    $stmt->execute();
    echo "<p style='color: green;'>✓ Updated all existing <strong>affiliate_users</strong> to 50% commission rate.</p>";

    echo "<h3 style='color: green;'>Success!</h3>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
