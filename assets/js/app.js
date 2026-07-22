/* رصيد - سلوك الواجهة العام */
(function () {
    'use strict';

    /* القائمة الجانبية على الجوال */
    const sidebar   = document.getElementById('sidebar');
    const backdrop  = document.getElementById('sidebarBackdrop');
    const toggleBtn = document.getElementById('sidebarToggle');

    function closeSidebar() {
        sidebar && sidebar.classList.remove('open');
        backdrop && backdrop.classList.remove('show');
    }

    toggleBtn && toggleBtn.addEventListener('click', function () {
        sidebar.classList.toggle('open');
        backdrop.classList.toggle('show');
    });
    backdrop && backdrop.addEventListener('click', closeSidebar);

    /* إرسال النموذج تلقائياً عند تغيير قوائم الفلترة */
    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            select.form && select.form.submit();
        });
    });

    /* تأكيد الحذف */
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    /* ربط التصنيف بالبنود: أي select يحمل data-items-target */
    document.querySelectorAll('select[data-items-target]').forEach(function (catSelect) {
        const itemSelect = document.querySelector(catSelect.getAttribute('data-items-target'));
        if (!itemSelect) return;

        const appUrl   = document.body.getAttribute('data-app-url') || './';
        const selected = itemSelect.getAttribute('data-selected') || '';

        function loadItems(categoryId, keepSelection) {
            itemSelect.innerHTML = '<option value="">-- اختر البند --</option>';
            if (!categoryId) return;
            fetch(appUrl + 'api/items.php?category_id=' + encodeURIComponent(categoryId), {
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    (data.items || []).forEach(function (item) {
                        const opt = document.createElement('option');
                        opt.value = item.id;
                        opt.textContent = item.name;
                        if (keepSelection && String(item.id) === selected) {
                            opt.selected = true;
                        }
                        itemSelect.appendChild(opt);
                    });
                })
                .catch(function () { /* يبقى القائمة فارغة */ });
        }

        catSelect.addEventListener('change', function () {
            loadItems(catSelect.value, false);
        });

        if (catSelect.value) {
            loadItems(catSelect.value, true);
        }
    });

    /* تبديل حالة استلام الإيصال/الفاتورة على العملية */
    const appUrl = document.body.getAttribute('data-app-url') || './';
    const csrf   = document.body.getAttribute('data-csrf') || '';
    document.querySelectorAll('[data-receipt-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            btn.disabled = true;
            fetch(appUrl + 'api/toggle_receipt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: 'id=' + encodeURIComponent(btn.getAttribute('data-tx-id')) + '&csrf_token=' + encodeURIComponent(csrf)
            })
                .then(function (r) {
                    if (!r.ok) { throw new Error('http_' + r.status); }
                    return r.json();
                })
                .then(function (data) {
                    if (!data.ok) { throw new Error(data.error || 'unknown'); }
                    const received = Number(data.receipt_status) === 1;
                    btn.classList.toggle('badge-receipt-received', received);
                    btn.classList.toggle('badge-receipt-missing', !received);
                    btn.querySelector('.receipt-icon').className = 'bi receipt-icon ' + (received ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill');
                    btn.querySelector('.receipt-label').textContent = received ? 'تم الاستلام' : 'لم يستلم';
                })
                .catch(function (err) {
                    console.error('toggle_receipt failed:', err);
                    alert('تعذّر تحديث حالة الاستلام. إذا استمرت المشكلة تأكد من ترقية قاعدة البيانات من صفحة "ترقية النظام"، أو أعد تحميل الصفحة.');
                })
                .finally(function () { btn.disabled = false; });
        });
    });
})();
