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
})();
