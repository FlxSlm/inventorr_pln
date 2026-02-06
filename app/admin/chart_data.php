<?php
// app/admin/chart_data.php
// AJAX endpoint: returns chart data filtered by semester range
// Called via GET with params: type (borrow|request), sem_start, year_start, sem_end, year_end

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';

$type = $_GET['type'] ?? ''; // borrow or request
$semStart = (int)($_GET['sem_start'] ?? 1);
$yearStart = (int)($_GET['year_start'] ?? date('Y'));
$semEnd = (int)($_GET['sem_end'] ?? ($semStart ?: 1));
$yearEnd = (int)($_GET['year_end'] ?? ($yearStart ?: date('Y')));

// Convert semester to date range
// Semester 1 = Jan 1 - Jun 30, Semester 2 = Jul 1 - Dec 31
$dateStart = $yearStart . '-' . ($semStart === 1 ? '01-01' : '07-01');
$dateEnd = $yearEnd . '-' . ($semEnd === 1 ? '06-30' : '12-31') . ' 23:59:59';

// Validate
if (!in_array($type, ['borrow', 'request'])) {
    echo json_encode(['error' => 'Invalid type']);
    exit;
}

if ($type === 'borrow') {
    $stmt = $pdo->prepare("
        SELECT i.id as inventory_id, i.name, i.item_type, i.image,
               COUNT(l.id) as count, SUM(l.quantity) as total_qty,
               (SELECT c.color FROM inventory_categories ic 
                JOIN categories c ON c.id = ic.category_id 
                WHERE ic.inventory_id = i.id LIMIT 1) as category_color
        FROM loans l
        JOIN inventories i ON i.id = l.inventory_id
        WHERE l.stage = 'approved'
          AND l.requested_at BETWEEN ? AND ?
        GROUP BY l.inventory_id
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute([$dateStart, $dateEnd]);
} else {
    $stmt = $pdo->prepare("
        SELECT i.id as inventory_id, i.name, i.item_type, i.image,
               COUNT(r.id) as count, SUM(r.quantity) as total_qty,
               (SELECT c.color FROM inventory_categories ic 
                JOIN categories c ON c.id = ic.category_id 
                WHERE ic.inventory_id = i.id LIMIT 1) as category_color
        FROM requests r
        JOIN inventories i ON i.id = r.inventory_id
        WHERE r.stage = 'approved'
          AND r.requested_at BETWEEN ? AND ?
        GROUP BY r.inventory_id
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute([$dateStart, $dateEnd]);
}

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = [];
$data = [];
$colors = [];
$totalAll = 0;

foreach ($items as $item) {
    $labels[] = $item['name'];
    $data[] = (int)$item['count'];
    $colors[] = $item['category_color'] ?: ($type === 'borrow' ? '#1a9aaa' : '#f59e0b');
    $totalAll += (int)$item['count'];
}

echo json_encode([
    'labels' => $labels,
    'data' => $data,
    'colors' => $colors,
    'total' => $totalAll,
    'count_types' => count($labels),
    'top_name' => $labels[0] ?? '',
    'top_count' => $data[0] ?? 0,
    'items' => $items,
    'period' => [
        'start' => $dateStart,
        'end' => $dateEnd,
        'label' => "Semester $semStart $yearStart" . ($semStart !== $semEnd || $yearStart !== $yearEnd ? " - Semester $semEnd $yearEnd" : '')
    ]
]);
