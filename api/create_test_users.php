<?php
require_once __DIR__ . '/db_connect.php';

try {
    $emailPro = 'test.pro@pipsacademy.local';
    $emailElite = 'test.oneonone@pipsacademy.local';

    $passwordHash = password_hash('TestPass123!', PASSWORD_DEFAULT);

    $conn->prepare("DELETE FROM users WHERE email IN (?, ?)")->execute([$emailPro, $emailElite]);

    $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, role, plan, email_verified) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->execute(['Test Pro User', $emailPro, $passwordHash, 'user', 'pro']);
    $stmt->execute(['Test One-on-One User', $emailElite, $passwordHash, 'user', 'elite']);

    echo "Created test users:\n";
    echo "Pro: $emailPro / TestPass123!\n";
    echo "One-on-One: $emailElite / TestPass123!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
