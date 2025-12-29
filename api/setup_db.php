<?php
/**
 * Setup Database Script
 * Runs the schema creation against the connected database (TiDB/MySQL)
 */

header('Content-Type: application/json');
require_once 'db_connect.php';

try {
    // 1. Users Table (includes plan)
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('user', 'admin') DEFAULT 'user',
        plan ENUM('free', 'pro', 'elite') DEFAULT 'free',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Signals Table
    $conn->exec("CREATE TABLE IF NOT EXISTS signals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pair VARCHAR(20) NOT NULL,
        type ENUM('BUY', 'SELL') NOT NULL,
        entry_price DECIMAL(10, 5) NOT NULL,
        stop_loss DECIMAL(10, 5) NOT NULL,
        take_profit DECIMAL(10, 5) NOT NULL,
        status ENUM('Pending', 'Running', 'Profit', 'Loss') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 3. Courses Table
    $conn->exec("CREATE TABLE IF NOT EXISTS courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        level ENUM('Beginner', 'Intermediate', 'Advanced') NOT NULL,
        thumbnail_url VARCHAR(255),
        video_path VARCHAR(255),
        price DECIMAL(10, 2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 4. User Progress Table
    $conn->exec("CREATE TABLE IF NOT EXISTS user_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        course_id INT NOT NULL,
        progress_percentage INT DEFAULT 0,
        last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    )");

    // Ensure 'video_path' exists
    try {
        $conn->query("SELECT video_path FROM courses LIMIT 1");
    } catch (Exception $e) {
        $conn->exec("ALTER TABLE courses ADD COLUMN video_path VARCHAR(255)");
    }

    // 5. Password Resets Table
    $conn->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX(token)
    )");

    // Ensure 'plan' column exists on existing installations
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'plan'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("ALTER TABLE users ADD COLUMN plan ENUM('free','pro','elite') DEFAULT 'free'");
    }

    // Seed Initial Data (admin only; no mock signals)
    
    // Check if admin exists
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE email = 'admin@pips.com'");
    if ($stmt->fetchColumn() == 0) {
        // Create Admin (Password: admin123)
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, role, plan) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Admin User', 'admin@pips.com', $password, 'admin', 'elite']);
    }

    // RESET POLICY: Ensure all non-admin users are on the FREE plan until upgraded
    // This cleans up any legacy data or manual changes
    $conn->exec("UPDATE users SET plan = 'free' WHERE role != 'admin' AND plan IS NULL"); 
    // Or if we want to enforce it strictly for everyone right now:
    $conn->exec("UPDATE users SET plan = 'free' WHERE role != 'admin'");

    // Remove mock seeding: no default signals inserted

    echo json_encode(['success' => true, 'message' => 'Database tables created (admin seeded).']);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Setup failed: ' . $e->getMessage()]);
}
?>
