// Sends the simulated payment to the server as an API request.
// The booking then waits on the admin page until an admin approves it.
(function () {
  var form = document.getElementById('paymentForm');
  if (!form) return;

  var button = document.getElementById('payButton');
  var statusText = document.getElementById('paymentStatus');
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    button.disabled = true;
    button.textContent = 'กำลังส่งคำขอ...';

    try {
      var response = await fetch('api/payment-request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        credentials: 'same-origin',
        body: JSON.stringify({ booking_id: Number(form.dataset.bookingId) })
      });
      var result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.message || 'ส่งคำขอไม่สำเร็จ');
      }
      window.location.href = 'dashboard.php';
    } catch (error) {
      statusText.textContent = error.message || 'เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ กรุณาลองใหม่';
      button.disabled = false;
      button.textContent = 'ยืนยันชำระเงิน';
    }
  });
})();
