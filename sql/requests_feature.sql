-- SQL for requests feature and other modifications
-- Run this script to add the required database changes

-- Add low_stock_threshold per item in inventories table
ALTER TABLE inventories ADD COLUMN IF NOT EXISTS low_stock_threshold INT DEFAULT 5;

-- Create requests table (similar to loans but for permanent item requests)
CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    user_id INT NOT NULL,
    quantity INT NOT NULL,
    status ENUM('pending','approved','rejected','completed') DEFAULT 'pending',
    stage ENUM('pending','awaiting_document','submitted','approved','rejected') DEFAULT 'pending',
    note TEXT,
    rejection_note TEXT,
    document_path VARCHAR(255) DEFAULT NULL,
    document_submitted_at TIMESTAMP NULL DEFAULT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (inventory_id) REFERENCES inventories(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create templates table for managing document templates
CREATE TABLE IF NOT EXISTS document_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_type ENUM('loan','return','request') NOT NULL,
    template_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default templates
INSERT IGNORE INTO document_templates (template_type, template_name, file_path, description, is_active) VALUES
('loan', 'Template Peminjaman', 'templates/BA STM ULTG GORONTALO.xlsx', 'Template dokumen serah terima peminjaman', 1),
('return', 'Template Pengembalian', 'templates/PENGEMBALIAN.xlsx', 'Template dokumen pengembalian barang', 1),
('request', 'Template Permintaan', 'templates/PERMINTAAN.xlsx', 'Template dokumen permintaan barang', 1);

-- Update existing inventories with default threshold
UPDATE inventories SET low_stock_threshold = 5 WHERE low_stock_threshold IS NULL;
