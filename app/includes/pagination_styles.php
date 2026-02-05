<?php
// Pagination Styles - Gmail inspired
?>
<style>
/* Pagination Styles - Gmail inspired */
.pagination-controls { display: flex; align-items: center; }
.pagination { margin-bottom: 0; }
.pagination .page-link {
    border: 1px solid #dee2e6;
    color: #5f6368;
    padding: 0.375rem 0.75rem;
    margin: 0 2px;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: all 0.2s ease;
}
.pagination .page-link:hover {
    background-color: #f1f3f4;
    border-color: #dadce0;
    color: #202124;
}
.pagination .page-item.active .page-link {
    background-color: #1a73e8;
    border-color: #1a73e8;
    color: white;
    font-weight: 500;
}
.pagination .page-item.disabled .page-link {
    background-color: #fff;
    border-color: #dee2e6;
    color: #dadce0;
}
</style>
