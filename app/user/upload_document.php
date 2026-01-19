<?php
// app/user/upload_document.php
// Page where user uploads filled Excel for their loan with stage awaiting_document
if (!isset($_SESSION['user'])) {
    header('Location: /index.php?page=login');
    exit;
}
$pdo = require __DIR__ . '/../config/database.php';
$userId = (int)$_SESSION['user']['id'];

$loan_id = (int)($_GET['loan_id'] ?? 0);
if (!$loan_id) {
    echo "<div class='alert alert-danger'>Loan ID not provided.</div>";
    exit;
}

// fetch loan and check ownership and stage
$stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ? AND user_id = ?');
$stmt->execute([$loan_id, $userId]);
$loan = $stmt->fetch();
if (!$loan) {
    echo "<div class='alert alert-danger'>Loan not found.</div>";
    exit;
}
if ($loan['stage'] !== 'awaiting_document') {
    echo "<div class='alert alert-info'>This loan is not awaiting a document. Current stage: " . htmlspecialchars($loan['stage']) . "</div>";
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // validate upload
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a file to upload.';
    } else {
        $allowedExt = ['xlsx','xls'];
        $maxBytes = 5 * 1024 * 1024; // 5 MB

        $file = $_FILES['document'];
        $tmp = $file['tmp_name'];
        $name = $file['name'];
        $size = $file['size'];

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            $errors[] = 'Only Excel files (.xlsx, .xls) are allowed.';
        } elseif ($size > $maxBytes) {
            $errors[] = 'File too large (max 5 MB).';
        } else {
            // ensure uploads folder exists
            $destDir = __DIR__ . '/../../public/assets/uploads/documents/';
            if (!is_dir($destDir)) mkdir($destDir, 0775, true);

            // create safe name
            $safe = 'loan_'.$loan_id.'_user_'.$userId.'_'.time().'.'.$ext;
            $dest = $destDir . $safe;

            if (move_uploaded_file($tmp, $dest)) {
                // store relative path (from public)
                $relpath = 'assets/uploads/documents/' . $safe;
                $stmt = $pdo->prepare('UPDATE loans SET document_path = ?, document_submitted_at = NOW(), stage = ? WHERE id = ?');
                $stmt->execute([$relpath, 'submitted', $loan_id]);

                $success = 'File uploaded successfully. Waiting admin review.';
                // Optionally redirect
                header('Location: /index.php?page=history&msg=' . urlencode($success));
                exit;
            } else {
                $errors[] = 'Failed to move uploaded file.';
            }
        }
    }
}
?>

<div class="card p-3">
  <h5>Upload Filled Document</h5>
  <p class="small-muted">Download template: <a href="/public/assets/templates/BA STM ULTG GORONTALO.xlsx" target="_blank" download>Download Template Excel</a></p>

  <?php foreach($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>
  <?php if($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data">
    <div class="mb-3">
      <label>Upload Excel (.xlsx)</label>
      <input type="file" name="document" accept=".xlsx,.xls" class="form-control" required>
    </div>
    <button class="btn btn-primary">Upload & Submit</button>
  </form>
</div>
