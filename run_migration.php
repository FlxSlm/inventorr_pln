<?php
// Quick migration runner to add missing columns
$pdo = require __DIR__ . '/app/config/database.php';

try {
    // Add columns to loans table
    $pdo->exec("ALTER TABLE loans ADD COLUMN IF NOT EXISTS admin_document_path VARCHAR(255) DEFAULT NULL");
    echo "✓ Added admin_document_path to loans table\n";
    
    $pdo->exec("ALTER TABLE loans ADD COLUMN IF NOT EXISTS return_admin_document_path VARCHAR(255) DEFAULT NULL");
    echo "✓ Added return_admin_document_path to loans table\n";
    
    // Add column to requests table
    $pdo->exec("ALTER TABLE requests ADD COLUMN IF NOT EXISTS admin_document_path VARCHAR(255) DEFAULT NULL");
    echo "✓ Added admin_document_path to requests table\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "You can now delete this file: run_migration.php\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
