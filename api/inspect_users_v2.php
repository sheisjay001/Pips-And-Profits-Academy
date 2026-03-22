<?php
require_once 'api/db_connect.php';
try {
    $stmt = $conn->query("DESCRIBE users");
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
