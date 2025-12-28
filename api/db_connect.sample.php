<?php
/**
 * Database Connection Config
 * Compatible with MySQL and TiDB
 */

// --- Configuration ---
// If using TiDB Cloud, these values are provided in the "Connect" dialog.
$host = '127.0.0.1';     // TiDB Host (e.g., gateway01.us-west-2.prod.aws.tidbcloud.com)
$port = '4000';          // TiDB Port is usually 4000. XAMPP MySQL is 3306.
$db_name = 'test';
$username = 'root';      // TiDB Username
$password = '';          // TiDB Password
$ssl_ca = '';            // Path to CA certificate (required for TiDB Cloud), e.g., 'ca.pem'

// ---------------------

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4";
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    // Add SSL options if a CA certificate is provided (Common for TiDB Cloud)
    if (!empty($ssl_ca) && file_exists($ssl_ca)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl_ca;
        // $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false; // Enable if needed
    } elseif ($port == '4000') {
        // Fallback if CA not found but we need SSL (might fail if server requires trusted cert)
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $conn = new PDO($dsn, $username, $password, $options);
    
} catch(PDOException $e) {
    error_log("Connection failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Check logs.']);
    exit;
}
?>