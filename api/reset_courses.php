<?php
// api/reset_courses.php
// This script is used to quickly wipe all courses from the database.
// Useful for development or resetting the platform.

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once 'db_connect.php';

    // Delete all progress first to satisfy FK constraints
    $conn->exec("DELETE FROM user_progress");
    $conn->exec("DELETE FROM courses");
    
    echo json_encode(['success' => true, 'message' => 'All courses and progress deleted successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>