-- suggestions_notifications.sql
-- Schema untuk fitur Usulan Material dan sistem notifikasi

USE `inventory_db`;

-- Table untuk menyimpan usulan material dari karyawan
CREATE TABLE IF NOT EXISTS `material_suggestions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `category_id` INT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('unread', 'read', 'replied') DEFAULT 'unread',
  `admin_reply` TEXT NULL,
  `replied_by` INT NULL,
  `replied_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`replied_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
);

-- Table untuk notifikasi umum
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `type` ENUM('loan_approved', 'loan_rejected', 'document_requested', 'return_approved', 'return_rejected', 'suggestion_reply', 'general') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `reference_id` INT NULL COMMENT 'ID referensi (loan_id atau suggestion_id)',
  `reference_type` VARCHAR(50) NULL COMMENT 'Tipe referensi: loan, suggestion, etc',
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

-- Tambah kolom rejection_note untuk menyimpan alasan penolakan
ALTER TABLE `loans` 
ADD COLUMN IF NOT EXISTS `rejection_note` TEXT NULL AFTER `note`,
ADD COLUMN IF NOT EXISTS `return_rejection_note` TEXT NULL AFTER `return_note`;

-- Index untuk performa query
CREATE INDEX IF NOT EXISTS `idx_notifications_user_read` ON `notifications`(`user_id`, `is_read`);
CREATE INDEX IF NOT EXISTS `idx_suggestions_status` ON `material_suggestions`(`status`);
CREATE INDEX IF NOT EXISTS `idx_suggestions_user` ON `material_suggestions`(`user_id`);
