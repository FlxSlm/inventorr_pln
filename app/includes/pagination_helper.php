<?php
// Pagination Helper Function
// Usage: renderPaginationHeader($currentPage, $totalPages, $displayFrom, $displayTo, $totalItems, $pageName)

function renderPaginationHeader($currentPage, $totalPages, $displayFrom, $displayTo, $totalItems, $pageName = 'admin_loans') {
    if ($totalItems == 0) return '';
    
    ob_start();
    ?>
    <div style="padding: 12px 24px; border-bottom: 1px solid var(--border-color); background: var(--bg-white);">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="text-muted small">
                <i class="bi bi-list-ul me-1"></i>
                Menampilkan <strong><?= $displayFrom ?></strong> - <strong><?= $displayTo ?></strong> dari <strong><?= $totalItems ?></strong> data
            </div>
            
            <?php if ($totalPages > 1): ?>
            <nav aria-label="Pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pageName ?>&p=1"><i class="bi bi-chevron-bar-left"></i></a>
                    </li>
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pageName ?>&p=<?= $currentPage - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    
                    <?php
                    $pageRange = 2;
                    $startPage = max(1, $currentPage - $pageRange);
                    $endPage = min($totalPages, $currentPage + $pageRange);
                    if ($startPage > 1): ?>
                        <li class="page-item"><a class="page-link" href="?page=<?= $pageName ?>&p=1">1</a></li>
                        <?php if ($startPage > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $pageName ?>&p=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="?page=<?= $pageName ?>&p=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pageName ?>&p=<?= $currentPage + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pageName ?>&p=<?= $totalPages ?>"><i class="bi bi-chevron-bar-right"></i></a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderPaginationFooter($currentPage, $totalPages, $pageName = 'admin_loans') {
    if ($totalPages <= 1) return '';
    
    ob_start();
    ?>
    <div style="padding: 12px 24px; border-top: 1px solid var(--border-color); background: var(--bg-white);">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="text-muted small">
                Halaman <?= $currentPage ?> dari <?= $totalPages ?>
            </div>
            
            <nav aria-label="Pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pageName ?>&p=1"><i class="bi bi-chevron-bar-left"></i></a>
                    </li>
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pageName ?>&p=<?= $currentPage - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    
                    <?php
                    $pageRange = 2;
                    $startPage = max(1, $currentPage - $pageRange);
                    $endPage = min($totalPages, $currentPage + $pageRange);
                    if ($startPage > 1): ?>
                        <li class="page-item"><a class="page-link" href="?page=<?= $pageName ?>&p=1">1</a></li>
                        <?php if ($startPage > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $pageName ?>&p=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="?page=<?= $pageName ?>&p=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pageName ?>&p=<?= $currentPage + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pageName ?>&p=<?= $totalPages ?>"><i class="bi bi-chevron-bar-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
