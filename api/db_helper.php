<?php
/**
 * DB Helper to handle schema variations (username vs name, etc.)
 * Enhanced version for better reliability on Vercel/Serverless
 */

/**
 * Get the correct user display name column
 */
function getUserNameColumn($conn) {
    static $column = null;
    if ($column !== null) return $column;

    try {
        // More reliable than SHOW COLUMNS: Fetch one row and check keys
        $stmt = $conn->query("SELECT * FROM users LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            if (array_key_exists('username', $row)) {
                $column = 'username';
            } elseif (array_key_exists('name', $row)) {
                $column = 'name';
            }
        }
        
        // Fallback to SHOW COLUMNS if table is empty
        if (!$column) {
            $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'username'");
            if ($stmt->rowCount() > 0) {
                $column = 'username';
            } else {
                $column = 'name';
            }
        }
    } catch (Exception $e) {
        $column = 'name'; // Ultimate fallback
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
        $stmt = $conn->query("SELECT * FROM users LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            if (array_key_exists('role_id', $row)) {
                $column = 'role_id';
            } elseif (array_key_exists('role', $row)) {
                $column = 'role';
            }
        }

        if (!$column) {
            $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'role_id'");
            if ($stmt->rowCount() > 0) {
                $column = 'role_id';
            } else {
                $column = 'role';
            }
        }
    } catch (Exception $e) {
        $column = 'role';
    }
    return $column;
}
?>
