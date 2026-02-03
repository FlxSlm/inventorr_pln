-- document_numbering.sql
-- Auto-increment document numbering with monthly reset

USE `inventory_db`;

-- Table to track document numbers for each type and month
CREATE TABLE IF NOT EXISTS `document_numbers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_type` ENUM('loan', 'return', 'request') NOT NULL,
    `year` SMALLINT NOT NULL,
    `month` TINYINT NOT NULL,
    `last_number` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_type_year_month` (`document_type`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table to store generated documents for transactions
CREATE TABLE IF NOT EXISTS `generated_documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_type` ENUM('loan', 'return', 'request') NOT NULL,
    `reference_id` VARCHAR(50) NOT NULL COMMENT 'loan group_id/id, request group_id/id, or return group_id/loan_id',
    `document_number` VARCHAR(100) NOT NULL,
    `file_path` VARCHAR(255) NULL COMMENT 'Path to uploaded final document',
    `generated_data` JSON NULL COMMENT 'Stored auto-fill data for the document',
    `status` ENUM('generated', 'uploaded', 'sent') DEFAULT 'generated',
    `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `uploaded_at` TIMESTAMP NULL DEFAULT NULL,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_doc_type_ref` (`document_type`, `reference_id`),
    INDEX `idx_doc_number` (`document_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Function to get Roman numeral for month
DELIMITER //
CREATE FUNCTION IF NOT EXISTS `get_roman_month`(month_num INT) 
RETURNS VARCHAR(5)
DETERMINISTIC
BEGIN
    DECLARE roman VARCHAR(5);
    CASE month_num
        WHEN 1 THEN SET roman = 'I';
        WHEN 2 THEN SET roman = 'II';
        WHEN 3 THEN SET roman = 'III';
        WHEN 4 THEN SET roman = 'IV';
        WHEN 5 THEN SET roman = 'V';
        WHEN 6 THEN SET roman = 'VI';
        WHEN 7 THEN SET roman = 'VII';
        WHEN 8 THEN SET roman = 'VIII';
        WHEN 9 THEN SET roman = 'IX';
        WHEN 10 THEN SET roman = 'X';
        WHEN 11 THEN SET roman = 'XI';
        WHEN 12 THEN SET roman = 'XII';
        ELSE SET roman = '';
    END CASE;
    RETURN roman;
END//
DELIMITER ;
