<?php
/**
 * رصيد - API: بنود تصنيف معيّن (JSON)
 */
require dirname(__DIR__) . '/includes/init.php';

if (!current_user()) {
    json_response(['error' => 'unauthorized'], 401);
}

$categoryId = (int)($_GET['category_id'] ?? 0);
if ($categoryId <= 0) {
    json_response(['items' => []]);
}

$items = q('SELECT id, name FROM items WHERE category_id = ? AND status = 1 ORDER BY name', [$categoryId])->fetchAll();

json_response(['items' => $items]);
