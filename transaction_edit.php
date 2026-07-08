<?php
/**
 * رصيد - تعديل عملية (مدير النظام فقط)
 */
require __DIR__ . '/includes/init.php';
require_admin();
require BASE_PATH . '/includes/GoogleSheets.php';

$id = (int)($_GET['id'] ?? 0);
$tx = q('SELECT * FROM transactions WHERE id = ?', [$id])->fetch();

if (!$tx) {
    flash('danger', 'العملية غير موجودة.');
    redirect(APP_URL . 'transactions.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type       = ($_POST['type'] ?? '') === 'expense' ? 'expense' : 'income';
    $transDate  = trim($_POST['trans_date'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $itemId     = (int)($_POST['item_id'] ?? 0);
    $tagId      = (int)($_POST['tag_id'] ?? 0);
    $amount     = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
    $notes      = trim($_POST['notes'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transDate) || !strtotime($transDate)) {
        $errors[] = 'يرجى إدخال تاريخ صحيح.';
    }
    if ($categoryId <= 0 || $itemId <= 0) {
        $errors[] = 'يرجى اختيار التصنيف والبند.';
    }
    if ($amount <= 0) {
        $errors[] = 'المبلغ يجب أن يكون أكبر من صفر (لا يسمح بالصفر أو القيم السالبة).';
    } elseif ($amount > 999999999999.99) {
        $errors[] = 'المبلغ يتجاوز الحد الأقصى المسموح.';
    }
    if (mb_strlen($notes) > 1000) {
        $errors[] = 'الملاحظات يجب ألا تتجاوز 1000 حرف.';
    }
    if (!$errors) {
        $valid = q('SELECT id FROM items WHERE id = ? AND category_id = ?', [$itemId, $categoryId])->fetch();
        if (!$valid) {
            $errors[] = 'البند المختار لا يتبع هذا التصنيف.';
        }
    }
    if (!$errors && $tagId > 0) {
        if (!q('SELECT id FROM tags WHERE id = ?', [$tagId])->fetch()) {
            $errors[] = 'التاق المختار غير صالح.';
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            q('UPDATE transactions SET type = ?, trans_date = ?, category_id = ?, item_id = ?, tag_id = ?, amount = ?, notes = ?
               WHERE id = ?',
              [$type, $transDate, $categoryId, $itemId, $tagId ?: null, $amount, $notes ?: null, $id]);
            db()->commit();

            $synced = GoogleSheets::sync($id, 'update');

            $msg = 'تم تحديث العملية بنجاح.';
            if (GoogleSheets::enabled() && !$synced) {
                $msg .= ' (تعذّرت المزامنة مع Google Sheets - راجع سجل المزامنة)';
            }
            flash($synced || !GoogleSheets::enabled() ? 'success' : 'warning', $msg);
            redirect(APP_URL . 'transactions.php');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            log_error('transaction_edit: ' . $e->getMessage());
            $errors[] = 'حدث خطأ أثناء الحفظ، يرجى المحاولة مجدداً.';
        }
    }

    // إعادة عرض القيم المرسلة عند وجود أخطاء
    $tx = array_merge($tx, [
        'type' => $type, 'trans_date' => $transDate, 'category_id' => $categoryId,
        'item_id' => $itemId, 'tag_id' => $tagId, 'amount' => $amount, 'notes' => $notes,
    ]);
}

$categories = q('SELECT id, name FROM categories WHERE status = 1 ORDER BY name')->fetchAll();
$tags       = q('SELECT id, name FROM tags WHERE status = 1 ORDER BY name')->fetchAll();

$pageTitle = 'تعديل عملية #' . $id;
require BASE_PATH . '/includes/layout/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil-square"></i> تعديل العملية</div>
            <div class="card-body">
                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger py-2"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">النوع <span class="text-danger">*</span></label>
                        <select class="form-select" name="type" required>
                            <option value="income" <?= $tx['type'] === 'income' ? 'selected' : '' ?>>إيراد</option>
                            <option value="expense" <?= $tx['type'] === 'expense' ? 'selected' : '' ?>>مصروف</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="trans_date" value="<?= e($tx['trans_date']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">التصنيف <span class="text-danger">*</span></label>
                        <select class="form-select" name="category_id" data-items-target="#itemSelect" required>
                            <option value="">-- اختر التصنيف --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (int)$tx['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">البند <span class="text-danger">*</span></label>
                        <select class="form-select" name="item_id" id="itemSelect"
                                data-selected="<?= (int)$tx['item_id'] ?>" required>
                            <option value="">-- اختر البند --</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">التاق <span class="text-muted small">(اختياري)</span></label>
                        <select class="form-select" name="tag_id">
                            <option value="">-- بدون تاق --</option>
                            <?php foreach ($tags as $tg): ?>
                                <option value="<?= $tg['id'] ?>" <?= (int)($tx['tag_id'] ?? 0) === (int)$tg['id'] ? 'selected' : '' ?>>
                                    <?= e($tg['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">المبلغ (<?= e(setting('currency', 'ر.س')) ?>) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" step="0.01" min="0.01"
                               value="<?= e((string)$tx['amount']) ?>" required dir="ltr">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">الملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3" maxlength="1000"><?= e($tx['notes']) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-check-circle"></i> حفظ التعديلات
                        </button>
                        <a href="<?= APP_URL ?>transactions.php" class="btn btn-outline-secondary">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require BASE_PATH . '/includes/layout/footer.php'; ?>
