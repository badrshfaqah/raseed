<?php
/**
 * رصيد - لوحة التحكم
 */
require __DIR__ . '/includes/init.php';
require_login();

$totals = tx_totals('', []);

$latest = q('SELECT t.*, c.name AS category_name, i.name AS item_name, u.name AS user_name,
             ' . tx_tags_subquery() . ' AS tag_names
             ' . tx_base_query() . '
             ORDER BY t.trans_date DESC, t.id DESC
             LIMIT 10')->fetchAll();

$pageTitle = 'لوحة التحكم';
require BASE_PATH . '/includes/layout/header.php';
?>

<!-- بطاقات الإجماليات -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-icon income"><i class="bi bi-arrow-down-circle"></i></div>
            <div>
                <div class="stat-label">إجمالي الإيرادات</div>
                <div class="stat-value"><?= format_amount($totals['income']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-icon expense"><i class="bi bi-arrow-up-circle"></i></div>
            <div>
                <div class="stat-label">إجمالي المصروفات</div>
                <div class="stat-value"><?= format_amount($totals['expense']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-icon balance"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="stat-label">الرصيد الحالي</div>
                <div class="stat-value <?= $totals['balance'] >= 0 ? 'positive' : 'negative' ?>">
                    <?= format_amount($totals['balance']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (can_add()): ?>
<div class="d-flex gap-2 mb-4 flex-wrap">
    <a href="<?= APP_URL ?>transaction_add.php?type=income" class="btn btn-success">
        <i class="bi bi-plus-circle"></i> إضافة إيراد
    </a>
    <a href="<?= APP_URL ?>transaction_add.php?type=expense" class="btn btn-danger">
        <i class="bi bi-plus-circle"></i> إضافة مصروف
    </a>
    <a href="<?= APP_URL ?>transactions.php" class="btn btn-outline-primary ms-auto">
        كشف الحساب الكامل <i class="bi bi-arrow-left"></i>
    </a>
</div>
<?php endif; ?>

<!-- آخر العمليات -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history"></i> آخر العمليات</span>
    </div>
    <div class="card-body p-0">
        <?php if (!$latest): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                لا توجد عمليات مسجلة بعد
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-mobile align-middle">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>النوع</th>
                            <th>التصنيف</th>
                            <th>البند</th>
                            <th>التاق</th>
                            <th>المبلغ</th>
                            <th>الملاحظات</th>
                            <th>المستخدم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($latest as $t): ?>
                            <tr>
                                <td data-label="التاريخ"><?= format_date($t['trans_date']) ?></td>
                                <td data-label="النوع">
                                    <span class="badge badge-<?= $t['type'] ?>"><?= type_label($t['type']) ?></span>
                                </td>
                                <td data-label="التصنيف"><?= e($t['category_name']) ?></td>
                                <td data-label="البند"><?= e($t['item_name']) ?></td>
                                <td data-label="التاق">
                                    <?php if (!empty($t['tag_names'])): ?>
                                        <?php foreach (explode('، ', $t['tag_names']) as $tgName): ?>
                                            <span class="badge text-bg-light border mb-1"><?= e($tgName) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td data-label="المبلغ" class="amount-<?= $t['type'] ?>">
                                    <?= ($t['type'] === 'expense' ? '-' : '+') . ' ' . format_amount($t['amount']) ?>
                                </td>
                                <td data-label="الملاحظات"><?= e($t['notes']) ?: '-' ?></td>
                                <td data-label="المستخدم"><?= e($t['user_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>
