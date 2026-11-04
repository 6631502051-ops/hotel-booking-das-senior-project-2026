<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['id_account'])) {
    $_SESSION['error'] = 'กรุณาเข้าสู่ระบบ!';
    header('location: signin.php');
    exit();
}

$ban_check_stmt = $conn->prepare("SELECT ban_account FROM account WHERE id_account = :user_id");
$ban_check_stmt->bindParam(':user_id', $_SESSION['id_account'], PDO::PARAM_INT);
$ban_check_stmt->execute();
$ban_check_row = $ban_check_stmt->fetch(PDO::FETCH_ASSOC);

if (($ban_check_row['ban_account'] ?? 0) == 1) {
    $_SESSION['error'] = 'บัญชีนี้ถูกแบน';
    header('location: signin.php');
    exit();
}

$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : (int)($_POST['booking_id'] ?? 0);
$user_id = (int)$_SESSION['id_account'];

if ($booking_id <= 0) {
    $_SESSION['error'] = 'ไม่พบข้อมูลการจอง';
    header('location: user.php');
    exit();
}

$booking_stmt = $conn->prepare("SELECT
        b.booking_id,
        b.user_id,
        b.member,
        b.check_in,
        b.check_out,
        r.room_number,
        a.firstname,
        a.lastname
    FROM booking_info b
    LEFT JOIN room r ON b.room_count = r.room_id
    LEFT JOIN account a ON b.user_id = a.id_account
    WHERE b.booking_id = :booking_id AND b.user_id = :user_id");
$booking_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
$booking_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$booking_stmt->execute();
$booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    $_SESSION['error'] = 'ไม่พบข้อมูลการจองของคุณ';
    header('location: user.php');
    exit();
}

$checkin_date = new DateTime($booking['check_in']);
$checkout_date = new DateTime($booking['check_out']);
$nights = max(1, (int)$checkin_date->diff($checkout_date)->days);
$room_price_per_night = 10;
$total_amount = $nights * $room_price_per_night;

function emvQrCrc(string $payload): string
{
    $crc = 0xFFFF;
    $length = strlen($payload);

    for ($i = 0; $i < $length; $i++) {
        $crc ^= ord($payload[$i]) << 8;

        for ($j = 0; $j < 8; $j++) {
            if (($crc & 0x8000) !== 0) {
                $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFF;
            }
        }
    }

    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

function buildQrPayload(float $amount): string
{
    $amountText = number_format($amount, 2, '.', '');
    $payloadWithoutCrc = '00020101021129370016A00000067701011101130066944562017530376454'
        . str_pad((string)strlen($amountText), 2, '0', STR_PAD_LEFT)
        . $amountText
        . '5802TH6304';

    return $payloadWithoutCrc . emvQrCrc($payloadWithoutCrc);
}

$qr_payload = buildQrPayload((float)$total_amount);
$qr_code_url = 'https://quickchart.io/qr?size=320&margin=2&text=' . urlencode($qr_payload);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_now'])) {
    $_SESSION['success'] = 'ชำระเงินเรียบร้อยแล้ว';
    header('location: user.php');
    exit();
}

$user_fullname = trim(($booking['firstname'] ?? '') . ' ' . ($booking['lastname'] ?? ''));
if ($user_fullname === '') {
    $user_fullname = '-';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Kanit', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.16), transparent 30%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.14), transparent 25%),
                linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
            min-height: 100vh;
        }

        .payment-shell {
            max-width: 980px;
            margin: 40px auto;
            padding: 0 16px;
        }

        .payment-hero {
            background: linear-gradient(135deg, #0f172a, #1d4ed8);
            color: #fff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.2);
            margin-bottom: 20px;
        }

        .payment-hero h1 {
            margin: 0 0 8px;
            font-weight: 700;
        }

        .payment-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }

        .payment-summary {
            padding: 24px;
            border-right: 1px solid #e5e7eb;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }

        .payment-summary .label {
            color: #64748b;
            font-size: 14px;
        }

        .payment-summary .value {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
        }

        .price-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 18px;
            padding: 18px;
            margin-top: 18px;
        }

        .price-box .amount {
            font-size: 34px;
            font-weight: 700;
            color: #1d4ed8;
            line-height: 1;
        }

        .payment-panel {
            padding: 24px;
        }

        .qr-frame {
            width: 320px;
            max-width: 100%;
            padding: 18px;
            border-radius: 20px;
            background: #fff;
            border: 1px solid #dbe4f0;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
        }

        .method-card {
            border: 1px solid #dbe4f0;
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .method-card small {
            color: #64748b;
        }

        .btn-pay {
            width: 100%;
            padding: 14px;
            border-radius: 14px;
            font-weight: 600;
        }

        .back-link {
            color: #dbeafe;
            text-decoration: none;
        }

        .back-link:hover {
            color: #fff;
        }

        @media (max-width: 768px) {
            .payment-summary {
                border-right: 0;
                border-bottom: 1px solid #e5e7eb;
            }
        }
    </style>
</head>
<body>
    <div class="payment-shell">
        <div class="payment-hero d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1>ชำระเงินการจอง</h1>
                <div>หลังจากยืนยันการจองแล้ว กรุณาชำระเงินเพื่อดำเนินการต่อ</div>
            </div>
            <a href="user.php" class="back-link">กลับหน้าจอง</a>
        </div>

        <div class="payment-card row g-0">
            <div class="col-lg-6 payment-summary">
                <h4 class="mb-3">รายละเอียดการจอง</h4>
                <div class="mb-3">
                    <div class="label">ชื่อผู้จอง</div>
                    <div class="value"><?php echo htmlspecialchars($user_fullname); ?></div>
                </div>
                <div class="mb-3">
                    <div class="label">ห้อง</div>
                    <div class="value">ห้อง <?php echo htmlspecialchars($booking['room_number'] ?? '-'); ?></div>
                </div>
                <div class="mb-3">
                    <div class="label">วันที่เข้าพัก</div>
                    <div class="value"><?php echo htmlspecialchars($booking['check_in']); ?> ถึง <?php echo htmlspecialchars($booking['check_out']); ?></div>
                </div>
                <div class="mb-3">
                    <div class="label">จำนวนผู้เข้าพัก</div>
                    <div class="value"><?php echo htmlspecialchars($booking['member']); ?> คน</div>
                </div>
                <div class="price-box">
                    <div class="label mb-1">ยอดชำระ</div>
                    <div class="amount">฿<?php echo number_format($total_amount); ?></div>
                    <div class="small text-muted mt-2">คำนวณจาก <?php echo $nights; ?> คืน x ฿<?php echo number_format($room_price_per_night); ?>/คืน</div>
                </div>
            </div>

            <div class="col-lg-6 payment-panel">
                <h4 class="mb-3">สแกน QR Code ชำระเงิน</h4>
                <form method="POST" action="payment.php">
                    <input type="hidden" name="booking_id" value="<?php echo htmlspecialchars($booking_id); ?>">

                    <div class="text-center mb-4">
                        <div class="qr-frame mx-auto">
                            <img src="<?php echo htmlspecialchars($qr_code_url); ?>" alt="QR Code สำหรับชำระเงิน" class="img-fluid">
                        </div>
                        <div class="small text-muted mt-3">สแกน QR Code เพื่อชำระเงินจำนวน ฿<?php echo number_format($total_amount); ?></div>
                    </div>

                    <div class="alert alert-warning mt-4">
                        กรุณาสแกน QR Code เพื่อชำระเงิน หลังชำระแล้วกดยืนยันเพื่อกลับหน้าจอง
                    </div>

                    <button type="submit" name="pay_now" class="btn btn-primary btn-pay">ยืนยันการชำระเงิน</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
