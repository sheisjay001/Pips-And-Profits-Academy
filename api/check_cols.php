<?php
require_once 'api/db_connect.php';
try {
    $stmt = $conn->query("SHOW COLUMNS FROM users");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns in users table: " . implode(", ", $cols);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
