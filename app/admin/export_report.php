<?php
// app/admin/export_report.php
// Generates editable PDF/Word/Excel report for top borrowed/requested items
// Uses same editable-document paradigm as generate_document.php

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /index.php?page=login');
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';

$type      = $_GET['type'] ?? '';       // borrow or request
$format    = $_GET['format'] ?? 'pdf';  // pdf, word, excel
$semStart  = (int)($_GET['sem_start'] ?? 1);
$yearStart = (int)($_GET['year_start'] ?? date('Y'));
$semEnd    = (int)($_GET['sem_end'] ?? $semStart);
$yearEnd   = (int)($_GET['year_end'] ?? $yearStart);

if (!in_array($type, ['borrow', 'request'])) {
    die('Invalid type');
}

// Convert semester to date range
$dateStart = $yearStart . '-' . ($semStart === 1 ? '01-01' : '07-01');
$dateEnd   = $yearEnd . '-' . ($semEnd === 1 ? '06-30' : '12-31') . ' 23:59:59';

$periodLabel = "Semester $semStart $yearStart" . ($semStart !== $semEnd || $yearStart !== $yearEnd ? " s/d Semester $semEnd $yearEnd" : '');

// Titles
$titles = [
    'borrow'  => 'Laporan Barang Yang Paling Sering Dipinjam',
    'request' => 'Laporan Barang Yang Paling Sering Diminta'
];
$docTitle = $titles[$type];

// Query data
if ($type === 'borrow') {
    $stmt = $pdo->prepare("
        SELECT i.id as inventory_id, i.name, i.item_type, i.image,
               COUNT(l.id) as count, SUM(l.quantity) as total_qty
        FROM loans l
        JOIN inventories i ON i.id = l.inventory_id
        WHERE l.stage = 'approved'
          AND l.requested_at BETWEEN ? AND ?
        GROUP BY l.inventory_id
        ORDER BY count DESC
    ");
    $stmt->execute([$dateStart, $dateEnd]);
} else {
    $stmt = $pdo->prepare("
        SELECT i.id as inventory_id, i.name, i.item_type, i.image,
               COUNT(r.id) as count, SUM(r.quantity) as total_qty
        FROM requests r
        JOIN inventories i ON i.id = r.inventory_id
        WHERE r.stage = 'approved'
          AND r.requested_at BETWEEN ? AND ?
        GROUP BY r.inventory_id
        ORDER BY count DESC
    ");
    $stmt->execute([$dateStart, $dateEnd]);
}
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate grand total for percentage
$grandTotal = 0;
foreach ($items as $item) {
    $grandTotal += (int)$item['count'];
}

// Fetch primary image for each item
foreach ($items as &$item) {
    // Try inventory_images table first
    $imgStmt = $pdo->prepare("SELECT image_path FROM inventory_images WHERE inventory_id = ? ORDER BY is_primary DESC, sort_order ASC LIMIT 1");
    $imgStmt->execute([$item['inventory_id']]);
    $primaryImg = $imgStmt->fetchColumn();
    if (!$primaryImg && !empty($item['image'])) {
        $primaryImg = $item['image'];
    }
    $item['primary_image'] = $primaryImg;
}
unset($item);

// ===== EXCEL EXPORT =====
if ($format === 'excel') {
    // Use PhpSpreadsheet
    require_once __DIR__ . '/../../vendor/autoload.php';
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Laporan');
    
    // PLN Logo (top-right)
    $excelLogoPath = __DIR__ . '/../../public/assets/img/logopln.png';
    if (file_exists($excelLogoPath)) {
        $logoDrawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $logoDrawing->setPath($excelLogoPath);
        $logoDrawing->setCoordinates('F1');
        $logoDrawing->setWidth(50);
        $logoDrawing->setHeight(50);
        $logoDrawing->setOffsetX(5);
        $logoDrawing->setOffsetY(2);
        $logoDrawing->setWorksheet($sheet);
    }
    
    // Company Header (right-aligned, below logo area)
    $sheet->setCellValue('E1', 'PT PLN (PERSERO)');
    $sheet->mergeCells('E1:E3');
    $sheet->getStyle('E1')->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('E1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_BOTTOM);
    $sheet->getRowDimension(1)->setRowHeight(20);
    
    $sheet->setCellValue('E4', 'UIP3B Sulawesi');
    $sheet->mergeCells('E4:F4');
    $sheet->getStyle('E4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('E4')->getFont()->setSize(10);
    
    $sheet->setCellValue('E5', 'UPT MANADO');
    $sheet->mergeCells('E5:F5');
    $sheet->getStyle('E5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('E5')->getFont()->setSize(10);
    
    // Title
    $sheet->setCellValue('A7', $docTitle);
    $sheet->mergeCells('A7:F7');
    $sheet->getStyle('A7')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A7')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Period
    $sheet->setCellValue('A8', 'Periode: ' . $periodLabel);
    $sheet->mergeCells('A8:F8');
    $sheet->getStyle('A8')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A8')->getFont()->setSize(11);
    
    // Headers
    $headers = ['No', 'Nama Barang', 'Merek/Tipe', 'Jumlah', 'Persentase', 'Gambar'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '10', $header);
        $sheet->getStyle($col . '10')->getFont()->setBold(true);
        $sheet->getStyle($col . '10')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $sheet->getStyle($col . '10')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $col++;
    }
    
    // Column widths
    $sheet->getColumnDimension('A')->setWidth(6);
    $sheet->getColumnDimension('B')->setWidth(35);
    $sheet->getColumnDimension('C')->setWidth(20);
    $sheet->getColumnDimension('D')->setWidth(12);
    $sheet->getColumnDimension('E')->setWidth(14);
    $sheet->getColumnDimension('F')->setWidth(25);
    
    // Data rows
    $row = 11;
    foreach ($items as $idx => $item) {
        $pct = $grandTotal > 0 ? round(($item['count'] / $grandTotal) * 100, 1) : 0;
        $sheet->setCellValue('A' . $row, $idx + 1);
        $sheet->setCellValue('B' . $row, $item['name']);
        $sheet->setCellValue('C' . $row, $item['item_type'] ?? '-');
        $sheet->setCellValue('D' . $row, $item['count']);
        $sheet->setCellValue('E' . $row, $pct . '%');
        
        // Try to embed image
        if (!empty($item['primary_image'])) {
            $imgPath = __DIR__ . '/../../public/assets/uploads/' . $item['primary_image'];
            if (file_exists($imgPath)) {
                $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $drawing->setPath($imgPath);
                $drawing->setCoordinates('F' . $row);
                $drawing->setWidth(80);
                $drawing->setHeight(60);
                $drawing->setOffsetX(5);
                $drawing->setOffsetY(5);
                $drawing->setWorksheet($sheet);
                $sheet->getRowDimension($row)->setRowHeight(55);
            } else {
                $sheet->setCellValue('F' . $row, '(gambar tidak tersedia)');
            }
        } else {
            $sheet->setCellValue('F' . $row, '(tidak ada gambar)');
        }
        
        // Center align
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $row++;
    }
    
    // Total row
    $sheet->setCellValue('A' . $row, '');
    $sheet->setCellValue('B' . $row, 'TOTAL');
    $sheet->setCellValue('C' . $row, '');
    $sheet->setCellValue('D' . $row, $grandTotal);
    $sheet->setCellValue('E' . $row, '100%');
    $sheet->getStyle('B' . $row . ':E' . $row)->getFont()->setBold(true);
    
    // Border for entire table
    $borderRange = 'A10:F' . $row;
    $sheet->getStyle($borderRange)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    
    // Output
    $filename = str_replace(' ', '_', $docTitle) . '_' . date('Ymd') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ===== WORD EXPORT (HTML-based .doc) =====
if ($format === 'word') {
    $filename = str_replace(' ', '_', $docTitle) . '_' . date('Ymd') . '.doc';
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Get PLN logo as base64
    $logoPath = __DIR__ . '/../../public/assets/img/logopln.png';
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    }
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"><style>';
    echo '@page { size: A4; margin: 20mm; }';
    echo 'body { font-family: "Times New Roman", Times, serif; font-size: 12pt; }';
    echo 'table.data-table { border-collapse: collapse; width: 100%; margin-top: 10px; }';
    echo 'table.data-table th, table.data-table td { border: 1px solid #333; padding: 5px 8px; text-align: center; vertical-align: middle; font-size: 11pt; }';
    echo 'table.data-table th { background-color: #f0f0f0; font-weight: bold; }';
    echo '.title { font-size: 16pt; font-weight: bold; text-align: center; margin: 10px 0 5px; }';
    echo '.subtitle { font-size: 11pt; text-align: center; margin-bottom: 15px; color: #555; }';
    echo '.text-left { text-align: left; }';
    echo '.img-cell img { width: 80px; height: 60px; object-fit: contain; }';
    echo '</style></head><body>';
    
    // Header with logo using table layout (works reliably in Word)
    echo '<table style="border:none;width:100%;margin-bottom:5px;border-collapse:collapse;">';
    echo '<tr style="border:none;">';
    echo '<td style="border:none;width:75%;vertical-align:top;"></td>';
    echo '<td style="border:none;width:25%;text-align:right;vertical-align:top;">';
    if ($logoBase64) {
        echo '<img src="' . $logoBase64 . '" alt="PLN Logo" width="45" height="45"><br>';
    }
    echo '<span style="font-size:10pt;font-weight:bold;">PT PLN (PERSERO)</span><br>';
    echo '<span style="font-size:9pt;">UIP3B Sulawesi</span><br>';
    echo '<span style="font-size:9pt;">UPT MANADO</span>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    
    echo '<div class="title">' . htmlspecialchars($docTitle) . '</div>';
    echo '<div class="subtitle">Periode: ' . htmlspecialchars($periodLabel) . '</div>';
    
    // Table
    echo '<table class="data-table">';
    echo '<tr><th style="width:30px;">No</th><th>Nama Barang</th><th>Merek/Tipe</th><th style="width:60px;">Jumlah</th><th style="width:70px;">Persentase</th><th style="width:100px;">Gambar</th></tr>';
    
    foreach ($items as $idx => $item) {
        $pct = $grandTotal > 0 ? round(($item['count'] / $grandTotal) * 100, 1) : 0;
        echo '<tr>';
        echo '<td>' . ($idx + 1) . '</td>';
        echo '<td class="text-left">' . htmlspecialchars($item['name']) . '</td>';
        echo '<td>' . htmlspecialchars($item['item_type'] ?? '-') . '</td>';
        echo '<td>' . $item['count'] . '</td>';
        echo '<td>' . $pct . '%</td>';
        echo '<td class="img-cell">';
        if (!empty($item['primary_image'])) {
            $imgPath = __DIR__ . '/../../public/assets/uploads/' . $item['primary_image'];
            if (file_exists($imgPath)) {
                $imgData = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($imgPath));
                echo '<img src="' . $imgData . '" alt="' . htmlspecialchars($item['name']) . '">';
            } else {
                echo '-';
            }
        } else {
            echo '-';
        }
        echo '</td>';
        echo '</tr>';
    }
    
    // Total row
    echo '<tr><td></td><td class="text-left"><strong>TOTAL</strong></td><td></td><td><strong>' . $grandTotal . '</strong></td><td><strong>100%</strong></td><td></td></tr>';
    echo '</table>';
    
    // Signature
    echo '<div style="margin-top: 40px;">';
    echo '<table style="border: none; width: 100%;">';
    echo '<tr style="border: none;">';
    echo '<td style="border: none; width: 50%; text-align: center;"><strong>Mengetahui,</strong><br>Asmen Konstruksi<br><br><br><br><br>______________________</td>';
    echo '<td style="border: none; width: 50%; text-align: center;">Manado, ' . date('d') . ' ' . ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][date('n')] . ' ' . date('Y') . '<br><br><br><br><br><br>______________________</td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
    
    echo '</body></html>';
    exit;
}

// ===== PDF EXPORT (Editable on-screen, same as generate_document.php) =====
// This renders an editable HTML page with download/save capabilities
$logoPath = '/public/assets/img/logopln.png';
$monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$currentDate = date('d') . ' ' . $monthNames[date('n')] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($docTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        @page { size: A4; margin: 15mm; }
        body { background: #e9ecef; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; }
        .toolbar { 
            background: #fff; padding: 15px 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            position: sticky; top: 0; z-index: 100;
            font-family: 'Inter', -apple-system, sans-serif !important;
        }
        .toolbar * { font-family: 'Inter', -apple-system, sans-serif !important; }
        .document-wrapper { padding: 20px; display: flex; flex-direction: column; gap: 20px; align-items: center; }
        .page-container {
            background: white; width: 210mm; min-height: 297mm; padding: 15mm 20mm;
            box-shadow: 0 0 20px rgba(0,0,0,0.15); position: relative;
            font-family: 'Times New Roman', Times, serif; font-size: 12px;
        }
        .page-header-logo {
            position: absolute; top: 10mm; right: 15mm; text-align: right; font-size: 11px; color: #000;
        }
        .page-header-logo .pln-logo-img { width: 60px; height: 60px; margin-bottom: 3px; }
        .page-header-logo .company-name { font-weight: bold; color: #000; }
        .document-title { text-align: center; margin: 80px 0 15px; }
        .document-title h4 { font-weight: bold; color: #000; font-size: 16px; margin: 0; }
        .document-title h5 { font-weight: normal; color: #555; font-size: 12px; margin: 5px 0 0; }
        .editable { 
            background-color: #fffef0; border-bottom: 1px dashed #ccc;
            padding: 1px 3px; min-width: 30px; display: inline-block;
        }
        .editable:focus { outline: 2px solid #0066cc; background: #fff; }
        .items-table { width: 100%; border-collapse: collapse; font-size: 11px; margin: 10px 0; }
        .items-table th, .items-table td { border: 1px solid #333; padding: 5px 8px; text-align: center; vertical-align: middle; }
        .items-table th { background: #f5f5f5; font-weight: bold; color: #000; }
        .items-table td.text-left { text-align: left; }
        .items-table .img-cell img { width: 80px; height: 60px; object-fit: contain; border-radius: 4px; }
        .signature-section { margin-top: 30px; font-size: 12px; }
        .signature-table { width: 100%; border: none !important; }
        .signature-table td { border: none !important; text-align: center; padding: 5px 15px; vertical-align: top; }
        .signature-box { height: 60px; }
        .signature-line { border-bottom: 1px solid #333; width: 70%; margin: 0 auto; }
        .total-row td { font-weight: bold; background: #f9f9f9; }
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
                    <h5 class="mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i><?= htmlspecialchars($docTitle) ?></h5>
                    <small class="text-muted">Periode: <?= htmlspecialchars($periodLabel) ?></small>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="downloadPDF()">
                        <i class="bi bi-file-pdf me-1"></i>Download PDF
                    </button>
                    <a href="/index.php" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="document-wrapper">
        <div class="page-container" id="page1">
            <!-- Logo Header -->
            <div class="page-header-logo">
                <img src="<?= $logoPath ?>" alt="PLN Logo" class="pln-logo-img"><br>
                <span class="company-name editable" contenteditable="true">PT PLN (PERSERO)</span><br>
                <span class="editable" contenteditable="true">UIP3B Sulawesi</span><br>
                <span class="editable" contenteditable="true">UPT MANADO</span>
            </div>

            <!-- Title -->
            <div class="document-title">
                <h4 class="editable" contenteditable="true"><?= htmlspecialchars($docTitle) ?></h4>
                <h5 class="editable" contenteditable="true">Periode: <?= htmlspecialchars($periodLabel) ?></h5>
            </div>

            <!-- Table -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:30px;">No</th>
                        <th>Nama Barang</th>
                        <th style="width:100px;">Merek/Tipe</th>
                        <th style="width:55px;">Jumlah</th>
                        <th style="width:70px;">Persentase</th>
                        <th style="width:100px;">Gambar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item):
                        $pct = $grandTotal > 0 ? round(($item['count'] / $grandTotal) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td class="text-left"><span class="editable" contenteditable="true"><?= htmlspecialchars($item['name']) ?></span></td>
                        <td><span class="editable" contenteditable="true"><?= htmlspecialchars($item['item_type'] ?? '-') ?></span></td>
                        <td><span class="editable" contenteditable="true"><?= $item['count'] ?></span></td>
                        <td><?= $pct ?>%</td>
                        <td class="img-cell">
                            <?php 
                            if (!empty($item['primary_image'])):
                                $imgFile = __DIR__ . '/../../public/assets/uploads/' . $item['primary_image'];
                                if (file_exists($imgFile)):
                                    $imgBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($imgFile));
                            ?>
                                <img src="<?= $imgBase64 ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                            <?php else: ?>
                                <span style="color:#999; font-size:9px;">Gambar tidak tersedia</span>
                            <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#999; font-size:9px;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="6" style="padding: 20px; color: #999;">Tidak ada data untuk periode ini</td>
                    </tr>
                    <?php else: ?>
                    <tr class="total-row">
                        <td></td>
                        <td class="text-left"><strong>TOTAL</strong></td>
                        <td></td>
                        <td><strong><?= $grandTotal ?></strong></td>
                        <td><strong>100%</strong></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Signature Section -->
            <div class="signature-section">
                <table class="signature-table">
                    <tr>
                        <td style="width: 50%;">
                            <strong class="editable" contenteditable="true">Mengetahui,</strong><br>
                            <span class="editable" contenteditable="true">Asmen Konstruksi</span>
                            <div class="signature-box"></div>
                            <div class="signature-line"></div>
                            <div style="margin-top: 5px;">
                                <span class="editable" contenteditable="true" style="font-weight: 600;">(...............................)</span>
                            </div>
                        </td>
                        <td style="width: 50%;">
                            <span class="editable" contenteditable="true">Manado</span>, <span class="editable" contenteditable="true"><?= $currentDate ?></span><br>
                            <span class="editable" contenteditable="true">Admin Gudang</span>
                            <div class="signature-box"></div>
                            <div class="signature-line"></div>
                            <div style="margin-top: 5px;">
                                <span class="editable" contenteditable="true" style="font-weight: 600;">(...............................)</span>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    async function downloadPDF() {
        const { jsPDF } = window.jspdf;
        const element = document.getElementById('page1');
        
        // Temporarily hide editable styling
        element.querySelectorAll('.editable').forEach(el => {
            el.style.backgroundColor = 'transparent';
            el.style.borderBottom = 'none';
        });
        
        try {
            const canvas = await html2canvas(element, {
                scale: 2,
                useCORS: true,
                allowTaint: true,
                backgroundColor: '#ffffff',
                logging: false
            });
            
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jsPDF('p', 'mm', 'a4');
            const pdfWidth = pdf.internal.pageSize.getWidth();
            const pdfHeight = pdf.internal.pageSize.getHeight();
            const imgWidth = canvas.width;
            const imgHeight = canvas.height;
            const ratio = Math.min(pdfWidth / imgWidth, pdfHeight / imgHeight);
            const x = (pdfWidth - imgWidth * ratio) / 2;
            
            pdf.addImage(imgData, 'PNG', x, 0, imgWidth * ratio, imgHeight * ratio);
            
            const filename = '<?= str_replace("'", "\\'", $docTitle) ?>_<?= date('Ymd') ?>.pdf';
            pdf.save(filename);
        } catch (err) {
            alert('Gagal membuat PDF: ' + err.message);
        }
        
        // Restore editable styling
        element.querySelectorAll('.editable').forEach(el => {
            el.style.backgroundColor = '#fffef0';
            el.style.borderBottom = '1px dashed #ccc';
        });
    }
    </script>
</body>
</html>
<?php exit; ?>
