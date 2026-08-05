<?php defined('RASEED') || exit; ?>
        </div><!-- /.page-body -->

        <footer class="app-footer">
            تطوير <a href="https://almgrat.com" target="_blank" rel="noopener">برمجة المجرات</a>
        </footer>
    </main>

    <!-- شريط التنقّل السفلي للجوال -->
    <?php
    $cp     = $currentPage ?? basename($_SERVER['SCRIPT_NAME']);
    $txType = $_GET['type'] ?? '';
    $mbnHome = $cp === 'dashboard.php' ? ' active' : '';
    $mbnExp  = ($cp === 'transaction_add.php' && $txType === 'expense') ? ' active' : '';
    $mbnInc  = ($cp === 'transaction_add.php' && $txType === 'income')  ? ' active' : '';
    $mbnList = $cp === 'transactions.php' ? ' active' : '';
    ?>
    <nav class="mobile-bottom-nav d-lg-none no-print" aria-label="تنقّل سريع">
        <a href="<?= APP_URL ?>dashboard.php" class="mbn-item<?= $mbnHome ?>">
            <i class="bi bi-house-door"></i><span>الرئيسية</span>
        </a>
        <?php if (can_add()): ?>
            <a href="<?= APP_URL ?>transaction_add.php?type=expense" class="mbn-item<?= $mbnExp ?>">
                <i class="bi bi-dash-circle"></i><span>مصروف</span>
            </a>
            <a href="<?= APP_URL ?>transaction_add.php?type=income" class="mbn-item<?= $mbnInc ?>">
                <i class="bi bi-plus-circle"></i><span>إيراد</span>
            </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>transactions.php" class="mbn-item<?= $mbnList ?>">
            <i class="bi bi-journal-text"></i><span>الكشف</span>
        </a>
        <button type="button" class="mbn-item js-sidebar-toggle">
            <i class="bi bi-list"></i><span>القائمة</span>
        </button>
    </nav>
</div><!-- /.layout -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>assets/js/app.js?v=<?= RASEED_VERSION ?>"></script>
<?= $pageScripts ?? '' ?>
</body>
</html>
