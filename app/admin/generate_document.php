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
function getNextDocumentNumber($pdo, $docType) {
    $year = date('Y');
    $month = date('n');
    
    // Check current month's last number from document_numbers table
    $stmt = $pdo->prepare("
        SELECT last_number FROM document_numbers 
        WHERE document_type = ? AND year = ? AND month = ?
    ");
    $stmt->execute([$docType, $year, $month]);
    $row = $stmt->fetch();
    
    if ($row) {
        // Return last_number + 1 as preview (actual save will use this)
        return $row['last_number'] + 1;
    }
    
    // If no record exists for this month, start from 1
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        @page { size: A4; margin: 15mm; }
        body { background: #e9ecef; font-family: 'Times New Roman', Times, serif; font-size: 12px; }
        .toolbar { background: #fff; padding: 15px 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; }
        .document-wrapper { padding: 20px; display: flex; flex-direction: column; gap: 20px; align-items: center; }
        
        .page-container {
            background: white;
            width: 210mm;
            min-height: 297mm;
            padding: 15mm 20mm;
            box-shadow: 0 0 20px rgba(0,0,0,0.15);
            position: relative;
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
            width: 120px; 
            height: 90px; 
            border: 1px dashed #ccc; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            background: #f9f9f9;
            font-size: 9px;
            color: #999;
        }
        .photo-placeholder img { max-width: 100%; max-height: 100%; object-fit: contain; }
        
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
                    <button type="button" class="btn btn-info btn-sm text-white" onclick="downloadExcel()">
                        <i class="bi bi-file-earmark-excel me-1"></i>Download Excel
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="saveDocument()">
                        <i class="bi bi-save me-1"></i>Simpan Dokumen
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
                        </td>
                        <td style="width: 50%;">
                            <strong class="editable" contenteditable="true">PIHAK KEDUA</strong>
                            <div class="signature-box"></div>
                            <div class="signature-line"></div>
                        </td>
                    </tr>
                </table>
                
                <div style="text-align: center; margin-top: 20px;">
                    <p style="margin-bottom:3px;"><strong class="editable" contenteditable="true">Mengetahui,</strong></p>
                    <p style="margin-bottom:0;" class="editable" contenteditable="true">AtMan Konstruksi</p>
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
                <span class="editable" contenteditable="true">LENGAN SULAWESI</span><br>
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
                <div class="doc-row">
                    <div class="doc-no editable" contenteditable="true"><?= $idx + 1 ?></div>
                    <div class="doc-material editable" contenteditable="true"><?= htmlspecialchars($item['item_name']) ?></div>
                    <div class="doc-photo">
                        <?php if (!empty($item['image'])): ?>
                        <div class="photo-placeholder">
                            <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" alt="">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <script>
        async function downloadPDF() {
            const { jsPDF } = window.jspdf;
            // Use compression with smaller quality
            const pdf = new jsPDF({
                orientation: 'p',
                unit: 'mm',
                format: 'a4',
                compress: true
            });
            
            // Remove editable styling for PDF
            document.querySelectorAll('.editable').forEach(el => {
                el.style.backgroundColor = 'transparent';
                el.style.borderBottom = 'none';
            });
            
            // Capture page 1 with optimized settings for smaller file size
            const page1 = document.getElementById('page1');
            const canvas1 = await html2canvas(page1, { 
                scale: 1.5, // Reduced from 2 for smaller file size
                useCORS: true, 
                logging: false,
                imageTimeout: 0
            });
            // Use JPEG with compression instead of PNG
            const imgData1 = canvas1.toDataURL('image/jpeg', 0.8);
            pdf.addImage(imgData1, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');
            
            // Capture page 2
            pdf.addPage();
            const page2 = document.getElementById('page2');
            const canvas2 = await html2canvas(page2, { 
                scale: 1.5,
                useCORS: true, 
                logging: false,
                imageTimeout: 0
            });
            const imgData2 = canvas2.toDataURL('image/jpeg', 0.8);
            pdf.addImage(imgData2, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');
            
            pdf.save('Berita_Acara_<?= str_replace(['/', ' '], ['_', '_'], $previewNumber) ?>.pdf');
            
            // Restore editable styling
            document.querySelectorAll('.editable').forEach(el => {
                el.style.backgroundColor = '#fffef0';
                el.style.borderBottom = '1px dashed #ccc';
            });
        }
        
        function downloadExcel() {
            // Prepare data for Excel
            const items = <?= json_encode($items) ?>;
            const docInfo = {
                type: '<?= $docTitle ?>',
                number: '<?= $previewNumber ?>',
                date: '<?= $dayName ?>, <?= $dateFormatted ?> <?= $monthFormatted ?> <?= $yearFormatted ?>',
                location: 'Gudang Tanggari',
                userName: '<?= htmlspecialchars($userName) ?>'
            };
            
            // Create workbook
            const wb = XLSX.utils.book_new();
            
            // Header data
            const headerData = [
                ['PT PLN (PERSERO)'],
                ['LENGAN SULAWESI'],
                ['UPT MANADO'],
                [''],
                ['FORMULIR'],
                ['BERITA ACARA SERAH TERIMA MATERIAL'],
                [''],
                ['Pada hari ini', ':', docInfo.date],
                ['Tempat', ':', docInfo.location],
                ['Nomor', ':', docInfo.number],
                [''],
                ['Pihak Pertama', ':', 'Admin'],
                ['Pihak Kedua', ':', docInfo.userName],
                [''],
                ['NO', 'Nama Barang', 'Merek/Type', 'No Seri', 'Jumlah', 'Satuan', 'Keterangan']
            ];
            
            // Add items
            items.forEach((item, idx) => {
                headerData.push([
                    idx + 1,
                    item.item_name || '',
                    item.item_type || '-',
                    item.code || '-',
                    item.quantity || 1,
                    item.unit || 'unit',
                    ''
                ]);
            });
            
            // Footer
            headerData.push(['']);
            headerData.push(['PIHAK PERTAMA telah menyerahkan kepada PIHAK KEDUA dan PIHAK KEDUA telah menerima dari PIHAK PERTAMA.']);
            headerData.push(['Demikian Berita Acara ini dibuat dengan sesungguhnya untuk dipergunakan sebagaimana mestinya.']);
            
            const ws = XLSX.utils.aoa_to_sheet(headerData);
            
            // Set column widths
            ws['!cols'] = [
                { wch: 5 },   // NO
                { wch: 25 },  // Nama Barang
                { wch: 15 },  // Merek/Type
                { wch: 15 },  // No Seri
                { wch: 10 },  // Jumlah
                { wch: 10 },  // Satuan
                { wch: 20 }   // Keterangan
            ];
            
            XLSX.utils.book_append_sheet(wb, ws, 'Berita Acara');
            
            // Download
            XLSX.writeFile(wb, 'Berita_Acara_<?= str_replace(['/', ' '], ['_', '_'], $previewNumber) ?>.xlsx');
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
    </script>
</body>
</html>
