<?php
/**
 * رصيد - إضافة عملية (إيراد / مصروف)
 */
require __DIR__ . '/includes/init.php';
require_can_add();
require BASE_PATH . '/includes/GoogleSheets.php';

$type = ($_GET['type'] ?? 'income') === 'expense' ? 'expense' : 'income';
$isIncome = $type === 'income';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type       = ($_POST['type'] ?? '') === 'expense' ? 'expense' : 'income';
    $isIncome   = $type === 'income';
    $transDate  = trim($_POST['trans_date'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $itemId     = (int)($_POST['item_id'] ?? 0);
    $amount     = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
    $notes      = trim($_POST['notes'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transDate) || !strtotime($transDate)) {
        $errors[] = 'يرجى إدخال تاريخ صحيح.';
    }
    if ($categoryId <= 0) {
        $errors[] = 'يرجى اختيار التصنيف.';
    }
    if ($itemId <= 0) {
        $errors[] = 'يرجى اختيار البند.';
    }
    if ($amount <= 0) {
        $errors[] = 'المبلغ يجب أن يكون أكبر من صفر (لا يسمح بالصفر أو القيم السالبة).';
    }

    // التحقق من أن البند يتبع التصنيف المختار فعلاً
    if (!$errors) {
        $valid = q('SELECT id FROM items WHERE id = ? AND category_id = ? AND status = 1', [$itemId, $categoryId])->fetch();
        if (!$valid) {
            $errors[] = 'البند المختار لا يتبع هذا التصنيف.';
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            q('INSERT INTO transactions (type, trans_date, category_id, item_id, amount, notes, user_id, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
              [$type, $transDate, $categoryId, $itemId, $amount, $notes ?: null, current_user()['id']]);
            $txId = (int)db()->lastInsertId();
            db()->commit();

            // المزامنة مع Google Sheets بعد نجاح الحفظ (فشلها لا يوقف العملية)
            $synced = GoogleSheets::sync($txId, 'add');

            $msg = 'تم حفظ ' . type_label($type) . ' بنجاح.';
            if (GoogleSheets::enabled()) {
                $msg .= $synced ? ' وتمت المزامنة مع Google Sheets.' : ' (تعذّرت المزامنة مع Google Sheets - راجع سجل المزامنة)';
            }
            flash($synced || !GoogleSheets::enabled() ? 'success' : 'warning', $msg);
            redirect(APP_URL . 'transaction_add.php?type=' . $type);
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            log_error('transaction_add: ' . $e->getMessage());
            $errors[] = 'حدث خطأ أثناء الحفظ، يرجى المحاولة مجدداً.';
        }
    }
}

$categories = q('SELECT id, name FROM categories WHERE status = 1 ORDER BY name')->fetchAll();

$pageTitle = $isIncome ? 'إضافة إيراد' : 'إضافة مصروف';
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-<?= $isIncome ? 'arrow-down-circle text-success' : 'arrow-up-circle text-danger' ?>"></i>
                <?= $pageTitle ?>
            </div>
            <div class="card-body">
                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger py-2"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type" value="<?= $type ?>">

                    <div class="mb-3">
                        <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="trans_date"
                               value="<?= e($_POST['trans_date'] ?? date('Y-m-d')) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">التصنيف <span class="text-danger">*</span></label>
                        <select class="form-select" name="category_id" data-items-target="#itemSelect" required>
                            <option value="">-- اختر التصنيف --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (int)($_POST['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">البند <span class="text-danger">*</span></label>
                        <select class="form-select" name="item_id" id="itemSelect"
                                data-selected="<?= (int)($_POST['item_id'] ?? 0) ?>" required>
                            <option value="">-- اختر البند --</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">المبلغ (<?= e(setting('currency', 'ر.س')) ?>) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" step="0.01" min="0.01"
                               value="<?= e($_POST['amount'] ?? '') ?>" required dir="ltr" placeholder="0.00">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">الملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"><?= e($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-<?= $isIncome ? 'success' : 'danger' ?> w-100 btn-lg">
                        <i class="bi bi-check-circle"></i> حفظ <?= type_label($type) ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>
