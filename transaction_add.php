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
    $tagIds     = array_map('intval', (array)($_POST['tag_ids'] ?? []));
    $amount     = (float)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
    $notes      = trim($_POST['notes'] ?? '');
    $isAsset    = ($type === 'expense' && !empty($_POST['is_asset'])) ? 1 : 0;
    $assetName  = $isAsset ? trim($_POST['asset_name'] ?? '') : '';

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
    } elseif ($amount > 999999999999.99) {
        $errors[] = 'المبلغ يتجاوز الحد الأقصى المسموح.';
    }
    if (mb_strlen($notes) > 1000) {
        $errors[] = 'الملاحظات يجب ألا تتجاوز 1000 حرف.';
    }
    if (mb_strlen($assetName) > 150) {
        $errors[] = 'اسم/تفاصيل الأصل يجب ألا يتجاوز 150 حرفاً.';
    }

    // التحقق من أن البند يتبع التصنيف المختار فعلاً
    if (!$errors) {
        $valid = q('SELECT id FROM items WHERE id = ? AND category_id = ? AND status = 1', [$itemId, $categoryId])->fetch();
        if (!$valid) {
            $errors[] = 'البند المختار لا يتبع هذا التصنيف.';
        }
    }
    // التاقات اختيارية؛ نُبقي الصالحة المفعّلة فقط
    $validTagIds = array_map('intval', q('SELECT id FROM tags WHERE status = 1')->fetchAll(PDO::FETCH_COLUMN));
    if (!$errors) {
        foreach ($tagIds as $tid) {
            if ($tid > 0 && !in_array($tid, $validTagIds, true)) {
                $errors[] = 'أحد التاقات المختارة غير صالح.';
                break;
            }
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            q('INSERT INTO transactions (type, trans_date, category_id, item_id, amount, is_asset, asset_name, notes, user_id, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
              [$type, $transDate, $categoryId, $itemId, $amount, $isAsset, $assetName ?: null, $notes ?: null, current_user()['id']]);
            $txId = (int)db()->lastInsertId();
            set_tx_tags($txId, $tagIds, $validTagIds);
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
$tags       = q('SELECT id, name FROM tags WHERE status = 1 ORDER BY name')->fetchAll();

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

                    <?php $selectedTags = array_map('intval', (array)($_POST['tag_ids'] ?? [])); ?>
                    <div class="mb-3">
                        <label class="form-label">التاقات <span class="text-muted small">(اختياري - يمكن اختيار أكثر من تاق)</span></label>
                        <select class="form-select" name="tag_ids[]" multiple size="5">
                            <?php foreach ($tags as $tg): ?>
                                <option value="<?= $tg['id'] ?>" <?= in_array((int)$tg['id'], $selectedTags, true) ? 'selected' : '' ?>>
                                    <?= e($tg['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">اضغط Ctrl (أو Cmd على ماك) مع النقر لاختيار عدة تاقات، مثل: خشب، مشروع كذا، الموظف فلان.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">المبلغ (<?= e(setting('currency', 'ر.س')) ?>) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" step="0.01" min="0.01"
                               value="<?= e($_POST['amount'] ?? '') ?>" required dir="ltr" placeholder="0.00">
                    </div>

                    <?php if (!$isIncome): ?>
                    <div class="mb-3 asset-block">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_asset" id="isAsset" value="1"
                                   data-asset-toggle <?= !empty($_POST['is_asset']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isAsset">
                                <i class="bi bi-box-seam"></i> تسجيل هذا المصروف كأصل (يظهر في قسم الأصول)
                            </label>
                        </div>
                        <div class="mt-2 <?= !empty($_POST['is_asset']) ? '' : 'd-none' ?>" data-asset-field>
                            <label class="form-label">اسم / تفاصيل الأصل</label>
                            <input type="text" class="form-control" name="asset_name" maxlength="150"
                                   value="<?= e($_POST['asset_name'] ?? '') ?>"
                                   placeholder="مثال: كاميرا Canon R5، عدسة 24-70، إضاءة استوديو...">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-4">
                        <label class="form-label">الملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3" maxlength="1000"><?= e($_POST['notes'] ?? '') ?></textarea>
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
