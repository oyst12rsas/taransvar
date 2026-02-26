
CREATE DATABASE IF NOT EXISTS wifi_hotspot1;
USE wifi_hotspot1;
grant select, delete, insert on wifi_hotspot1.* to scriptUsrAces3f3;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    last_login DATETIME,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    type ENUM('hourly', 'daily', 'monthly') NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    data_limit VARCHAR(20) NOT NULL,
    speed VARCHAR(50) NOT NULL,
    devices INT NOT NULL DEFAULT 1,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    plan_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    mpesa_code VARCHAR(50) NOT NULL UNIQUE,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    used TINYINT(1) DEFAULT 0,
    used_at DATETIME,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL UNIQUE,
    user_id INT,
    phone VARCHAR(20) NOT NULL,
    transaction_id INT NOT NULL,
    plan_id INT NOT NULL,
    ip_address VARCHAR(45),
    mac_address VARCHAR(20),
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    user_id INT,
    phone VARCHAR(20) NOT NULL,
    data_used BIGINT DEFAULT 0,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO plans (name, description, type, price, data_limit, speed, devices, status, created_at) VALUES
('Basic Hour', 'For quick browsing sessions', 'hourly', 20, '100MB', 'Standard', 1, 'active', NOW()),
('Premium Hour', 'For intensive usage', 'hourly', 50, '300MB', 'High', 2, 'active', NOW()),
('Basic Day', 'For casual daily use', 'daily', 100, '500MB', 'Standard', 2, 'active', NOW()),
('Premium Day', 'For heavy daily usage', 'daily', 200, '1.5GB', 'High', 3, 'active', NOW()),
('Basic Month', 'For regular users', 'monthly', 1000, '10GB', 'Standard', 2, 'active', NOW()),
('Premium Month', 'For power users', 'monthly', 2500, '30GB', 'High', 5, 'active', NOW());
