<?php
/**
 * DB Helper to handle schema variations (username vs name, etc.)
 */

/**
 * Get the correct user display name column
 */
function getUserNameColumn($conn) {
    static $column = null;
    if ($column !== null) return $column;

    try {
        $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'username'");
        if ($stmt->rowCount() > 0) {
            $column = 'username';
        } else {
            $column = 'name';
        }
    } catch (Exception $e) {
        $column = 'name'; // Default fallback
    }
    return $column;
}

/**
 * Get the correct role column
 */
function getRoleColumn($conn) {
    static $column = null;
    if ($column !== null) return $column;

    try {
        $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'role_id'");
        if ($stmt->rowCount() > 0) {
            $column = 'role_id';
        } else {
            $column = 'role';
        }
    } catch (Exception $e) {
        $column = 'role'; // Default fallback
    }
    return $column;
}
?>
