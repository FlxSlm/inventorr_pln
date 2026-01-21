-- schema.sql (simplified)
CREATE DATABASE IF NOT EXISTS `inventory_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `inventory_db`;

-- users
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','karyawan') NOT NULL DEFAULT 'karyawan',
  phone VARCHAR(30) DEFAULT NULL,
  is_blacklisted TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- inventories
CREATE TABLE inventories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(100) NOT NULL UNIQUE,
  description TEXT, 
  stock_total INT DEFAULT 0,
  stock_available INT DEFAULT 0,
  unit VARCHAR(50),
  image VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  deleted_at TIMESTAMP NULL DEFAULT NULL
);

-- loans (peminjaman)
CREATE TABLE loans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT NOT NULL,
  user_id INT NOT NULL,
  quantity INT NOT NULL,
  status ENUM('pending','approved','rejected','returned') DEFAULT 'pending',
  note TEXT,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  returned_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (inventory_id) REFERENCES inventories(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- bast (simplified) : records of assignment if needed
CREATE TABLE basts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL,
  doc_number VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (loan_id) REFERENCES loans(id)
);

-- seed admin
INSERT INTO users (name, email, password, role) VALUES
('Admin','admin@example.com', '{PASSWORD_HASH}', 'admin');
