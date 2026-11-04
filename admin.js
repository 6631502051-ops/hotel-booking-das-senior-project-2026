document.addEventListener('DOMContentLoaded', function () {
    /* ---------------------------------------------------------
     * ยืนยันก่อนลบ/แบน-ปลดแบน (แทนที่ inline onclick="return confirm(...)")
     * ใช้ attribute data-confirm="ข้อความ" บนลิงก์ที่ต้องการยืนยัน
     * --------------------------------------------------------- */
    document.querySelectorAll('a[data-confirm]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            var message = link.getAttribute('data-confirm');
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('form[data-confirm-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm-form');
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    /* ---------------------------------------------------------
     * ปุ่มเปิด/ปิด sidebar บนหน้าจอขนาดเล็ก
     * --------------------------------------------------------- */
    var sidebarToggle = document.getElementById('sidebarToggle');
    var sidebar = document.querySelector('.sidebar');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function () {
            sidebar.classList.toggle('show');
        });

        document.addEventListener('click', function (event) {
            var isClickInsideSidebar = sidebar.contains(event.target);
            var isClickOnToggle = sidebarToggle.contains(event.target);

            if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
    }

    initPaymentQueue();
});

/* ---------------------------------------------------------
 * คำขอชำระเงินรอยืนยัน
 * - ดึงรายการจาก api/admin-payments.php ทุก 5 วินาที
 * - ปุ่มยืนยัน/ปฏิเสธส่งไปที่ api/admin-payment-decision.php
 * --------------------------------------------------------- */
function initPaymentQueue() {
    var body = document.getElementById('pendingPaymentsBody');
    if (!body) return;

    var countBadge = document.getElementById('pendingCount');
    var navBadge = document.getElementById('pendingNavCount');
    var alertBox = document.getElementById('paymentAlert');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var busy = false;

    function text(value) {
        var span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);
        return span.innerHTML;
    }

    function showAlert(message, ok) {
        alertBox.textContent = message;
        alertBox.className = 'alert mb-3 ' + (ok ? 'alert-success' : 'alert-danger');
        alertBox.hidden = false;
    }

    function render(items) {
        countBadge.textContent = items.length;
        navBadge.textContent = items.length;
        navBadge.hidden = items.length === 0;

        if (items.length === 0) {
            body.innerHTML = '<tr><td colspan="7" class="text-muted">ไม่มีคำขอที่รอยืนยัน</td></tr>';
            return;
        }

        body.innerHTML = items.map(function (item) {
            return '<tr>' +
                '<td class="text-muted">#' + text(item.booking_id) + '</td>' +
                '<td>' + text(item.guest) + '<div class="small text-muted">' + text(item.email) + '</div></td>' +
                '<td><span class="badge badge-soft-primary">ห้อง ' + text(item.room) + '</span></td>' +
                '<td>' + text(item.check_in) + ' → ' + text(item.check_out) + '</td>' +
                '<td>฿' + text(Number(item.amount).toLocaleString('th-TH')) + '</td>' +
                '<td>' + text(item.requested_at) + '</td>' +
                '<td class="text-end text-nowrap">' +
                    '<button type="button" class="btn btn-sm-icon btn-success" data-payment="' + text(item.payment_id) + '" data-decision="approve"><i class="bi bi-check-lg"></i> ยืนยัน</button> ' +
                    '<button type="button" class="btn btn-sm-icon btn-danger" data-payment="' + text(item.payment_id) + '" data-decision="reject"><i class="bi bi-x-lg"></i> ปฏิเสธ</button>' +
                '</td>' +
            '</tr>';
        }).join('');
    }

    async function load() {
        if (busy) return;
        try {
            var response = await fetch('api/admin-payments.php', { credentials: 'same-origin', cache: 'no-store' });
            var result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'โหลดรายการไม่สำเร็จ');
            render(result.items);
        } catch (error) {
            showAlert(error.message, false);
        }
    }

    body.addEventListener('click', async function (event) {
        var button = event.target.closest('button[data-decision]');
        if (!button) return;

        var approve = button.dataset.decision === 'approve';
        var note = '';
        if (approve) {
            if (!window.confirm('ยืนยันว่าได้รับเงินแล้ว และอนุมัติการจองนี้?')) return;
        } else {
            note = window.prompt('เหตุผลที่ปฏิเสธ (ไม่บังคับ)', '');
            if (note === null) return;
        }

        busy = true;
        body.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        try {
            var response = await fetch('api/admin-payment-decision.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    payment_id: Number(button.dataset.payment),
                    decision: button.dataset.decision,
                    note: note
                })
            });
            var result = await response.json();
            showAlert(result.message || (response.ok ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ'), response.ok && result.ok);
        } catch (error) {
            showAlert('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ', false);
        } finally {
            busy = false;
            load();
        }
    });

    load();
    setInterval(load, 5000);
}
