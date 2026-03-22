-- Affiliate System Database Schema
-- Add these tables to your existing database

-- Affiliate Users Table
CREATE TABLE IF NOT EXISTS affiliate_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    affiliate_code VARCHAR(20) NOT NULL UNIQUE,
    affiliate_link VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('pending', 'active', 'suspended') DEFAULT 'pending',
    commission_rate DECIMAL(5,2) DEFAULT 10.00,
    total_earnings DECIMAL(10,2) DEFAULT 0.00,
    total_withdrawn DECIMAL(10,2) DEFAULT 0.00,
    current_balance DECIMAL(10,2) DEFAULT 0.00,
    referral_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Affiliate Referrals Table
CREATE TABLE IF NOT EXISTS affiliate_referrals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT NOT NULL,
    referred_user_id INT NOT NULL,
    referral_code VARCHAR(20) NOT NULL,
    commission_amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
    signup_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confirmation_date TIMESTAMP NULL,
    FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE,
    FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_referral (affiliate_id, referred_user_id)
);

-- Affiliate Payouts Table
CREATE TABLE IF NOT EXISTS affiliate_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'cancelled') DEFAULT 'pending',
    payout_date DATE NOT NULL,
    processed_date TIMESTAMP NULL,
    transaction_id VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE
);

-- Affiliate Bank Accounts Table
CREATE TABLE IF NOT EXISTS affiliate_bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT NOT NULL UNIQUE,
    bank_name VARCHAR(100) NOT NULL,
    account_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    routing_number VARCHAR(50) NULL,
    swift_code VARCHAR(50) NULL,
    country VARCHAR(50) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    is_default BOOLEAN DEFAULT TRUE,
    is_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE
);

-- Commission Rates by User Plan
CREATE TABLE IF NOT EXISTS commission_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_plan ENUM('free', 'pro', 'elite') NOT NULL UNIQUE,
    commission_rate DECIMAL(5,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default commission rates
INSERT INTO commission_rates (user_plan, commission_rate) VALUES 
('free', 5.00),
('pro', 10.00),
('elite', 15.00);

-- Update users table to include referral tracking
ALTER TABLE users 
ADD COLUMN referred_by_affiliate_id INT NULL,
ADD COLUMN referral_code_used VARCHAR(20) NULL,
ADD COLUMN signup_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Add indexes for better performance
CREATE INDEX idx_affiliate_code ON affiliate_users(affiliate_code);
CREATE INDEX idx_affiliate_link ON affiliate_users(affiliate_link);
CREATE INDEX idx_referral_status ON affiliate_referrals(status);
CREATE INDEX idx_payout_status ON affiliate_payouts(status);
CREATE INDEX idx_payout_date ON affiliate_payouts(payout_date);

-- Affiliate Transactions Table
CREATE TABLE IF NOT EXISTS affiliate_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT NOT NULL,
    referral_id INT NULL,
    transaction_type ENUM('commission', 'withdrawal', 'bonus', 'penalty') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT NULL,
    payment_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (affiliate_id) REFERENCES affiliate_users(id) ON DELETE CASCADE,
    FOREIGN KEY (referral_id) REFERENCES affiliate_referrals(id) ON DELETE SET NULL
);

-- Add index for transactions
CREATE INDEX idx_transaction_type ON affiliate_transactions(transaction_type);
CREATE INDEX idx_transaction_date ON affiliate_transactions(created_at);
