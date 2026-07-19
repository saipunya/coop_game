<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/workflow.php';

$user = current_user();
$member = current_member();
if (!$user && !$member) redirect_to('user-login.php');
if ($user && !user_can('payments', $user)) {
  http_response_code(403);
  exit('บัญชีนี้ไม่มีสิทธิ์พิมพ์ใบเสร็จและจ่ายเงิน');
}

ensure_workflow_schema();
$id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!$id) {
  http_response_code(404);
  exit('ไม่พบใบเสร็จ');
}

$stmt = db()->prepare('SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
  FROM tbl_rubber_workflow workflow
  LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
  WHERE workflow.workflow_id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$receipt = $stmt->fetch();

if (!$receipt || !in_array($receipt['workflow_status'], ['deducted', 'paid'], true)) {
  http_response_code(404);
  exit('ใบเสร็จยังไม่พร้อมใช้งาน');
}
if ($member && !$user && (int) $receipt['member_id'] !== (int) $member['mem_id'] && $receipt['member_number'] !== $member['mem_number']) {
  http_response_code(403);
  exit('ไม่มีสิทธิ์ดูใบเสร็จนี้');
}

if ($user) {
  audit_log('view_receipt', 'workflow', $id, 'เปิดใบเสร็จ ' . $receipt['receipt_no'] . ' ของสมาชิก ' . $receipt['member_number'], [
    'round_date' => $receipt['weigh_date'],
    'member_number' => $receipt['member_number'],
    'receipt_no' => $receipt['receipt_no'],
    'workflow_status' => $receipt['workflow_status'],
  ]);
}

$stmt = db()->prepare('SELECT deduction_label, deduction_amount FROM tbl_rubber_deduction WHERE workflow_id = :id ORDER BY deduction_id');
$stmt->execute(['id' => $id]);
$deductions = $stmt->fetchAll();

$isPaid = $receipt['workflow_status'] === 'paid' && !empty($receipt['paid_at']);

function receipt_thai_datetime(?string $value): string
{
  if (!$value) return '-';
  try {
    $date = new DateTimeImmutable($value);
    return $date->format('d/m/') . ((int) $date->format('Y') + 543) . $date->format(' H:i') . ' น.';
  } catch (Throwable $e) {
    return $value;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ใบรับเงิน <?php echo h($receipt['receipt_no']); ?></title>
  <link href="typography.css" rel="stylesheet">
  <style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; padding: 28px; font-family: "Sarabun", sans-serif; color: #17212f; background: #eef2ef; }
    .receipt { position: relative; isolation: isolate; overflow: hidden; width: min(780px, 100%); margin: auto; padding: 42px 48px; background: #fff; border: 1px solid #cfd8d2; box-shadow: 0 12px 34px rgba(0, 0, 0, .08); }
    .receipt > :not(.paid-watermark) { position: relative; z-index: 2; }
    .paid-watermark { position: absolute; inset: 0; z-index: 1; display: grid; place-items: center; pointer-events: none; }
    .paid-watermark span { transform: rotate(-28deg); padding: 13px 30px; border: 7px double rgba(185, 28, 28, .15); border-radius: 16px; color: rgba(185, 28, 28, .13); font-size: clamp(48px, 10vw, 82px); font-weight: 900; line-height: 1; letter-spacing: .08em; white-space: nowrap; }
    .head { display: flex; justify-content: space-between; gap: 24px; border-bottom: 3px solid #175c40; padding-bottom: 20px; }
    .head h1 { margin: 0; color: #175c40; font-size: 27px; }
    .head p { margin: 5px 0 0; color: #5f6e65; }
    .receipt-no { text-align: right; }
    .receipt-no strong { display: block; font-size: 18px; }
    .status { display: inline-block; margin-top: 7px; padding: 5px 10px; border-radius: 99px; color: #175c40; background: #e3f2e9; font-weight: 800; font-size: 12px; }
    .status.paid { color: #991b1b; background: #fee2e2; border: 1px solid #fecaca; }
    .info { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 32px; margin: 24px 0; }
    .info div { border-bottom: 1px dotted #aebbb3; padding: 8px 0; }
    .info span { color: #68766e; font-size: 12px; }
    .info strong { display: block; margin-top: 3px; }
    .paid-record { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 18px; padding: 12px 14px !important; border: 1px solid #fecaca !important; border-radius: 9px; background: rgba(254, 242, 242, .94); }
    .paid-record strong { color: #991b1b; }
    table { width: 100%; border-collapse: collapse; margin-top: 18px; }
    th, td { padding: 11px 12px; border: 1px solid #d7dfda; }
    th { background: #f3f7f4; text-align: left; font-size: 12px; }
    .num { text-align: right; }
    .totals { width: min(380px, 100%); margin: 20px 0 0 auto; }
    .totals div { display: flex; justify-content: space-between; padding: 8px 4px; }
    .totals .net { margin-top: 5px; padding: 13px 10px; border-top: 2px solid #175c40; color: #175c40; font-size: 20px; font-weight: 800; }
    .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; margin-top: 70px; text-align: center; }
    .signature-box { min-height: 90px; padding-top: 8px; border-top: 1px solid #333; }
    .signature-box strong { display: block; }
    .signature-name { display: block; margin-bottom: 4px; color: #17212f; font-weight: 700; }
    .signature-meta { display: block; margin-top: 4px; color: #68766e; font-size: 11px; line-height: 1.5; }
    .actions { width: min(780px, 100%); margin: 14px auto; text-align: right; }
    .actions button { padding: 10px 18px; border: 0; border-radius: 7px; color: #fff; background: #175c40; font: 700 14px "Sarabun"; cursor: pointer; }
    @media (max-width: 600px) {
      body { padding: 10px; }
      .receipt { padding: 25px 20px; }
      .head { display: block; }
      .receipt-no { margin-top: 14px; text-align: left; }
      .info, .paid-record { grid-template-columns: 1fr; }
      .signatures { gap: 24px; }
      .paid-watermark span { font-size: 44px; }
    }
    @media print {
      @page { margin: 10mm; }
      body { padding: 0; background: #fff; }
      .receipt { width: 100%; border: 0; box-shadow: none; }
      .actions { display: none; }
    }
  </style>
</head>
<body>
  <div class="actions"><button onclick="window.print()">พิมพ์ใบเสร็จ</button></div>
  <main class="receipt">
    <?php if ($isPaid): ?>
      <div class="paid-watermark" aria-hidden="true"><span>จ่ายเงินแล้ว</span></div>
    <?php endif; ?>

    <header class="head">
      <div>
        <h1><?php echo h(system_name()); ?></h1>
        <p><?php echo h(cooperative_name()); ?></p>
      </div>
      <div class="receipt-no">
        <span>ใบรับเงินค่ายาง</span>
        <strong><?php echo h($receipt['receipt_no']); ?></strong>
        <span class="status<?php echo $isPaid ? ' paid' : ''; ?>"><?php echo h(workflow_status_label($receipt['workflow_status'])); ?></span>
      </div>
    </header>

    <section class="info">
      <div><span>สมาชิก</span><strong><?php echo h($receipt['member_number'] . ' · ' . $receipt['member_name']); ?></strong></div>
      <div><span>กลุ่มสมาชิก</span><strong><?php echo h($receipt['member_group']); ?></strong></div>
      <div><span>วันชั่งยาง</span><strong><?php echo h($receipt['weigh_date']); ?></strong></div>
      <div><span>ลานยาง</span><strong><?php echo h($receipt['yard_name']); ?></strong></div>
      <div><span>จำนวนกระสอบ</span><strong><?php echo number_format((float) $receipt['total_bags'], 0); ?> กระสอบ</strong></div>
      <div><span>น้ำหนักจริง</span><strong><?php echo number_format((float) $receipt['actual_weight'], 2); ?> กิโลกรัม</strong></div>
      <?php if ($isPaid): ?>
        <div class="paid-record">
          <div><span>เจ้าหน้าที่ผู้กด “จ่ายเงินแล้ว”</span><strong><?php echo h($receipt['paid_by'] ?: '-'); ?></strong></div>
          <div><span>วัน–เวลาที่จ่ายเงิน</span><strong><?php echo h(receipt_thai_datetime($receipt['paid_at'])); ?></strong></div>
        </div>
      <?php endif; ?>
    </section>

    <table>
      <thead><tr><th>รายละเอียด</th><th class="num">จำนวน</th><th class="num">จำนวนเงิน (บาท)</th></tr></thead>
      <tbody>
        <tr>
          <td>ค่ายาง ราคากิโลกรัมละ <?php echo number_format((float) $receipt['price_per_kg'], 2); ?> บาท</td>
          <td class="num"><?php echo number_format((float) $receipt['actual_weight'], 2); ?> kg</td>
          <td class="num"><?php echo number_format((float) $receipt['gross_amount'], 2); ?></td>
        </tr>
        <?php foreach ($deductions as $item): ?>
          <tr><td>หัก: <?php echo h($item['deduction_label']); ?></td><td></td><td class="num">-<?php echo number_format((float) $item['deduction_amount'], 2); ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <section class="totals">
      <div><span>ยอดค่ายาง</span><strong><?php echo number_format((float) $receipt['gross_amount'], 2); ?> บาท</strong></div>
      <div><span>ยอดหักรวม</span><strong><?php echo number_format((float) $receipt['total_deduction'], 2); ?> บาท</strong></div>
      <div class="net"><span>ยอดรับสุทธิ</span><strong><?php echo number_format((float) $receipt['net_amount'], 2); ?> บาท</strong></div>
    </section>

    <section class="signatures">
      <div class="signature-box"><strong>ผู้รับเงิน / สมาชิก</strong></div>
      <div class="signature-box">
        <?php if ($isPaid): ?>
          <span class="signature-name"><?php echo h($receipt['paid_by'] ?: '-'); ?></span>
          <strong>เจ้าหน้าที่ผู้จ่ายเงิน</strong>
          <span class="signature-meta">ผู้กด “จ่ายเงินแล้ว”<br><?php echo h(receipt_thai_datetime($receipt['paid_at'])); ?></span>
        <?php else: ?>
          <strong>ผู้จ่ายเงิน / เจ้าหน้าที่</strong>
        <?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
