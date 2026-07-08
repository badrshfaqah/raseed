<?php
/**
 * رصيد - سجل مزامنة Google Sheets (مدير النظام فقط)
 */
require __DIR__ . '/includes/init.php';
require_admin();
require BASE_PATH . '/includes/GoogleSheets.php';

// إعادة محاولة مزامنة عملية فشلت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry') {
    verify_csrf();
    $txId = (int)($_POST['transaction_id'] ?? 0);

    // إذا كانت العملية موجودة في قاعدة البيانات نزامنها كتحديث
    // (update يضيف الصف إن لم يوجد، ويمنع تكراره إن وجد)، وإلا نحذفها من الشيت
    $exists = q('SELECT id FROM transactions WHERE id = ?', [$txId])->fetch();
    $ok = GoogleSheets::sync($txId, $exists ? 'update' : 'delete');
    flash($ok ? 'success' : 'danger', $ok ? 'تمت إعادة المزامنة بنجاح.' : 'فشلت إعادة المزامنة، راجع التفاصيل في السجل.');
    redirect(APP_URL . 'sync_log.php');
}

$statusFilter = in_array($_GET['status'] ?? '', ['success', 'failed'], true) ? $_GET['status'] : '';
$where = $statusFilter ? 'WHERE l.status = ?' : '';
$bind  = $statusFilter ? [$statusFilter] : [];

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$count   = (int)q("SELECT COUNT(*) FROM google_sheet_sync_logs l $where", $bind)->fetchColumn();
$pages   = max(1, (int)ceil($count / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$logs = q("SELECT l.* FROM google_sheet_sync_logs l
           $where
           ORDER BY l.id DESC
           LIMIT $perPage OFFSET $offset", $bind)->fetchAll();

$actionLabels = ['add' => 'إضافة', 'update' => 'تعديل', 'delete' => 'حذف'];

$pageTitle = 'سجل المزامنة';
require BASE_PATH . '/includes/layout/header.php';
?>

<?php if (!GoogleSheets::enabled()): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        مزامنة Google Sheets غير مفعّلة. يمكنك تفعيلها من
        <a href="<?= APP_URL ?>settings.php#sheets">إعدادات النظام</a>.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>سجل المزامنة (<?= number_format($count) ?>)</span>
        <form method="get" class="d-flex gap-2">
            <select class="form-select form-select-sm" name="status" data-autosubmit>
                <option value="">جميع الحالات</option>
                <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>ناجحة</option>
                <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>فاشلة</option>
            </select>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (!$logs): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-arrow-repeat fs-1 d-block mb-2"></i>
                لا توجد سجلات مزامنة
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-mobile align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>العملية</th>
                            <th>الإجراء</th>
                            <th>الحالة</th>
                            <th>التفاصيل</th>
                            <th>الوقت</th>
                            <th class="text-center">إعادة المحاولة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td data-label="#"><?= $l['id'] ?></td>
                                <td data-label="العملية">#<?= $l['transaction_id'] ?></td>
                                <td data-label="الإجراء"><?= $actionLabels[$l['action']] ?? e($l['action']) ?></td>
                                <td data-label="الحالة">
                                    <span class="badge text-bg-<?= $l['status'] === 'success' ? 'success' : 'danger' ?>">
                                        <?= $l['status'] === 'success' ? 'ناجحة' : 'فاشلة' ?>
                                    </span>
                                </td>
                                <td data-label="التفاصيل" class="small"><?= e($l['message']) ?></td>
                                <td data-label="الوقت"><?= format_datetime($l['created_at']) ?></td>
                                <td data-label="إعادة المحاولة" class="text-center">
                                    <?php if ($l['status'] === 'failed'): ?>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="retry">
                                            <input type="hidden" name="transaction_id" value="<?= $l['transaction_id'] ?>">
                                            <input type="hidden" name="sync_action" value="<?= e($l['action']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="إعادة المحاولة">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(['status' => $statusFilter, 'page' => $p]) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>
