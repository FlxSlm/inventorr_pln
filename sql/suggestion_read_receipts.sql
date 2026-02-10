-- suggestion_read_receipts.sql
-- Track which admins have viewed each suggestion

USE `inventory_db`;

CREATE TABLE IF NOT EXISTS `suggestion_views` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `suggestion_id` INT NOT NULL,
    `admin_id` INT NOT NULL,
    `viewed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`suggestion_id`) REFERENCES `material_suggestions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_view` (`suggestion_id`, `admin_id`)
);

CREATE INDEX IF NOT EXISTS `idx_suggestion_views_suggestion` ON `suggestion_views`(`suggestion_id`);
