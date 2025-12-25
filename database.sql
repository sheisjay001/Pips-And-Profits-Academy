-- Pips and Profit Academy Database Schema
-- Run this SQL script in phpMyAdmin or your MySQL client to create the database structure

CREATE DATABASE IF NOT EXISTS pips_profit_academy;
USE pips_profit_academy;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Signals Table
CREATE TABLE IF NOT EXISTS signals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pair VARCHAR(20) NOT NULL,
    type ENUM('BUY', 'SELL') NOT NULL,
    entry_price DECIMAL(10, 5) NOT NULL,
    stop_loss DECIMAL(10, 5) NOT NULL,
    take_profit DECIMAL(10, 5) NOT NULL,
    status ENUM('Pending', 'Running', 'Profit', 'Loss') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Courses Table
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    level ENUM('Beginner', 'Intermediate', 'Advanced') NOT NULL,
    thumbnail_url VARCHAR(255),
    price DECIMAL(10, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- User Progress Table
CREATE TABLE IF NOT EXISTS user_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    progress_percentage INT DEFAULT 0,
    last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

-- Initial Admin User (Password: admin123)
-- Note: In a real app, you must hash passwords using password_hash() in PHP
INSERT INTO users (name, email, password_hash, role) VALUES 
('Admin User', 'admin@pips.com', '$2y$10$YourHashedPasswordHere', 'admin');

-- Sample Signals
INSERT INTO signals (pair, type, entry_price, stop_loss, take_profit, status) VALUES 
('EUR/USD', 'BUY', 1.0850, 1.0820, 1.0900, 'Profit'),
('GBP/JPY', 'SELL', 182.40, 182.80, 181.50, 'Running');

-- Sample Courses
INSERT INTO courses (title, description, level, thumbnail_url) VALUES 
('Forex Basics 101', 'Introduction to currency trading.', 'Beginner', 'https://images.unsplash.com/photo-1611974765270-ca1258634369?w=400'),
('Technical Analysis Masterclass', 'Chart patterns and indicators.', 'Intermediate', 'https://images.unsplash.com/photo-1590283603385-17ffb3a7f29f?w=400');
