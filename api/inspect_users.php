<?php
require_once 'api/db_connect.php';
try {
    $stmt = $conn->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($columns, true) . "</pre>";
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
