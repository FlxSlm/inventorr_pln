<?php
// app/admin/generate_document.php
// Auto-fill Document Generator for Berita Acara - Format sesuai Template PLN

$pdo = require __DIR__ . '/../config/database.php';

$docType = $_GET['type'] ?? ''; // loan, request, return
$refId = $_GET['ref'] ?? ''; // group_id or single_<id>

if (!$docType || !$refId) {
    die('Invalid parameters');
}

// Get the active template for this document type
$templateStmt = $pdo->prepare("SELECT * FROM document_templates WHERE template_type = ? AND is_active = 1 LIMIT 1");
$templateStmt->execute([$docType]);
$activeTemplate = $templateStmt->fetch();

// Function to convert month number to Roman numerals
function getRomanMonth($month) {
    $romans = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $romans[$month - 1] ?? '';
}

// Function to get Indonesian day name
function getIndonesianDay($date) {
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $days[date('w', strtotime($date))];
}

// Function to get next document number (resets monthly)
// This function only PREVIEWS the next number - actual increment happens only when saving
// Now considers actual saved documents to auto-correct numbering when documents are deleted
function getNextDocumentNumber($pdo, $docType) {
    $year = date('Y');
    $month = date('n');
    $romanMonth = getRomanMonth($month);
    
    // First check if there are any actual saved documents for this month
    // This ensures numbering resets properly when documents are deleted from database
    $docTypeMap = [
        'loan' => 'BAST-PJ',
        'request' => 'BAST-PM', 
        'return' => 'BAST-KM'
    ];
    $typeCode = $docTypeMap[$docType] ?? 'BAST';
    
    // Pattern to match: XXX/YYY/ZZZ/ROMAN/YEAR where XXX is the number we need
    $searchPattern = "%/{$typeCode}/%/{$romanMonth}/{$year}";
    
    $stmt = $pdo->prepare("
        SELECT document_number FROM generated_documents 
        WHERE document_type = ? 
        AND status IN ('saved', 'uploaded', 'sent')
        AND document_number LIKE ?
        ORDER BY id DESC
    ");
    $stmt->execute([$docType, $searchPattern]);
    $existingDocs = $stmt->fetchAll();
    
    // If there are saved documents, get the highest number from them
    if (!empty($existingDocs)) {
        $maxNumber = 0;
        foreach ($existingDocs as $doc) {
            // Extract the number from document_number (first part before /)
            $parts = explode('/', $doc['document_number']);
            if (!empty($parts[0]) && is_numeric($parts[0])) {
                $num = (int)$parts[0];
                if ($num > $maxNumber) {
                    $maxNumber = $num;
                }
            }
        }
        
        // Update document_numbers table to match actual documents
        $stmt = $pdo->prepare("
            INSERT INTO document_numbers (document_type, year, month, last_number)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)
        ");
        $stmt->execute([$docType, $year, $month, $maxNumber]);
        
        return $maxNumber + 1;
    }
    
    // No saved documents exist for this month - reset to 1
    // Also clear the document_numbers record for this month
    $stmt = $pdo->prepare("
        DELETE FROM document_numbers 
        WHERE document_type = ? AND year = ? AND month = ?
    ");
    $stmt->execute([$docType, $year, $month]);
    
    return 1;
}

// Check if document already has a saved number
$existingDoc = null;
$documentNumber = null;
$stmt = $pdo->prepare("SELECT * FROM generated_documents WHERE document_type = ? AND reference_id = CAST(? AS CHAR) AND status IN ('saved', 'uploaded', 'sent') ORDER BY id DESC LIMIT 1");
$stmt->execute([$docType, $refId]);
$existingDoc = $stmt->fetch();

if ($existingDoc) {
    $documentNumber = $existingDoc['document_number'];
}

// Fetch data based on type and reference
$items = [];
$userName = '';
$transactionDate = '';

if (strpos($refId, 'single_') === 0) {
    $itemId = str_replace('single_', '', $refId);
    
    if ($docType === 'loan') {
        $stmt = $pdo->prepare("
            SELECT l.*, i.name as item_name, i.unit, i.image, i.code, i.description, i.item_type,
                   u.name, l.requested_at as transaction_date
            FROM loans l
            JOIN inventories i ON l.inventory_id = i.id
            JOIN users u ON l.user_id = u.id
            WHERE l.id = ?
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item) {
            $items[] = $item;
            $userName = $item['name'];
            $transactionDate = $item['transaction_date'];
        }
    } elseif ($docType === 'request') {
        $stmt = $pdo->prepare("
            SELECT r.*, i.name as item_name, i.unit, i.image, i.code, i.description, i.item_type,
                   u.name, r.requested_at as transaction_date
            FROM requests r
            JOIN inventories i ON r.inventory_id = i.id
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item) {
            $items[] = $item;
            $userName = $item['name'];
            $transactionDate = $item['transaction_date'];
        }
    } elseif ($docType === 'return') {
        $stmt = $pdo->prepare("
            SELECT l.*, i.name as item_name, i.unit, i.image, i.code, i.description, i.item_type,
                   u.name, COALESCE(l.return_requested_at, l.requested_at) as transaction_date
            FROM loans l
            JOIN inventories i ON l.inventory_id = i.id
            JOIN users u ON l.user_id = u.id
            WHERE l.id = ?
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item) {
            $items[] = $item;
            $userName = $item['name'];
            $transactionDate = $item['transaction_date'];
        }
    }
} else {
    $groupId = $refId;
    
    if ($docType === 'loan') {
        $stmt = $pdo->prepare("
            SELECT l.*, i.name as item_name, i.unit, i.image, i.code, i.description, i.item_type,
                   u.name, l.requested_at as transaction_date
            FROM loans l
            JOIN inventories i ON l.inventory_id = i.id
            JOIN users u ON l.user_id = u.id
            WHERE l.group_id = ?
        ");
        $stmt->execute([$groupId]);
        $items = $stmt->fetchAll();
        if (!empty($items)) {
            $userName = $items[0]['name'];
            $transactionDate = $items[0]['transaction_date'];
        }
    } elseif ($docType === 'request') {
        $stmt = $pdo->prepare("
            SELECT r.*, i.name as item_name, i.unit, i.image, i.code, i.description, i.item_type,
                   u.name, r.requested_at as transaction_date
            FROM requests r
            JOIN inventories i ON r.inventory_id = i.id
            JOIN users u ON r.user_id = u.id
            WHERE r.group_id = ?
        ");
        $stmt->execute([$groupId]);
        $items = $stmt->fetchAll();
        if (!empty($items)) {
            $userName = $items[0]['name'];
            $transactionDate = $items[0]['transaction_date'];
        }
    } elseif ($docType === 'return') {
        $stmt = $pdo->prepare("
            SELECT l.*, i.name as item_name, i.unit, i.image, i.code, i.description, i.item_type,
                   u.name, COALESCE(l.return_requested_at, l.requested_at) as transaction_date
            FROM loans l
            JOIN inventories i ON l.inventory_id = i.id
            JOIN users u ON l.user_id = u.id
            WHERE l.group_id = ?
        ");
        $stmt->execute([$groupId]);
        $items = $stmt->fetchAll();
        if (!empty($items)) {
            $userName = $items[0]['name'];
            $transactionDate = $items[0]['transaction_date'];
        }
    }
}

// Get transaction type label
$docTypeLabels = [
    'loan' => 'Peminjaman',
    'request' => 'Permintaan', 
    'return' => 'Pengembalian'
];
$docTitle = $docTypeLabels[$docType] ?? 'Transaksi';

// TUSI mapping based on document type
$tusiMapping = [
    'loan' => 'Surat Peminjaman',
    'request' => 'Surat Permintaan',
    'return' => 'Surat Pengembalian'
];
$tusiLabel = $tusiMapping[$docType] ?? 'Surat';

// Preview number (not saved yet) - auto-generate with format XXX/UPT Manado/RomanMonth/Year
if ($documentNumber) {
    $previewNumber = $documentNumber;
} else {
    $nextNum = getNextDocumentNumber($pdo, $docType);
    $previewNumber = sprintf('%03d/UPT Manado/%s/%d', $nextNum, getRomanMonth(date('n')), date('Y'));
}

// Format date parts
$dayName = getIndonesianDay($transactionDate ?: date('Y-m-d'));
$dateFormatted = date('d', strtotime($transactionDate ?: date('Y-m-d')));
$monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$monthFormatted = $monthNames[date('n', strtotime($transactionDate ?: date('Y-m-d'))) - 1];
$yearFormatted = date('Y', strtotime($transactionDate ?: date('Y-m-d')));

// Prepare data for Excel export
$exportData = [
    'docType' => $docType,
    'docTitle' => $docTitle,
    'refId' => $refId,
    'userName' => $userName,
    'dayName' => $dayName,
    'dateFormatted' => $dateFormatted,
    'monthFormatted' => $monthFormatted,
    'yearFormatted' => $yearFormatted,
    'previewNumber' => $previewNumber,
    'tusiLabel' => $tusiLabel,
    'items' => $items
];

// Determine back URL based on document type
$backUrls = [
    'loan' => '/index.php?page=admin_loans',
    'request' => '/index.php?page=admin_requests',
    'return' => '/index.php?page=admin_returns'
];
$backUrl = $backUrls[$docType] ?? '/index.php?page=admin_loans';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Document - Berita Acara <?= $docTitle ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        @page { size: A4; margin: 15mm; }
        body { background: #e9ecef; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; }
        .toolbar { 
            background: #fff; 
            padding: 15px 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            position: sticky; 
            top: 0; 
            z-index: 100;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        }
        .toolbar * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        }
        .document-wrapper { padding: 20px; display: flex; flex-direction: column; gap: 20px; align-items: center; }
        
        .page-container {
            background: white;
            width: 210mm;
            min-height: 297mm;
            padding: 15mm 20mm;
            box-shadow: 0 0 20px rgba(0,0,0,0.15);
            position: relative;
            /* Document font - only applies to page content */
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
        }
        
        .page-header-logo {
            position: absolute;
            top: 10mm;
            right: 15mm;
            text-align: right;
            font-size: 11px;
            color: #000;
        }
        .page-header-logo .pln-logo-img { width: 60px; height: 60px; margin-bottom: 3px; }
        .page-header-logo .company-name { font-weight: bold; color: #000; }
        
        .document-title { text-align: center; margin: 25px 0 15px; }
        .document-title h4 { font-weight: bold; color: #000; font-size: 16px; margin: 0; }
        .document-title h5 { font-weight: bold; color: #000; font-size: 15px; margin: 5px 0 0; }
        
        .info-section { font-size: 12px; margin: 10px 0; line-height: 1.8; }
        .info-section table { border: none !important; }
        .info-section td { border: none !important; padding: 3px 5px; vertical-align: top; }
        .info-section .label { width: 120px; }
        
        .editable { 
            background-color: #fffef0; 
            border-bottom: 1px dashed #ccc;
            padding: 1px 3px;
            min-width: 30px;
            display: inline-block;
        }
        .editable:focus { outline: 2px solid #0066cc; background: #fff; }
        
        .items-table { width: 100%; border-collapse: collapse; font-size: 11px; margin: 10px 0; }
        .items-table th, .items-table td { border: 1px solid #333; padding: 5px 8px; text-align: center; vertical-align: middle; }
        .items-table th { background: #f5f5f5; font-weight: bold; color: #000; }
        .items-table td.text-left { text-align: left; }
        
        .signature-section { margin-top: 20px; font-size: 12px; }
        .signature-table { width: 100%; border: none !important; }
        .signature-table td { border: none !important; text-align: center; padding: 5px 15px; vertical-align: top; }
        .signature-box { height: 60px; }
        .signature-line { border-bottom: 1px solid #333; width: 70%; margin: 0 auto; }
        
        .closing-text { font-size: 12px; margin: 15px 0; }
        
        /* Page 2 - Documentation */
        .doc-title { color: #000; font-weight: bold; text-align: center; font-size: 16px; margin: 40px 0 20px; }
        .doc-list { font-size: 12px; }
        .doc-row { display: flex; margin-bottom: 10px; align-items: flex-start; }
        .doc-no { width: 30px; color: #000; font-weight: bold; }
        .doc-material { width: 180px; color: #000; }
        .doc-photo { flex: 1; }
        
        .photo-placeholder { 
            width: 200px; 
            height: 150px; 
            border: 1px dashed #ccc; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            background: #f9f9f9;
            font-size: 9px;
            color: #999;
            overflow: hidden;
        }
        .photo-placeholder img { 
            width: 100%; 
            height: 100%; 
            object-fit: contain;
            display: block;
        }
        
        @media print {
            body { background: white; }
            .toolbar { display: none !important; }
            .page-container { box-shadow: none; margin: 0; page-break-after: always; }
            .editable { background: transparent !important; border: none !important; }
        }
    </style>
</head>
<body>
    <!-- Toolbar -->
    <div class="toolbar">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0" style="font-family: Arial, sans-serif;"><i class="bi bi-file-earmark-text me-2"></i>Berita Acara <?= $docTitle ?></h5>
                    <small class="text-muted" style="font-family: Arial, sans-serif;">
                        <?php if ($documentNumber): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Nomor: <?= htmlspecialchars($documentNumber) ?></span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Nomor akan digenerate saat disimpan</span>
                        <?php endif; ?>
                        <?php if ($activeTemplate): ?>
                            <span class="badge bg-info ms-1"><i class="bi bi-file-earmark me-1"></i>Template: <?= htmlspecialchars($activeTemplate['template_name']) ?></span>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($activeTemplate): ?>
                    <a href="/public/assets/<?= htmlspecialchars($activeTemplate['file_path']) ?>" class="btn btn-outline-secondary btn-sm" download>
                        <i class="bi bi-download me-1"></i>Download Template
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="downloadPDF()">
                        <i class="bi bi-file-pdf me-1"></i>Download PDF
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="saveDocument()">
                        <i class="bi bi-save me-1"></i>Simpan Dokumen
                    </button>
                    <button type="button" class="btn btn-info btn-sm text-white" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                        <i class="bi bi-upload me-1"></i>Upload Dokumen
                    </button>
                    <a href="<?= $backUrl ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>Kembali
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="document-wrapper">
        <!-- PAGE 1: Formulir Berita Acara -->
        <div class="page-container" id="page1">
            <!-- Logo Header -->
            <div class="page-header-logo">
                <img src="/public/assets/img/logopln.png" alt="PLN Logo" class="pln-logo-img"><br>
                <span class="company-name editable" contenteditable="true">PT PLN (PERSERO)</span><br>
                <span class="editable" contenteditable="true">UIP3B Sulawesi</span><br>
                <span class="editable" contenteditable="true">UPT MANADO</span>
            </div>
            
            <!-- Title -->
            <div class="document-title">
                <h4 class="editable" contenteditable="true">FORMULIR</h4>
                <h5 class="editable" contenteditable="true">BERITA ACARA SERAH TERIMA MATERIAL</h5>
            </div>
            
            <!-- Info Section -->
            <div class="info-section">
                <table>
                    <tr>
                        <td class="label">Pada hari ini,</td>
                        <td>:</td>
                        <td><span class="editable" contenteditable="true"><?= $dayName ?></span>, <span class="editable" contenteditable="true"><?= $dateFormatted ?> <?= $monthFormatted ?> <?= $yearFormatted ?></span></td>
                    </tr>
                    <tr>
                        <td class="label">Tempat</td>
                        <td>:</td>
                        <td><span class="editable" contenteditable="true">Gudang Tanggari</span></td>
                    </tr>
                    <tr>
                        <td class="label">Berdasarkan pada</td>
                        <td>:</td>
                        <td>Surat <span class="editable" contenteditable="true">AM33</span> : <span class="editable" contenteditable="true">Moda Distribusi</span> : TUSI : <span class="editable" contenteditable="true"><?= $tusiLabel ?></span> : " " <small style="color:#888;">(isian yang tidak perlu)</small></td>
                    </tr>
                    <tr>
                        <td class="label">Nomor</td>
                        <td>:</td>
                        <td><span class="editable" contenteditable="true" id="docNumberField"><?= $previewNumber ?></span></td>
                    </tr>
                </table>
            </div>
            
            <!-- Pihak Section -->
            <div class="info-section">
                <p style="margin-bottom: 3px;" class="editable" contenteditable="true">Kami yang bertanda tangan dibawah ini :</p>
                <table>
                    <tr><td style="width:15px;">1.</td><td style="width:60px;">Nama</td><td style="width:10px;">:</td><td><span class="editable" contenteditable="true">Nama Siapa</span></td></tr>
                    <tr><td></td><td>Jabatan</td><td>:</td><td><span class="editable" contenteditable="true"></span></td></tr>
                </table>
                <p style="margin: 3px 0;">Untuk selanjutnya disebut <span class="editable" contenteditable="true">PIHAK PERTAMA</span> dari <span class="editable" contenteditable="true" style="font-weight:bold;">Darimana</span></p>
                
                <table>
                    <tr><td style="width:15px;">2.</td><td style="width:60px;">Nama</td><td style="width:10px;">:</td><td><span class="editable" contenteditable="true"><?= htmlspecialchars($userName) ?></span></td></tr>
                    <tr><td></td><td>Jabatan</td><td>:</td><td><span class="editable" contenteditable="true"></span></td></tr>
                </table>
                <p style="margin: 3px 0;">Untuk selanjutnya disebut <span class="editable" contenteditable="true">PIHAK KEDUA</span> dari <span class="editable" contenteditable="true" style="font-weight:bold;">Darimana</span></p>
            </div>
            
            <!-- Items Table -->
            <div class="info-section">
                <p style="margin-bottom: 5px;" class="editable" contenteditable="true">Dengan ini menyatakan telah mengadakan serah terima barang dengan rincian sebagai berikut:</p>
            </div>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:30px;">NO</th>
                        <th style="width:150px;">Nama barang</th>
                        <th style="width:80px;">Merek/Type</th>
                        <th style="width:80px;">No Seri</th>
                        <th style="width:50px;">Jumlah</th>
                        <th style="width:50px;">Satuan</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td class="text-left"><span class="editable" contenteditable="true"><?= htmlspecialchars($item['item_name']) ?></span></td>
                        <td><span class="editable" contenteditable="true"><?= htmlspecialchars($item['item_type'] ?? '-') ?></span></td>
                        <td><span class="editable" contenteditable="true"><?= htmlspecialchars($item['code'] ?? '-') ?></span></td>
                        <td><span class="editable" contenteditable="true"><?= htmlspecialchars($item['quantity'] ?? 1) ?></span></td>
                        <td><span class="editable" contenteditable="true"><?= htmlspecialchars($item['unit'] ?? 'unit') ?></span></td>
                        <td><span class="editable" contenteditable="true"></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Closing -->
            <div class="closing-text">
                <p style="margin-bottom:3px;" class="editable" contenteditable="true">PIHAK PERTAMA telah menyerahkan kepada PIHAK KEDUA dan PIHAK KEDUA telah menerima dari PIHAK PERTAMA.</p>
                <p class="editable" contenteditable="true">Demikian Berita Acara ini dibuat dengan sesungguhnya untuk dipergunakan sebagaimana mestinya.</p>
            </div>
            
            <!-- Signature Section -->
            <div class="signature-section">
                <p style="text-align: right; margin-bottom: 10px;"><span class="editable" contenteditable="true">Manado</span>, <span class="editable" contenteditable="true"><?= $dateFormatted ?> <?= $monthFormatted ?> <?= $yearFormatted ?></span></p>
                
                <table class="signature-table">
                    <tr>
                        <td style="width: 50%;">
                            <strong class="editable" contenteditable="true">PIHAK PERTAMA</strong>
                            <div class="signature-box"></div>
                            <div class="signature-line"></div>
                            <div style="margin-top: 5px;">
                                <span class="editable" contenteditable="true" style="font-weight: 600;">(...............................)</span>
                            </div>
                        </td>
                        <td style="width: 50%;">
                            <strong class="editable" contenteditable="true">PIHAK KEDUA</strong>
                            <div class="signature-box"></div>
                            <div class="signature-line"></div>
                            <div style="margin-top: 5px;">
                                <span class="editable" contenteditable="true" style="font-weight: 600;">(<?= htmlspecialchars($userName) ?>)</span>
                            </div>
                        </td>
                    </tr>
                </table>
                
                <div style="text-align: center; margin-top: 20px;">
                    <p style="margin-bottom:3px;"><strong class="editable" contenteditable="true">Mengetahui,</strong></p>
                    <p style="margin-bottom:0;" class="editable" contenteditable="true">Asmen Konstruksi</p>
                    <div class="signature-box"></div>
                    <div class="signature-line" style="width: 35%;"></div>
                    <p style="margin-top: 3px;" class="editable" contenteditable="true">NAMA</p>
                </div>
            </div>
        </div>
        
        <!-- PAGE 2: Dokumentasi Material -->
        <div class="page-container" id="page2">
            <!-- Logo Header -->
            <div class="page-header-logo">
                <img src="/public/assets/img/logopln.png" alt="PLN Logo" class="pln-logo-img"><br>
                <span class="company-name editable" contenteditable="true">PT PLN (PERSERO)</span><br>
                <span class="editable" contenteditable="true">UIP3B Sulawesi</span><br>
                <span class="editable" contenteditable="true">UPT MANADO</span>
            </div>
            
            <div class="doc-title editable" contenteditable="true">DOKUMENTASI MATERIAL</div>
            
            <table style="width: 100%; font-size: 12px;">
                <tr>
                    <td style="width: 30px; font-weight: bold;" class="editable" contenteditable="true">No.</td>
                    <td style="width: 180px; font-weight: bold;" class="editable" contenteditable="true">MATERIAL</td>
                    <td style="font-weight: bold; color: #000;" class="editable" contenteditable="true">DOKUMENTASI</td>
                </tr>
            </table>
            
            <div class="doc-list" style="margin-top: 10px;">
                <?php foreach ($items as $idx => $item): ?>
                <div class="doc-row" style="margin-bottom: 20px;">
                    <div class="doc-no editable" contenteditable="true"><?= $idx + 1 ?></div>
                    <div class="doc-material editable" contenteditable="true"><?= htmlspecialchars($item['item_name']) ?></div>
                    <div class="doc-photo">
                        <?php if (!empty($item['image'])): 
                            $imagePath = __DIR__ . '/../../public/assets/uploads/' . $item['image'];
                            $imageBase64 = '';
                            $mimeType = 'image/jpeg';
                            if (file_exists($imagePath)) {
                                $imageData = file_get_contents($imagePath);
                                $imageBase64 = base64_encode($imageData);
                                $finfo = new finfo(FILEINFO_MIME_TYPE);
                                $mimeType = $finfo->file($imagePath);
                            }
                        ?>
                        <div class="photo-placeholder" style="width: 200px; height: 150px;">
                            <?php if ($imageBase64): ?>
                            <img src="data:<?= $mimeType ?>;base64,<?= $imageBase64 ?>" 
                                 alt="<?= htmlspecialchars($item['item_name']) ?>"
                                 style="width: 100%; height: 100%; object-fit: contain;"
                                 crossorigin="anonymous">
                            <?php else: ?>
                            <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" 
                                 alt="<?= htmlspecialchars($item['item_name']) ?>"
                                 style="width: 100%; height: 100%; object-fit: contain;"
                                 crossorigin="anonymous">
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="photo-placeholder" style="width: 200px; height: 150px;">
                            <span style="color: #999; font-size: 11px;">Tidak ada foto</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Save Modal -->
    <div class="modal fade" id="saveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-save me-2"></i>Simpan Dokumen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="saveForm" method="POST" action="/index.php?page=save_generated_document">
                    <div class="modal-body">
                        <input type="hidden" name="document_type" value="<?= $docType ?>">
                        <input type="hidden" name="reference_id" value="<?= $refId ?>">
                        <input type="hidden" name="existing_doc_id" value="<?= $existingDoc['id'] ?? '' ?>">
                        <input type="hidden" name="document_content" id="documentContent">
                        
                        <?php if (!$documentNumber): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Nomor surat akan digenerate otomatis saat dokumen disimpan.
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>
                            Nomor surat: <strong><?= htmlspecialchars($documentNumber) ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="send_notification" name="send_notification" value="1">
                            <label class="form-check-label" for="send_notification">
                                Kirim notifikasi ke karyawan
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-save me-1"></i>Simpan Dokumen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal - Auto-send PDF -->
    <div class="modal fade" id="uploadDocModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send me-2"></i>Kirim Dokumen ke Karyawan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="uploadDocType" value="<?= $docType ?>">
                    <input type="hidden" id="uploadRefId" value="<?= $refId ?>">
                    <input type="hidden" id="uploadDocNumber" value="<?= htmlspecialchars($previewNumber) ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Dokumen akan digenerate sebagai PDF dan dikirim otomatis ke karyawan.
                    </div>
                    
                    <div id="uploadPreviewArea" class="text-center mb-3 p-4" style="background: #f8f9fa; border-radius: 8px;">
                        <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 48px;"></i>
                        <p class="mt-2 mb-0">Berita_Acara_<?= str_replace(['/', ' '], ['_', '_'], $previewNumber) ?>.pdf</p>
                        <small class="text-muted">Dokumen akan digenerate dari halaman ini</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Catatan (Opsional)</label>
                        <textarea id="uploadNotes" class="form-control" rows="2" placeholder="Catatan untuk karyawan..."></textarea>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="upload_notify" checked>
                        <label class="form-check-label" for="upload_notify">
                            Kirim notifikasi ke karyawan
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-warning" id="btnFirstConfirm" onclick="showUploadConfirm()">
                        <i class="bi bi-check me-1"></i>Kirim Dokumen
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Final Confirmation Modal for Upload -->
    <div class="modal fade" id="uploadConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Konfirmasi Kirim</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div style="font-size: 4rem; color: var(--warning);">
                        <i class="bi bi-send"></i>
                    </div>
                    <h5 class="mt-3">Kirim Dokumen ke Karyawan?</h5>
                    <p class="text-muted">Dokumen akan digenerate dan dikirim ke <strong><?= htmlspecialchars($userName) ?></strong>.</p>
                    <p class="text-warning"><strong>Pastikan dokumen sudah benar sebelum mengirim!</strong></p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-success" id="btnFinalUpload" onclick="executeUpload()">
                        <i class="bi bi-send me-1"></i>Ya, Kirim Sekarang
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <script>
        // Preload all images to ensure they're available for PDF capture
        async function preloadImages() {
            const images = document.querySelectorAll('#page1 img, #page2 img');
            const promises = [];
            
            images.forEach(img => {
                if (!img.complete) {
                    promises.push(new Promise((resolve, reject) => {
                        img.onload = resolve;
                        img.onerror = resolve; // Resolve even on error to not block
                    }));
                }
            });
            
            await Promise.all(promises);
            
            // Additional wait to ensure images are fully rendered
            await new Promise(resolve => setTimeout(resolve, 500));
        }
        
        async function downloadPDF() {
            // Show loading indicator
            const downloadBtn = document.querySelector('[onclick="downloadPDF()"]');
            const originalHTML = downloadBtn.innerHTML;
            downloadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Membuat PDF...';
            downloadBtn.disabled = true;
            
            try {
                const { jsPDF } = window.jspdf;
                // Use compression with smaller quality
                const pdf = new jsPDF({
                    orientation: 'p',
                    unit: 'mm',
                    format: 'a4',
                    compress: true
                });
                
                // Preload all images first
                await preloadImages();
                
                // Remove editable styling for PDF
                document.querySelectorAll('.editable').forEach(el => {
                    el.style.backgroundColor = 'transparent';
                    el.style.borderBottom = 'none';
                });
                
                // Capture page 1 with optimized settings
                const page1 = document.getElementById('page1');
                const canvas1 = await html2canvas(page1, { 
                    scale: 2.5, // Higher scale for better image quality
                    useCORS: true, 
                    allowTaint: true, // Allow tainted canvas for local images
                    logging: false,
                    imageTimeout: 30000, // Wait up to 30 seconds for images
                    backgroundColor: '#ffffff',
                    onclone: function(clonedDoc) {
                        // Make sure images in cloned document are visible
                        clonedDoc.querySelectorAll('.photo-placeholder img').forEach(img => {
                            img.style.width = '100%';
                            img.style.height = '100%';
                            img.style.objectFit = 'contain';
                            img.style.display = 'block';
                        });
                    }
                });
                // Use JPEG with compression
                const imgData1 = canvas1.toDataURL('image/jpeg', 0.92);
                pdf.addImage(imgData1, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');
                
                // Capture page 2 - Documentation with images
                pdf.addPage();
                const page2 = document.getElementById('page2');
                const canvas2 = await html2canvas(page2, { 
                    scale: 2.5,
                    useCORS: true, 
                    allowTaint: true,
                    logging: false,
                    imageTimeout: 30000,
                    backgroundColor: '#ffffff',
                    onclone: function(clonedDoc) {
                        // Make sure images in cloned document are visible
                        clonedDoc.querySelectorAll('.photo-placeholder img').forEach(img => {
                            img.style.width = '100%';
                            img.style.height = '100%';
                            img.style.objectFit = 'contain';
                            img.style.display = 'block';
                        });
                    }
                });
                const imgData2 = canvas2.toDataURL('image/jpeg', 0.92);
                pdf.addImage(imgData2, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');
                
                pdf.save('Berita_Acara_<?= str_replace(['/', ' '], ['_', '_'], $previewNumber) ?>.pdf');
                
                // Restore editable styling
                document.querySelectorAll('.editable').forEach(el => {
                    el.style.backgroundColor = '#fffef0';
                    el.style.borderBottom = '1px dashed #ccc';
                });
            } catch (error) {
                console.error('Error generating PDF:', error);
                alert('Terjadi kesalahan saat membuat PDF. Silakan coba lagi.');
            } finally {
                // Restore button
                downloadBtn.innerHTML = originalHTML;
                downloadBtn.disabled = false;
            }
        }
            
        function saveDocument() {
            // Capture current document content
            const page1HTML = document.getElementById('page1').innerHTML;
            const page2HTML = document.getElementById('page2').innerHTML;
            document.getElementById('documentContent').value = JSON.stringify({
                page1: page1HTML,
                page2: page2HTML
            });
            
            const modal = new bootstrap.Modal(document.getElementById('saveModal'));
            modal.show();
        }
        
        // Handle form submission
        document.getElementById('saveForm').addEventListener('submit', function(e) {
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Menyimpan...';
        });
        
        // Preload images on page load
        document.addEventListener('DOMContentLoaded', function() {
            preloadImages();
        });
        
        // Upload confirmation functions
        function showUploadConfirm() {
            // Hide first modal
            bootstrap.Modal.getInstance(document.getElementById('uploadDocModal')).hide();
            
            // Show confirmation modal
            setTimeout(function() {
                new bootstrap.Modal(document.getElementById('uploadConfirmModal')).show();
            }, 300);
        }
        
        async function executeUpload() {
            const btn = document.getElementById('btnFinalUpload');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Mengirim...';
            
            try {
                // Generate PDF as blob
                const pdfBlob = await generatePDFBlob();
                
                // Prepare form data
                const formData = new FormData();
                formData.append('document_type', document.getElementById('uploadDocType').value);
                formData.append('reference_id', document.getElementById('uploadRefId').value);
                formData.append('document_number', document.getElementById('uploadDocNumber').value);
                formData.append('upload_notes', document.getElementById('uploadNotes').value);
                formData.append('send_notification', document.getElementById('upload_notify').checked ? '1' : '0');
                formData.append('auto_generated', '1');
                formData.append('document_file', pdfBlob, 'Berita_Acara_<?= str_replace(['/', ' '], ['_', '_'], $previewNumber) ?>.pdf');
                
                // Send to server
                const response = await fetch('/index.php?page=upload_generated_document', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.text();
                
                // Check if redirect happened (success)
                if (response.ok || response.redirected) {
                    // Close modal and redirect
                    bootstrap.Modal.getInstance(document.getElementById('uploadConfirmModal')).hide();
                    
                    // Show success and redirect
                    alert('Dokumen berhasil dikirim ke karyawan!');
                    window.location.href = '/index.php?page=admin_saved_documents&msg=sent';
                } else {
                    throw new Error('Gagal mengirim dokumen');
                }
            } catch (error) {
                console.error('Upload error:', error);
                alert('Terjadi kesalahan saat mengirim dokumen. Silakan coba lagi.');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>Ya, Kirim Sekarang';
            }
        }
        
        async function generatePDFBlob() {
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF({
                orientation: 'p',
                unit: 'mm',
                format: 'a4',
                compress: true
            });
            
            // Preload images
            await preloadImages();
            
            // Remove editable styling for PDF
            document.querySelectorAll('.editable').forEach(el => {
                el.style.backgroundColor = 'transparent';
                el.style.borderBottom = 'none';
            });
            
            // Capture page 1
            const page1 = document.getElementById('page1');
            const canvas1 = await html2canvas(page1, { 
                scale: 2.5,
                useCORS: true, 
                allowTaint: true,
                logging: false,
                imageTimeout: 30000,
                backgroundColor: '#ffffff',
                onclone: function(clonedDoc) {
                    clonedDoc.querySelectorAll('.photo-placeholder img').forEach(img => {
                        img.style.width = '100%';
                        img.style.height = '100%';
                        img.style.objectFit = 'contain';
                        img.style.display = 'block';
                    });
                }
            });
            const imgData1 = canvas1.toDataURL('image/jpeg', 0.92);
            pdf.addImage(imgData1, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');
            
            // Capture page 2
            pdf.addPage();
            const page2 = document.getElementById('page2');
            const canvas2 = await html2canvas(page2, { 
                scale: 2.5,
                useCORS: true, 
                allowTaint: true,
                logging: false,
                imageTimeout: 30000,
                backgroundColor: '#ffffff',
                onclone: function(clonedDoc) {
                    clonedDoc.querySelectorAll('.photo-placeholder img').forEach(img => {
                        img.style.width = '100%';
                        img.style.height = '100%';
                        img.style.objectFit = 'contain';
                        img.style.display = 'block';
                    });
                }
            });
            const imgData2 = canvas2.toDataURL('image/jpeg', 0.92);
            pdf.addImage(imgData2, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');
            
            // Restore editable styling
            document.querySelectorAll('.editable').forEach(el => {
                el.style.backgroundColor = '#fffef0';
                el.style.borderBottom = '1px dashed #ccc';
            });
            
            // Return as blob
            return pdf.output('blob');
        }
    </script>
</body>
</html>
