<?php
// app/admin/save_generated_document.php
// Handler untuk menyimpan dokumen yang sudah digenerate

session_start();
$pdo = require __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Method not allowed';
    header('Location: /index.php?page=admin_loans');
    exit;
}

$docType = $_POST['document_type'] ?? '';
$refId = $_POST['reference_id'] ?? '';
$existingDocId = $_POST['existing_doc_id'] ?? '';
$documentContent = $_POST['document_content'] ?? '';
$sendNotification = isset($_POST['send_notification']) && $_POST['send_notification'] == '1';

if (!$docType || !$refId) {
    $_SESSION['error'] = 'Parameter tidak lengkap';
    header('Location: /index.php?page=admin_loans');
    exit;
}

// Function to convert month number to Roman numerals
function getRomanMonth($month) {
    $romans = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $romans[$month - 1] ?? '';
}

try {
    $pdo->beginTransaction();
    
    // Check if document already has a number
    $documentNumber = null;
    if ($existingDocId) {
        $stmt = $pdo->prepare("SELECT document_number FROM generated_documents WHERE id = ?");
        $stmt->execute([$existingDocId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $documentNumber = $existing['document_number'];
        }
    }
    
    // Generate document number only if not exists
    if (!$documentNumber) {
        // Get next number from document_numbers table
        $year = date('Y');
        $month = date('n');
        $romanMonth = getRomanMonth($month);
        
        // Get and increment the counter based on document_type, year, and month
        $stmt = $pdo->prepare("SELECT last_number FROM document_numbers WHERE document_type = ? AND year = ? AND month = ? FOR UPDATE");
        $stmt->execute([$docType, $year, $month]);
        $row = $stmt->fetch();
        
        if ($row) {
            $nextNumber = $row['last_number'] + 1;
            $stmt = $pdo->prepare("UPDATE document_numbers SET last_number = ? WHERE document_type = ? AND year = ? AND month = ?");
            $stmt->execute([$nextNumber, $docType, $year, $month]);
        } else {
            $nextNumber = 1;
            $stmt = $pdo->prepare("INSERT INTO document_numbers (document_type, year, month, last_number) VALUES (?, ?, ?, 1)");
            $stmt->execute([$docType, $year, $month]);
        }
        
        // Format: 001/UPT Manado/II/2026
        $documentNumber = sprintf('%03d/UPT Manado/%s/%d', $nextNumber, $romanMonth, $year);
    }
    
    // Update or insert the document
    if ($existingDocId) {
        $stmt = $pdo->prepare("
            UPDATE generated_documents 
            SET generated_data = ?, status = 'saved', generated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$documentContent, $existingDocId]);
        $docId = $existingDocId;
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO generated_documents (document_type, reference_id, document_number, generated_data, status, generated_at)
            VALUES (?, ?, ?, ?, 'saved', NOW())
        ");
        $stmt->execute([$docType, $refId, $documentNumber, $documentContent]);
        $docId = $pdo->lastInsertId();
    }
    
    // Update transaction status based on document type
    if (strpos($refId, 'single_') === 0) {
        $itemId = str_replace('single_', '', $refId);
        if ($docType === 'loan') {
            $stmt = $pdo->prepare("UPDATE loans SET admin_document_path = 'generated_doc_$docId' WHERE id = ?");
            $stmt->execute([$itemId]);
        } elseif ($docType === 'request') {
            $stmt = $pdo->prepare("UPDATE requests SET admin_document_path = 'generated_doc_$docId' WHERE id = ?");
            $stmt->execute([$itemId]);
        } elseif ($docType === 'return') {
            $stmt = $pdo->prepare("UPDATE loans SET return_admin_document_path = 'generated_doc_$docId' WHERE id = ?");
            $stmt->execute([$itemId]);
        }
    } else {
        if ($docType === 'loan') {
            $stmt = $pdo->prepare("UPDATE loans SET admin_document_path = 'generated_doc_$docId' WHERE group_id = ?");
            $stmt->execute([$refId]);
        } elseif ($docType === 'request') {
            $stmt = $pdo->prepare("UPDATE requests SET admin_document_path = 'generated_doc_$docId' WHERE group_id = ?");
            $stmt->execute([$refId]);
        } elseif ($docType === 'return') {
            $stmt = $pdo->prepare("UPDATE loans SET return_admin_document_path = 'generated_doc_$docId' WHERE group_id = ?");
            $stmt->execute([$refId]);
        }
    }
    
    // Send notification if requested
    if ($sendNotification) {
        // Get user_id from reference
        $userId = null;
        if (strpos($refId, 'single_') === 0) {
            $itemId = str_replace('single_', '', $refId);
            if ($docType === 'loan' || $docType === 'return') {
                $stmt = $pdo->prepare("SELECT user_id FROM loans WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("SELECT user_id FROM requests WHERE id = ?");
            }
            $stmt->execute([$itemId]);
            $row = $stmt->fetch();
            $userId = $row['user_id'] ?? null;
        } else {
            if ($docType === 'loan' || $docType === 'return') {
                $stmt = $pdo->prepare("SELECT user_id FROM loans WHERE group_id = ? LIMIT 1");
            } else {
                $stmt = $pdo->prepare("SELECT user_id FROM requests WHERE group_id = ? LIMIT 1");
            }
            $stmt->execute([$refId]);
            $row = $stmt->fetch();
            $userId = $row['user_id'] ?? null;
        }
        
        if ($userId) {
            $docTypeLabels = ['loan' => 'Peminjaman', 'request' => 'Permintaan', 'return' => 'Pengembalian'];
            $message = 'Dokumen Berita Acara ' . ($docTypeLabels[$docType] ?? 'Transaksi') . ' dengan nomor ' . $documentNumber . ' telah dibuat.';
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$userId, $message]);
        }
    }
    
    $pdo->commit();
    
    // Redirect back with success message
    $_SESSION['success'] = 'Dokumen berhasil disimpan dengan nomor: ' . $documentNumber;
    
    // Redirect based on doc type
    $redirectPages = [
        'loan' => 'admin_loans',
        'request' => 'admin_requests',
        'return' => 'admin_returns'
    ];
    $redirectPage = $redirectPages[$docType] ?? 'admin_loans';
    
    header('Location: /index.php?page=' . $redirectPage);
    exit;
    
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = 'Gagal menyimpan dokumen: ' . $e->getMessage();
    header('Location: /index.php?page=admin_generate_document&type=' . $docType . '&ref=' . $refId);
    exit;
}
