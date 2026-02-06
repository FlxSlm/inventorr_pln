<?php
// run_seed_loans.php
// Simple runner to execute sql/seed_loans_50.sql using app/config/database.php
// Usage (from project root): php sql/run_seed_loans.php

// Updated runner: find a valid user and inventory, then insert 50 seed loans via PDO
$pdo = require __DIR__ . '/../app/config/database.php';

// Configurable target inventory id (user requested inventory_id = 30)
$inventoryId = 28;
$countToInsert = 300;

try {
    // Verify inventory exists
    $st = $pdo->prepare('SELECT id FROM inventories WHERE id = ?');
    $st->execute([$inventoryId]);
    $inv = $st->fetch();
    if (!$inv) {
        echo "❌ Inventory id {$inventoryId} not found. Aborting.\n";
        exit(1);
    }

    // Use requested user id = 7 (ensure it exists)
    $userId = 7;
    $st = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $st->execute([$userId]);
    if (!$st->fetch()) {
        echo "❌ User id {$userId} not found in `users` table. Aborting.\n";
        exit(1);
    }

    // Prepare insert
    $insert = $pdo->prepare('INSERT INTO loans (group_id, inventory_id, user_id, quantity, note, requested_at) VALUES (?, ?, ?, ?, ?, ?)');

    $pdo->beginTransaction();
    for ($i = 1; $i <= $countToInsert; $i++) {
        // unique group_id per row as requested
        $groupId = bin2hex(random_bytes(16));
        $quantity = ($i % 3) + 1; // 1..3
        $note = 'Seed loan ' . $i;
        $requestedAt = (new DateTime())->modify("-{$i} minutes")->format('Y-m-d H:i:s');

        $insert->execute([$groupId, $inventoryId, $userId, $quantity, $note, $requestedAt]);
    }
    $pdo->commit();
    echo "✅ Inserted {$countToInsert} seed loans for inventory_id={$inventoryId} and user_id={$userId}.\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Failed to insert seed loans: " . $e->getMessage() . "\n";
    exit(1);
}

?>