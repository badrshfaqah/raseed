<?php
/**
 * رصيد - API: تبديل حالة استلام الإيصال/الفاتورة على عملية
 */
require dirname(__DIR__) . '/includes/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}
verify_csrf();

if (!can_toggle_receipt()) {
    json_response(['error' => 'forbidden'], 403);
}

$id = (int)($_POST['id'] ?? 0);
$tx = q('SELECT id, receipt_status FROM transactions WHERE id = ?', [$id])->fetch();
if (!$tx) {
    json_response(['error' => 'not_found'], 404);
}

$newStatus = $tx['receipt_status'] ? 0 : 1;
q('UPDATE transactions SET receipt_status = ? WHERE id = ?', [$newStatus, $id]);

json_response(['ok' => true, 'receipt_status' => $newStatus]);
