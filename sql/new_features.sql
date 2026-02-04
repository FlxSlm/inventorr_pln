-- new_features.sql
-- New features: Multiple images, Forgot password, Change password

-- Table for multiple inventory images
CREATE TABLE IF NOT EXISTS inventory_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for password reset requests with email verification
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(150) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reset_token VARCHAR(64) NULL,
    token_expires_at TIMESTAMP NULL,
    new_password_hash VARCHAR(255) NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    processed_by INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add reset_token column if table already exists
ALTER TABLE password_reset_requests 
ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) NULL AFTER status,
ADD COLUMN IF NOT EXISTS token_expires_at TIMESTAMP NULL AFTER reset_token;

-- Add index for faster queries
CREATE INDEX IF NOT EXISTS idx_password_reset_status ON password_reset_requests(status);
CREATE INDEX IF NOT EXISTS idx_password_reset_user ON password_reset_requests(user_id);
CREATE INDEX IF NOT EXISTS idx_password_reset_token ON password_reset_requests(reset_token);
CREATE INDEX IF NOT EXISTS idx_inventory_images_primary ON inventory_images(inventory_id, is_primary);

-- Add images_json column to material_suggestions for multiple images
ALTER TABLE material_suggestions 
ADD COLUMN IF NOT EXISTS images_json TEXT NULL AFTER image;
