<?php
/**
 * Database Connection Config
 * Compatible with MySQL and TiDB
 * 
 * This file automatically switches between:
 * 1. Local Development (loading db_connect.local.php)
 * 2. Production/Vercel (loading from Environment Variables)
 */

// 1. Try to load local configuration if it exists (for XAMPP/Local Dev)
if (file_exists(__DIR__ . '/db_connect.local.php')) {
    include __DIR__ . '/db_connect.local.php';
    return;
}

// 2. Production / Vercel Environment Configuration
$host = getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '4000';
$db_name = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

// We bundle the cacert.pem in the api directory for production
$ssl_ca = __DIR__ . '/cacert.pem';

// ---------------------

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4";
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    // Add SSL options
    if (!empty($ssl_ca) && file_exists($ssl_ca)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl_ca;
    } else {
        // Fallback: If cert is missing but we are likely on TiDB (port 4000), disable verify to allow connection
        // (Not recommended for high security, but prevents app breakage)
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $conn = new PDO($dsn, $username, $password, $options);
    
} catch(PDOException $e) {
    // Hide actual credentials in production!
    error_log("Connection failed: " . $e->getMessage());
    // Return JSON error
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}
?>