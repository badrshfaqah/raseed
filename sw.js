/* رصيد - عامل الخدمة (Service Worker) لتطبيق الجوال PWA
 *
 * مبسّط عمداً: لا يخزّن صفحات PHP الديناميكية حتى لا تُعرض بيانات قديمة.
 * يمرّر الطلبات للشبكة مباشرة، ويعرض رسالة بسيطة فقط إذا انقطع الاتصال
 * أثناء التنقّل. وجوده يجعل التطبيق قابلاً للتثبيت على الشاشة الرئيسية.
 */
const OFFLINE_HTML =
    '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width, initial-scale=1">' +
    '<title>لا يوجد اتصال</title><style>body{font-family:Tahoma,sans-serif;text-align:center;' +
    'padding:60px 20px;color:#334155}h3{color:#0f766e}</style></head><body>' +
    '<h3>لا يوجد اتصال بالإنترنت</h3><p>تعذّر تحميل الصفحة. تحقّق من الاتصال وحاول مجدداً.</p>' +
    '<p><button onclick="location.reload()" style="padding:10px 22px;border:0;border-radius:8px;' +
    'background:#0f766e;color:#fff;font:inherit;cursor:pointer">إعادة المحاولة</button></p></body></html>';

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    const req = event.request;
    // نتعامل فقط مع طلبات التنقّل (فتح الصفحات) لتوفير رسالة انقطاع لطيفة
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(function () {
                return new Response(OFFLINE_HTML, {
                    headers: { 'Content-Type': 'text/html; charset=utf-8' },
                });
            })
        );
        return;
    }
    // بقية الطلبات (أصول، API) تمرّ للشبكة كما هي
    event.respondWith(fetch(req).catch(function () { return Response.error(); }));
});
