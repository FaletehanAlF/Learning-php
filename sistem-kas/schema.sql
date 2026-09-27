-- ============================================================
-- Sistem Informasi Pengelolaan Uang Kas
-- Database: MySQL 8.0+ (file ini)
-- Dev fallback: SQLite (dibuat otomatis via install.php)
-- ============================================================
CREATE DATABASE IF NOT EXISTS kas_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kas_db;

-- Users table (3 role: admin, kasir, supervisor)
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL UNIQUE,
  email VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'kasir', 'supervisor') NOT NULL DEFAULT 'kasir',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role),
  INDEX idx_users_email (email)
) ENGINE=InnoDB;

-- Categories
CREATE TABLE IF NOT EXISTS categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  type ENUM('income', 'expense') NOT NULL,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_category_type_name (type, name),
  INDEX idx_categories_type (type)
) ENGINE=InnoDB;

-- Transactions
CREATE TABLE IF NOT EXISTS transactions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  transaction_number VARCHAR(50) NOT NULL UNIQUE,
  type ENUM('income', 'expense') NOT NULL,
  category_id INT NOT NULL,
  amount DECIMAL(15,2) NOT NULL CHECK (amount > 0),
  description TEXT NULL,
  reference_number VARCHAR(100) NULL UNIQUE,
  attachment VARCHAR(255) NULL COMMENT 'path file bukti di uploads/',
  transaction_date DATE NOT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_trx_type (type),
  INDEX idx_trx_date (transaction_date),
  INDEX idx_trx_category (category_id),
  INDEX idx_trx_created_by (created_by)
) ENGINE=InnoDB;

-- Activity Log
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NULL,
  action VARCHAR(255) NOT NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_logs_user (user_id),
  INDEX idx_logs_created (created_at)
) ENGINE=InnoDB;

-- Password resets (fungsionalitas lupa password)
CREATE TABLE IF NOT EXISTS password_resets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  token VARCHAR(128) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_reset_token (token)
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA
-- Password default (hash via install.php, di bawah hanya contoh):
--   admin / admin@kas.local / admin123 (admin)
--   kasir / kasir@kas.local / kasir123 (kasir)
--   supervisor / spv@kas.local / spv123 (supervisor)
-- ============================================================
INSERT INTO categories (type, name, description) VALUES
  ('income', 'Penjualan', 'Pemasukan dari penjualan barang/jasa'),
  ('income', 'Pinjaman', 'Dana pinjaman masuk'),
  ('income', 'Modal', 'Setoran modal pemilik'),
  ('income', 'Lainnya (Masuk)', 'Pemasukan lain-lain'),
  ('expense', 'Gaji', 'Gaji & honor karyawan'),
  ('expense', 'Utilitas', 'Listrik, air, internet, telepon'),
  ('expense', 'Inventory', 'Belanja stok / bahan baku'),
  ('expense', 'Operasional', 'ATK, transport, konsumsi'),
  ('expense', 'Lainnya (Keluar)', 'Pengeluaran lain-lain')
ON DUPLICATE KEY UPDATE name = VALUES(name);
