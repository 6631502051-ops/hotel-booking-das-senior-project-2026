<?php
/*
 * Payment simulation + admin approval.
 *
 * Flow: booking_db.php saves a booking as 'pending_payment'
 *   -> guest presses "ยืนยันชำระเงิน" (submit_payment_request) -> 'awaiting_approval'
 *   -> admin approves  (decide_payment) -> booking 'active',   payment 'approved'
 *   -> admin rejects   (decide_payment) -> booking 'rejected', payment 'rejected'
 * No real money moves: the PromptPay QR is shown, and the admin's approval stands
 * in for checking that the transfer arrived.
 */

if (!function_exists('submit_payment_request')) {
    function submit_payment_request(PDO $conn, int $bookingId, int $userId): array
    {
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("SELECT booking_id, check_in, check_out, booking_status
                FROM booking_info WHERE booking_id = :booking_id AND user_id = :user_id FOR UPDATE");
            $stmt->execute(['booking_id' => $bookingId, 'user_id' => $userId]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                $conn->rollBack();
                return ['ok' => false, 'status' => 404, 'message' => 'ไม่พบข้อมูลการจองของคุณ'];
            }
            if ($booking['booking_status'] !== 'pending_payment') {
                $conn->rollBack();
                return ['ok' => false, 'status' => 409, 'message' => 'การจองนี้ส่งคำขอชำระเงินไปแล้ว หรือไม่อยู่ในสถานะรอชำระเงิน'];
            }

            $amount = booking_amount($booking['check_in'], $booking['check_out'])['total'];
            $conn->prepare('INSERT INTO payment (booking_id, amount) VALUES (:booking_id, :amount)')
                ->execute(['booking_id' => $bookingId, 'amount' => $amount]);
            $paymentId = (int)$conn->lastInsertId();

            $conn->prepare("UPDATE booking_info SET booking_status = 'awaiting_approval' WHERE booking_id = :booking_id")
                ->execute(['booking_id' => $bookingId]);

            $conn->commit();
            return [
                'ok' => true,
                'status' => 200,
                'payment_id' => $paymentId,
                'booking_status' => 'awaiting_approval',
                'message' => 'ส่งคำขอชำระเงินแล้ว กรุณารอแอดมินยืนยัน',
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('submit_payment_request: ' . $e->getMessage());
            return ['ok' => false, 'status' => 500, 'message' => 'ไม่สามารถส่งคำขอชำระเงินได้'];
        }
    }
}

if (!function_exists('list_pending_payments')) {
    function list_pending_payments(PDO $conn): array
    {
        $stmt = $conn->query("SELECT
                p.payment_id, p.booking_id, p.amount, p.method, p.requested_at,
                b.check_in, b.check_out, b.member,
                r.room_number,
                a.firstname, a.lastname, a.email_account
            FROM payment p
            JOIN booking_info b ON b.booking_id = p.booking_id
            LEFT JOIN room r ON r.room_id = b.room_count
            LEFT JOIN account a ON a.id_account = b.user_id
            WHERE p.payment_status = 'pending' AND b.booking_status = 'awaiting_approval'
            ORDER BY p.requested_at ASC, p.payment_id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('refresh_room_status')) {
    /* Same rule as updateRoomStatus() in admin.php: room_status = 1 while the room has a confirmed booking. */
    function refresh_room_status(PDO $conn, int $roomId): void
    {
        $count = $conn->prepare("SELECT COUNT(*) FROM booking_info WHERE room_count = :room_id AND booking_status = 'active'");
        $count->execute(['room_id' => $roomId]);
        $conn->prepare('UPDATE room SET room_status = :room_status WHERE room_id = :room_id')
            ->execute(['room_status' => ((int)$count->fetchColumn() > 0) ? 1 : 0, 'room_id' => $roomId]);
    }
}

if (!function_exists('decide_payment')) {
    function decide_payment(PDO $conn, int $paymentId, string $decision, int $adminId, string $note = ''): array
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['ok' => false, 'status' => 422, 'message' => 'decision ต้องเป็น approve หรือ reject'];
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("SELECT p.payment_id, p.booking_id, p.payment_status, b.booking_status, b.room_count
                FROM payment p JOIN booking_info b ON b.booking_id = p.booking_id
                WHERE p.payment_id = :payment_id FOR UPDATE");
            $stmt->execute(['payment_id' => $paymentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $conn->rollBack();
                return ['ok' => false, 'status' => 404, 'message' => 'ไม่พบคำขอชำระเงิน'];
            }
            if ($row['payment_status'] !== 'pending' || $row['booking_status'] !== 'awaiting_approval') {
                $conn->rollBack();
                return ['ok' => false, 'status' => 409, 'message' => 'คำขอนี้ถูกตัดสินไปแล้ว'];
            }

            $approved = $decision === 'approve';
            $conn->prepare('UPDATE payment SET payment_status = :status, reviewed_by = :admin_id,
                    reviewed_at = NOW(), review_note = :note WHERE payment_id = :payment_id')
                ->execute([
                    'status' => $approved ? 'approved' : 'rejected',
                    'admin_id' => $adminId,
                    'note' => mb_substr($note, 0, 255) ?: null,
                    'payment_id' => $paymentId,
                ]);
            $conn->prepare('UPDATE booking_info SET booking_status = :status WHERE booking_id = :booking_id')
                ->execute(['status' => $approved ? 'active' : 'rejected', 'booking_id' => $row['booking_id']]);

            refresh_room_status($conn, (int)$row['room_count']);
            $conn->commit();

            return [
                'ok' => true,
                'status' => 200,
                'booking_id' => (int)$row['booking_id'],
                'booking_status' => $approved ? 'active' : 'rejected',
                'message' => $approved ? 'ยืนยันการชำระเงินแล้ว การจองสำเร็จ' : 'ปฏิเสธการชำระเงินแล้ว',
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('decide_payment: ' . $e->getMessage());
            return ['ok' => false, 'status' => 500, 'message' => 'ไม่สามารถบันทึกผลการตรวจสอบได้'];
        }
    }
}
