-- update_schema.sql
-- Tambahan schema untuk fitur kategori/tag dan pengembalian barang

USE `inventory_db`;

-- Table untuk kategori/tag barang
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `color` VARCHAR(20) DEFAULT '#0F75BC',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL
);

-- Relasi many-to-many antara inventories dan categories
CREATE TABLE IF NOT EXISTS `inventory_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `inventory_id` INT NOT NULL,
  `category_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`inventory_id`) REFERENCES `inventories`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_inventory_category` (`inventory_id`, `category_id`)
);

-- Tambah kolom untuk pengembalian di tabel loans
-- return_stage untuk tracking tahap pengembalian
ALTER TABLE `loans` 
ADD COLUMN IF NOT EXISTS `return_stage` ENUM('none','pending_return','awaiting_return_doc','return_submitted','return_approved','return_rejected') DEFAULT 'none',
ADD COLUMN IF NOT EXISTS `return_requested_at` TIMESTAMP NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `return_document_path` VARCHAR(255) NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `return_document_submitted_at` TIMESTAMP NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `return_note` TEXT NULL DEFAULT NULL;

-- Insert beberapa kategori default
INSERT INTO `categories` (`name`, `description`, `color`) VALUES
('Elektronik', 'Barang-barang elektronik seperti laptop, printer, dll', '#3B82F6'),
('ATK', 'Alat tulis kantor', '#10B981'),
('Furnitur', 'Meja, kursi, lemari, dll', '#F59E0B'),
('Kendaraan', 'Mobil, motor, dll', '#EF4444'),
('Peralatan', 'Peralatan kerja umum', '#8B5CF6'),
('Lainnya', 'Barang-barang lainnya', '#6B7280')
ON DUPLICATE KEY UPDATE `name`=`name`;
