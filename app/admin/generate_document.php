<?php
// app/admin/generate_document.php
// Auto-fill Document Generator for Berita Acara

$pdo = require __DIR__ . '/../config/database.php';

$docType = $_GET['type'] ?? ''; // loan, request, return
$refId = $_GET['ref'] ?? ''; // group_id or single_<id>

if (!$docType || !$refId) {
    die('Invalid parameters');
}

// Function to convert month number to Roman numerals
function getRomanMonth($month) {
    $romans = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $romans[$month - 1] ?? '';
}

// Function to generate document number
function generateDocumentNumber($pdo, $docType) {
    $year = date('Y');
    $month = date('n');
    $romanMonth = getRomanMonth($month);
    
    try {
        $pdo->beginTransaction();
        
        // Get or create document number entry for this type/year/month
        $stmt = $pdo->prepare("
            SELECT last_number 
            FROM document_numbers 
            WHERE document_type = ? AND year = ? AND month = ?
            FOR UPDATE
        ");
        $stmt->execute([$docType, $year, $month]);
        $row = $stmt->fetch();
        
        if ($row) {
            $nextNumber = $row['last_number'] + 1;
            $updateStmt = $pdo->prepare("
                UPDATE document_numbers 
                SET last_number = ? 
                WHERE document_type = ? AND year = ? AND month = ?
            ");
            $updateStmt->execute([$nextNumber, $docType, $year, $month]);
        } else {
            $nextNumber = 1;
            $insertStmt = $pdo->prepare("
                INSERT INTO document_numbers (document_type, year, month, last_number) 
                VALUES (?, ?, ?, ?)
            ");
            $insertStmt->execute([$docType, $year, $month, $nextNumber]);
        }
        
        $pdo->commit();
        
        // Format: 001/UPT Manado/II/2026
        $documentNumber = sprintf('%03d/UPT Manado/%s/%d', $nextNumber, $romanMonth, $year);
        return $documentNumber;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Generate document number
$documentNumber = generateDocumentNumber($pdo, $docType);

// Fetch data based on type and reference
$items = [];
$userName = '';
$transactionDate = '';

if (strpos($refId, 'single_') === 0) {
    // Single item transaction
    $itemId = str_replace('single_', '', $refId);
    
    if ($docType === 'loan') {
        $stmt = $pdo->prepare("
            SELECT l.*, i.name as item_name, i.unit, i.image, i.code, i.description,
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
            $userName = $item['full_name'];
            $transactionDate = $item['transaction_date'];
        }
    } elseif ($docType === 'request') {
        $stmt = $pdo->prepare("
            SELECT r.*, i.name as item_name, i.unit, i.image, i.code, i.description,
                   u.name, r.request_date as transaction_date
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
            SELECT l.*, i.name as item_name, i.unit, i.image, i.code, i.description,
                   u.name, l.returned_at as transaction_date
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
    // Grouped transaction
    $groupId = $refId;
    
    if ($docType === 'loan') {
        $stmt = $pdo->prepare("
            SELECT l.*, i.name as item_name, i.unit, i.image, i.code, i.description,
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
            SELECT r.*, i.name as item_name, i.unit, i.image, i.code, i.description,
                   u.name, r.request_date as transaction_date
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
            SELECT l.*, i.name as item_name, i.unit, i.image, i.code, i.description,
                   u.name, l.returned_at as transaction_date
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

// Save document generation record
$stmt = $pdo->prepare("
    INSERT INTO generated_documents (document_type, reference_id, document_number, generated_data, status) 
    VALUES (?, ?, ?, ?, 'generated')
");
$generatedData = json_encode([
    'items' => $items,
    'user_name' => $userName,
    'transaction_date' => $transactionDate,
    'generated_at' => date('Y-m-d H:i:s')
]);
$stmt->execute([$docType, $refId, $documentNumber, $generatedData]);
$documentId = $pdo->lastInsertId();

$docTitle = ($docType === 'loan') ? 'Peminjaman' : (($docType === 'return') ? 'Pengembalian' : 'Permintaan');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Document - Berita Acara <?= $docTitle ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .document-container {
            background: white;
            padding: 40px;
            margin: 20px auto;
            max-width: 1000px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .document-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .document-title {
            font-weight: bold;
            text-decoration: underline;
            margin: 20px 0;
        }
        .document-number {
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table, th, td {
            border: 1px solid #000;
        }
        th, td {
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .editable {
            background-color: #fffacd;
            min-height: 20px;
            padding: 5px;
        }
        .editable:focus {
            outline: 2px solid #007bff;
            background-color: #fff;
        }
        .photo-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
        }
        .photo-container img {
            max-width: 150px;
            max-height: 150px;
            object-fit: contain;
        }
        .action-buttons {
            margin: 20px 0;
            text-align: center;
        }
        .btn-group-custom {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="document-container" id="documentContent">
            <div class="document-header">
                <h4>BERITA ACARA SERAH TERIMA</h4>
                <h5>BARANG <?= strtoupper($docTitle) ?></h5>
                <div class="document-number">
                    <strong>Nomor: <?= htmlspecialchars($documentNumber) ?></strong>
                </div>
            </div>

            <div class="document-body">
                <p>Pada hari ini, <span class="editable" contenteditable="true"><?= date('l, d F Y', strtotime($transactionDate)) ?></span>, 
                telah dilakukan serah terima barang <?= $docTitle ?> kepada:</p>
                
                <table style="width: auto; border: none; margin: 10px 0;">
                    <tr style="border: none;">
                        <td style="border: none; width: 150px;">Nama</td>
                        <td style="border: none; width: 20px;">:</td>
                        <td style="border: none;"><span class="editable" contenteditable="true"><?= htmlspecialchars($userName) ?></span></td>
                    </tr>
                    <tr style="border: none;">
                        <td style="border: none;">Jabatan</td>
                        <td style="border: none;">:</td>
                        <td style="border: none;"><span class="editable" contenteditable="true">-</span></td>
                    </tr>
                    <tr style="border: none;">
                        <td style="border: none;">Unit Kerja</td>
                        <td style="border: none;">:</td>
                        <td style="border: none;"><span class="editable" contenteditable="true">PLN UPT Manado</span></td>
                    </tr>
                </table>

                <p>Dengan rincian barang sebagai berikut:</p>

                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Barang</th>
                            <th>Merek/Type</th>
                            <th>No. Seri</th>
                            <th>Jumlah</th>
                            <th>Satuan</th>
                            <th>Material</th>
                            <th>Dokumentasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td class="editable" contenteditable="true"><?= htmlspecialchars($item['item_name']) ?></td>
                            <td class="editable" contenteditable="true"><?= htmlspecialchars($item['brand'] ?? '-') ?></td>
                            <td class="editable" contenteditable="true"><?= htmlspecialchars($item['serial_number'] ?? '-') ?></td>
                            <td class="editable" contenteditable="true"><?= htmlspecialchars($item['quantity'] ?? 1) ?></td>
                            <td class="editable" contenteditable="true"><?= htmlspecialchars($item['unit'] ?? 'unit') ?></td>
                            <td class="editable" contenteditable="true"><?= htmlspecialchars($item['item_name']) ?></td>
                            <td>
                                <div class="photo-container">
                                    <?php if (!empty($item['image'])): ?>
                                        <img src="/public/assets/uploads/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>">
                                    <?php else: ?>
                                        <span>No Image</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 30px;">Demikian Berita Acara ini dibuat untuk dapat digunakan sebagaimana mestinya.</p>

                <table style="width: 100%; border: none; margin-top: 50px;">
                    <tr style="border: none;">
                        <td style="border: none; width: 50%; text-align: center;">
                            <p><strong>Yang Menyerahkan,</strong></p>
                            <br><br><br>
                            <p><span class="editable" contenteditable="true">[Nama Pejabat]</span></p>
                            <p><span class="editable" contenteditable="true">[Jabatan]</span></p>
                        </td>
                        <td style="border: none; width: 50%; text-align: center;">
                            <p><strong>Yang Menerima,</strong></p>
                            <br><br><br>
                            <p><span class="editable" contenteditable="true"><?= htmlspecialchars($userName) ?></span></p>
                            <p><span class="editable" contenteditable="true">[Jabatan]</span></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="action-buttons">
            <div class="btn-group-custom">
                <button class="btn btn-primary" onclick="downloadPDF()">
                    <i class="fas fa-file-pdf"></i> Download PDF
                </button>
                <button class="btn btn-success" onclick="downloadExcel()">
                    <i class="fas fa-file-excel"></i> Download Excel
                </button>
                <button class="btn btn-info" onclick="showUploadModal()">
                    <i class="fas fa-upload"></i> Upload Dokumen Final
                </button>
                <a href="javascript:history.back()" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Dokumen Final</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="/index.php?page=upload_generated_document" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="document_id" value="<?= $documentId ?>">
                        <input type="hidden" name="document_type" value="<?= $docType ?>">
                        <input type="hidden" name="reference_id" value="<?= $refId ?>">
                        
                        <div class="mb-3">
                            <label for="final_document" class="form-label">File Dokumen (PDF/Excel/Word)</label>
                            <input type="file" class="form-control" id="final_document" name="final_document" 
                                   accept=".pdf,.xlsx,.xls,.doc,.docx" required>
                            <div class="form-text">Max 10MB. Format: PDF, Excel, atau Word</div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="send_notification" name="send_notification" value="1" checked>
                            <label class="form-check-label" for="send_notification">
                                Kirim notifikasi ke karyawan
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Upload & Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://kit.fontawesome.com/a076d05399.js"></script>

    <script>
        function downloadPDF() {
            const element = document.getElementById('documentContent');
            
            // Remove editable highlighting for PDF
            const editables = element.querySelectorAll('.editable');
            editables.forEach(el => {
                el.style.backgroundColor = 'transparent';
            });
            
            html2canvas(element, {
                scale: 2,
                useCORS: true,
                logging: false
            }).then(canvas => {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                
                const imgWidth = 210; // A4 width in mm
                const imgHeight = canvas.height * imgWidth / canvas.width;
                const imgData = canvas.toDataURL('image/png');
                
                pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
                pdf.save('Berita_Acara_<?= $documentNumber ?>.pdf');
                
                // Restore editable highlighting
                editables.forEach(el => {
                    el.style.backgroundColor = '#fffacd';
                });
            });
        }

        function downloadExcel() {
            const data = [
                ['BERITA ACARA SERAH TERIMA'],
                ['BARANG <?= strtoupper($docTitle) ?>'],
                ['Nomor: <?= $documentNumber ?>'],
                [''],
                ['Nama', '<?= $userName ?>'],
                ['Tanggal', '<?= date('d F Y', strtotime($transactionDate)) ?>'],
                [''],
                ['No', 'Nama Barang', 'Merek/Type', 'No. Seri', 'Jumlah', 'Satuan', 'Material']
                <?php foreach ($items as $index => $item): ?>,
                [
                    '<?= $index + 1 ?>',
                    '<?= htmlspecialchars($item['item_name']) ?>',
                    '<?= htmlspecialchars($item['brand'] ?? '-') ?>',
                    '<?= htmlspecialchars($item['serial_number'] ?? '-') ?>',
                    '<?= htmlspecialchars($item['quantity'] ?? 1) ?>',
                    '<?= htmlspecialchars($item['unit'] ?? 'unit') ?>',
                    '<?= htmlspecialchars($item['item_name']) ?>'
                ]
                <?php endforeach; ?>
            ];

            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Berita Acara');
            XLSX.writeFile(wb, 'Berita_Acara_<?= $documentNumber ?>.xlsx');
        }

        function showUploadModal() {
            const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
            modal.show();
        }
    </script>
</body>
</html>
