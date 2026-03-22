<?php
/**
 * Run Affiliate Migration Script (API Version for Vercel)
 * This script will create all necessary affiliate tables
 */

require_once 'db_connect.php';

echo "<h2>Affiliate Migration Script</h2>";

try {
    // Read and execute migration SQL (one level up from /api/)
    $migrationFile = __DIR__ . '/../affiliate_migration.sql';
    if (!file_exists($migrationFile)) {
        die("Error: affiliate_migration.sql file not found at $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    echo "<h3>Executing Migration...</h3>";
    echo "<ul>";
    
    foreach ($statements as $statement) {
        if (empty($statement)) continue;
        
        try {
            $conn->exec($statement);
            echo "<li style='color: green;'>✓ " . htmlspecialchars(substr($statement, 0, 50)) . "...</li>";
        } catch (PDOException $e) {
            echo "<li style='color: red;'>✗ Error: " . $e->getMessage() . "</li>";
            echo "<li>Statement: " . htmlspecialchars($statement) . "</li>";
        }
    }
    
    echo "</ul>";
    echo "<h3 style='color: green;'>Migration completed!</h3>";
    
    // Verify tables were created
    echo "<h3>Verifying Tables...</h3>";
    $tables = ['affiliate_users', 'affiliate_referrals', 'affiliate_payouts', 'affiliate_bank_accounts', 'affiliate_transactions', 'commission_rates'];
    
    echo "<ul>";
    foreach ($tables as $table) {
        try {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result->rowCount() > 0) {
                echo "<li style='color: green;'>✓ Table '$table' exists</li>";
            } else {
                echo "<li style='color: red;'>✗ Table '$table' missing</li>";
            }
        } catch (PDOException $e) {
            echo "<li style='color: red;'>✗ Error checking table '$table': " . $e->getMessage() . "</li>";
        }
    }
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<h3 style='color: red;'>Migration Failed</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
