-- Settings table for configurable values
CREATE TABLE IF NOT EXISTS settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT NOT NULL,
  description VARCHAR(255),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default low stock threshold
INSERT INTO settings (setting_key, setting_value, description) 
VALUES ('low_stock_threshold', '5', 'Batas minimum stok untuk dikategorikan sebagai stok menipis')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Add year column to inventories table
ALTER TABLE inventories ADD COLUMN IF NOT EXISTS year_acquired VARCHAR(4) DEFAULT NULL AFTER unit;
