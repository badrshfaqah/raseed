<?php
/**
 * رصيد - حذف عملية (مدير النظام فقط)
 */
require __DIR__ . '/includes/init.php';
require_admin();
require BASE_PATH . '/includes/GoogleSheets.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . 'transactions.php');
}
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
$tx = q('SELECT id FROM transactions WHERE id = ?', [$id])->fetch();

if (!$tx) {
    flash('danger', 'العملية غير موجودة.');
    redirect(APP_URL . 'transactions.php');
}

try {
    // المزامنة أولاً (حذف الصف من الشيت) ثم الحذف من قاعدة البيانات
    $synced = GoogleSheets::sync($id, 'delete');

    q('DELETE FROM transactions WHERE id = ?', [$id]);

    $msg = 'تم حذف العملية بنجاح.';
    if (GoogleSheets::enabled() && !$synced) {
        $msg .= ' (تعذّر حذفها من Google Sheets - راجع سجل المزامنة)';
    }
    flash($synced || !GoogleSheets::enabled() ? 'success' : 'warning', $msg);
} catch (Throwable $e) {
    log_error('transaction_delete: ' . $e->getMessage());
    flash('danger', 'حدث خطأ أثناء الحذف.');
}

redirect(APP_URL . 'transactions.php');
