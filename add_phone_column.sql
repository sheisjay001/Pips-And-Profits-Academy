-- Add phone number column to users table
ALTER TABLE users ADD COLUMN phone VARCHAR(20) AFTER password_hash;
