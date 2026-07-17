<?php
require_once __DIR__ . '/auth.php';
require_user();
require_once __DIR__ . '/navbar.php';

$user = current_user();
$error = '';
$flash = $_SESSION['rubber_flash'] ?? null;
unset($_SESSION['rubber_flash']);

function rubber_redirect($params = '')
{
  header('Location: ' . url_for('rubbers.php') . ($params ? '?' . ltrim($params, '?') : ''));
  exit;
}

function rubber_number($value)
{
  return number_format((float) $value, 2);
}

function rubber_thai_date($date)
{
  if (!$date) return '—';
  $months = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
  $time = strtotime($date);
  return (int) date('j', $time) . ' ' . $months[(int) date('n', $time)] . ' ' . ((int) date('Y', $time) + 543);
}

function rubber_decimal($name)
{
  $value = trim($_POST[$name] ?? '0');
  if ($value === '') return 0.0;
  if (!is_numeric($value) || (float) $value < 0) {
    throw new RuntimeException('จำนวนเงินและปริมาณต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป');
  }
  return round((float) $value, 2);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่อีกครั้ง');
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'delete') {
      if (!$id) throw new RuntimeException('ไม่พบรายการที่ต้องการลบ');
      $stmt = db()->prepare('DELETE FROM tbl_rubber WHERE ru_id = :id');
      $stmt->execute(['id' => $id]);
      $_SESSION['rubber_flash'] = ['type' => 'success', 'message' => 'ลบรายการรวบรวมยางเรียบร้อยแล้ว'];
      rubber_redirect();
    }

    if (!in_array($action, ['create', 'update'], true)) throw new RuntimeException('คำสั่งไม่ถูกต้อง');
    $date = trim($_POST['date'] ?? '');
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    $lan = trim($_POST['lan'] ?? '');
    $group = trim($_POST['group'] ?? '');
    $number = trim($_POST['number'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $class = ($_POST['class'] ?? '') === 'farmer' ? 'farmer' : 'member';
    $quantity = rubber_decimal('quantity');
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) throw new RuntimeException('กรุณาระบุวันที่ให้ถูกต้อง');
    if (!in_array($lan, ['1', '2', '3', '4'], true)) throw new RuntimeException('กรุณาเลือกลานรับยาง');
    if ($fullname === '' || mb_strlen($fullname) > 255) throw new RuntimeException('กรุณาระบุชื่อ-สกุล');
    if ($quantity <= 0) throw new RuntimeException('ปริมาณยางต้องมากกว่า 0');

    $deductions = [];
    foreach (['hoon', 'loan', 'shortdebt', 'deposit', 'tradeloan', 'insurance'] as $field) $deductions[$field] = rubber_decimal($field);
    $priceStmt = db()->prepare('SELECT pr_price FROM tbl_price WHERE pr_date <= :date ORDER BY pr_date DESC, pr_id DESC LIMIT 1');
    $priceStmt->execute(['date' => $date]);
    $price = (float) ($priceStmt->fetchColumn() ?: 0);
    if ($price <= 0) throw new RuntimeException('ไม่พบราคาอ้างอิงสำหรับวันที่นี้ กรุณาบันทึกราคาก่อน');
    $value = round($price * $quantity, 2);
    $expend = round(array_sum($deductions), 2);
    $net = round($value - $expend, 2);

    $data = ['date'=>$date, 'lan'=>$lan, 'group'=>$group, 'number'=>$number, 'fullname'=>$fullname, 'class'=>$class, 'quantity'=>$quantity, 'value'=>$value, 'hoon'=>$deductions['hoon'], 'loan'=>$deductions['loan'], 'shortdebt'=>$deductions['shortdebt'], 'deposit'=>$deductions['deposit'], 'tradeloan'=>$deductions['tradeloan'], 'insurance'=>$deductions['insurance'], 'expend'=>$expend, 'netvalue'=>$net, 'saveby'=>$user['user_fullname'], 'savedate'=>date('Y-m-d')];
    if ($action === 'create') {
      $sql = 'INSERT INTO tbl_rubber (ru_date,ru_lan,ru_group,ru_number,ru_fullname,ru_class,ru_quantity,ru_value,ru_hoon,ru_loan,ru_shortdebt,ru_deposit,ru_tradeloan,ru_insurance,ru_expend,ru_netvalue,ru_saveby,ru_savedate) VALUES (:date,:lan,:group,:number,:fullname,:class,:quantity,:value,:hoon,:loan,:shortdebt,:deposit,:tradeloan,:insurance,:expend,:netvalue,:saveby,:savedate)';
      $message = 'เพิ่มรายการรวบรวมยางเรียบร้อยแล้ว';
    } else {
      if (!$id) throw new RuntimeException('ไม่พบรายการที่ต้องการแก้ไข');
      $data['id'] = $id;
      $sql = 'UPDATE tbl_rubber SET ru_date=:date,ru_lan=:lan,ru_group=:group,ru_number=:number,ru_fullname=:fullname,ru_class=:class,ru_quantity=:quantity,ru_value=:value,ru_hoon=:hoon,ru_loan=:loan,ru_shortdebt=:shortdebt,ru_deposit=:deposit,ru_tradeloan=:tradeloan,ru_insurance=:insurance,ru_expend=:expend,ru_netvalue=:netvalue,ru_saveby=:saveby,ru_savedate=:savedate WHERE ru_id=:id';
      $message = 'แก้ไขรายการรวบรวมยางเรียบร้อยแล้ว';
    }
    db()->prepare($sql)->execute($data);
    $_SESSION['rubber_flash'] = ['type' => 'success', 'message' => $message];
    rubber_redirect('lan=' . urlencode($lan));
  } catch (Throwable $e) {
    error_log('Rubber CRUD failed: ' . $e->getMessage());
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

$lan = in_array($_GET['lan'] ?? '', ['1','2','3','4'], true) ? $_GET['lan'] : '';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$rows = $members = [];
$total = 0;
$latestPrice = 0;
$editRow = null;
$latestRoundDate = null;
$roundSummary = ['records' => 0, 'quantity' => 0, 'value' => 0, 'expend' => 0, 'netvalue' => 0];

try {
  $latestPrice = (float) (db()->query('SELECT pr_price FROM tbl_price ORDER BY pr_date DESC, pr_id DESC LIMIT 1')->fetchColumn() ?: 0);
  $latestRoundDate = db()->query('SELECT MAX(ru_date) FROM tbl_rubber')->fetchColumn() ?: null;
  if ($latestRoundDate) {
    $stmt = db()->prepare('SELECT COUNT(*) AS records, COALESCE(SUM(ru_quantity),0) AS quantity, COALESCE(SUM(ru_value),0) AS value, COALESCE(SUM(ru_expend),0) AS expend, COALESCE(SUM(ru_netvalue),0) AS netvalue FROM tbl_rubber WHERE ru_date=:date');
    $stmt->execute(['date' => $latestRoundDate]);
    $roundSummary = array_merge($roundSummary, $stmt->fetch() ?: []);
  }
  $members = db()->query('SELECT mem_group,mem_number,mem_fullname,mem_class FROM tbl_member ORDER BY mem_fullname LIMIT 3000')->fetchAll();
  if ($lan !== '' && isset($_GET['edit']) && ($editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT))) {
    $stmt = db()->prepare('SELECT * FROM tbl_rubber WHERE ru_id=:id'); $stmt->execute(['id'=>$editId]); $editRow = $stmt->fetch() ?: null;
  }
  if (isset($_GET['receipt']) && ($receiptId = filter_var($_GET['receipt'], FILTER_VALIDATE_INT))) {
    $stmt = db()->prepare('SELECT * FROM tbl_rubber WHERE ru_id=:id'); $stmt->execute(['id'=>$receiptId]); $receipt = $stmt->fetch();
    if ($receipt) {
      $payload = [
        'id'=>(int)$receipt['ru_id'], 'thai_date'=>rubber_thai_date($receipt['ru_date']),
        'fullname'=>$receipt['ru_fullname'], 'class_label'=>$receipt['ru_class']==='member'?'สมาชิก':'เกษตรกร',
        'lan'=>$receipt['ru_lan'], 'quantity'=>(float)$receipt['ru_quantity'],
        'price'=>(float)$receipt['ru_quantity'] > 0 ? (float)$receipt['ru_value']/(float)$receipt['ru_quantity'] : 0,
        'value'=>(float)$receipt['ru_value'], 'hoon'=>(float)$receipt['ru_hoon'], 'loan'=>(float)$receipt['ru_loan'],
        'shortdebt'=>(float)$receipt['ru_shortdebt'], 'deposit'=>(float)$receipt['ru_deposit'],
        'tradeloan'=>(float)$receipt['ru_tradeloan'], 'insurance'=>(float)$receipt['ru_insurance'],
        'expend'=>(float)$receipt['ru_expend'], 'netvalue'=>(float)$receipt['ru_netvalue'],
        'saveby'=>$receipt['ru_saveby'], 'savedate'=>rubber_thai_date($receipt['ru_savedate']),
      ];
      $jsonFile = tempnam(sys_get_temp_dir(), 'rubber_receipt_');
      $pdfFile = $jsonFile . '.pdf';
      file_put_contents($jsonFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
      $command = escapeshellarg(PHP_BINARY === '' ? 'python3' : dirname(PHP_BINARY) . '/python3');
      if (!is_executable(trim($command, "'"))) $command = 'python3';
      $command .= ' ' . escapeshellarg(__DIR__ . '/scripts/generate_receipt_pdf.py') . ' ' . escapeshellarg($jsonFile) . ' ' . escapeshellarg($pdfFile) . ' 2>&1';
      exec($command, $pdfOutput, $pdfStatus);
      @unlink($jsonFile);
      if ($pdfStatus !== 0 || !is_file($pdfFile)) {
        error_log('Receipt PDF failed: ' . implode("\n", $pdfOutput));
        throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้ กรุณาตรวจสอบ Python, ReportLab และฟอนต์ภาษาไทย');
      }
      header('Content-Type: application/pdf');
      header('Content-Disposition: attachment; filename="rubber-receipt-' . (int)$receipt['ru_id'] . '.pdf"');
      header('Content-Length: ' . filesize($pdfFile));
      readfile($pdfFile);
      @unlink($pdfFile);
      exit;
    }
  }
  $where=[]; $params=[];
  if ($lan !== '') {
    $where[]='ru_lan=:lan'; $params['lan']=$lan;
  } elseif ($latestRoundDate) {
    $where[]='ru_date=:latest_date'; $params['latest_date']=$latestRoundDate;
  }
  if ($search !== '') { $where[]='(ru_fullname LIKE :q OR ru_number LIKE :q OR ru_group LIKE :q)'; $params['q']='%'.$search.'%'; }
  $whereSql = $where ? ' WHERE '.implode(' AND ',$where) : '';
  $stmt=db()->prepare('SELECT COUNT(*) FROM tbl_rubber'.$whereSql); $stmt->execute($params); $total=(int)$stmt->fetchColumn();
  $offset=($page-1)*$perPage;
  $stmt=db()->prepare('SELECT * FROM tbl_rubber'.$whereSql.' ORDER BY ru_date DESC,ru_id DESC LIMIT '.$perPage.' OFFSET '.$offset); $stmt->execute($params); $rows=$stmt->fetchAll();
  if (($_GET['export'] ?? '') === 'csv') {
    $stmt=db()->prepare('SELECT * FROM tbl_rubber'.$whereSql.' ORDER BY ru_date DESC,ru_id DESC'); $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="rubbers.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['ID','วันที่','ลาน','กลุ่ม','เลขที่','ชื่อ-สกุล','ประเภท','ปริมาณ','หุ้น','เงินกู้','หนี้สั้น','เงินฝาก','ลูกหนี้การค้า','ประกันภัย','ยอดสุทธิ']);
    while($r=$stmt->fetch()) fputcsv($out,[$r['ru_id'],$r['ru_date'],$r['ru_lan'],$r['ru_group'],$r['ru_number'],$r['ru_fullname'],$r['ru_class'],$r['ru_quantity'],$r['ru_hoon'],$r['ru_loan'],$r['ru_shortdebt'],$r['ru_deposit'],$r['ru_tradeloan'],$r['ru_insurance'],$r['ru_netvalue']]);
    fclose($out); exit;
  }
} catch (Throwable $e) { error_log('Rubber list failed: '.$e->getMessage()); $error=$error ?: db_friendly_error($e); }

$pages=max(1,(int)ceil($total/$perPage));
$form=$editRow ?: ['ru_date'=>date('Y-m-d'),'ru_lan'=>$lan ?: '1','ru_group'=>'','ru_number'=>'','ru_fullname'=>'','ru_class'=>'member','ru_quantity'=>'','ru_hoon'=>'0','ru_loan'=>'0','ru_shortdebt'=>'0','ru_deposit'=>'0','ru_tradeloan'=>'0','ru_insurance'=>'0'];
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>จัดการข้อมูลยางพารา</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
<style>:root{--green:#168b58;--dark:#0d4d34;--pale:#dcefe5;--line:#dfe7e2;--ink:#202824}body{font-family:"Sarabun",sans-serif;background:#f4f6f7;color:var(--ink)}.content{min-width:0;padding:30px}.shell{max-width:1500px;margin:auto}.hero{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:28px 34px;border:1px solid #9fceb6;border-radius:24px;background:#d7ebe2}.hero h1{margin:0;font-size:30px;font-weight:800;color:#103d2c}.hero p{margin:6px 0 0;color:#55806b}.hero-stats{display:flex;gap:12px;flex-wrap:wrap}.hero-pill{padding:14px 20px;border:1px solid #9ac9b0;border-radius:999px;background:#fff;font-weight:800;color:#174e36}.yard-filter{display:flex;align-items:center;gap:10px;margin:24px 0 12px;padding:12px 16px;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 4px 16px rgba(20,55,38,.05);overflow-x:auto}.yard-filter a{flex:0 0 auto;padding:9px 22px;border:1px solid #9fcfb7;border-radius:999px;color:#184f37;background:#dff0e8;font-weight:800}.yard-filter a.active{color:#fff;background:var(--green);box-shadow:0 5px 14px rgba(22,139,88,.25)}.round-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:18px}.round-card{padding:20px;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(20,55,38,.05)}.round-card span{display:block;color:#718078;font-size:13px}.round-card strong{display:block;margin-top:9px;color:#174e36;font-size:25px}.panel{margin-top:18px;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 7px 25px rgba(20,55,38,.06);overflow:hidden}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 24px;border-bottom:1px solid var(--line)}.panel-head h2{margin:0;font-size:21px;font-weight:800}.form-body{padding:24px}.section-title{margin:10px 0 18px;font-size:24px}.form-label{color:#35624c;font-weight:700}.form-control,.form-select{min-height:46px;border-color:#d6e1da;border-radius:11px}.deduct-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px 22px}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:22px;padding:20px;border:1px solid #70d7e8;border-radius:12px;background:#cdf4fa}.summary-box{padding:17px;border:1px solid #a9cfba;border-radius:18px;background:#fff;color:#6b7e74}.summary-box strong{display:block;margin-top:5px;color:#0e5c70;font-size:19px}.form-footer{display:flex;justify-content:space-between;align-items:center;padding:14px 24px;border-top:1px solid var(--line)}.btn-save{min-width:130px;background:#176ff2;color:#fff}.table-meta{text-align:center;font-weight:600}.table-actions{display:flex;gap:7px;justify-content:flex-end}.table-actions .btn{width:40px;height:40px;display:grid;place-items:center;padding:0}.table thead th{color:#fff;background:#168b58;white-space:nowrap}.table td{white-space:nowrap;vertical-align:middle}.member-badge{color:#fff;background:#168b58}.farmer-badge{color:#fff;background:#df3545}.pagination{justify-content:flex-end}.search-row{display:flex;gap:10px}.empty{padding:50px;text-align:center;color:#87958d}@media(max-width:1120px){.content{padding:22px}.deduct-grid{grid-template-columns:repeat(2,1fr)}.round-summary{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.content{padding:14px 10px}.hero{align-items:flex-start;flex-direction:column;padding:22px}.hero-stats{width:100%}.deduct-grid,.summary,.round-summary{grid-template-columns:1fr}.form-body{padding:18px}.panel-head{align-items:flex-start;flex-direction:column}.search-row{width:100%;flex-wrap:wrap}}</style></head><body>
<?php if($lan===''):?><style>.table-actions .btn-warning,.table-actions form{display:none}</style><?php endif;?>
<?php render_topbar(); ?><div class="app"><?php render_sidebar('rubbers'); ?><main class="content"><div class="shell">
<section class="hero"><div><h1><i class="bi bi-droplet me-2"></i>จัดการข้อมูลยางพารา</h1><p>บันทึกรับซื้อ ติดตามยอดหัก และตรวจสอบข้อมูลในหน้าจอเดียว</p></div><div class="hero-stats"><span class="hero-pill"><i class="bi bi-calendar3 me-2"></i><?php echo h(rubber_thai_date(date('Y-m-d'))); ?></span><span class="hero-pill"><i class="bi bi-check2-square me-2"></i><?php echo number_format($total); ?> รายการ</span></div></section>
<?php if($flash):?><div class="alert alert-<?php echo h($flash['type']); ?> mt-3"><?php echo h($flash['message']); ?></div><?php endif;?><?php if($error):?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif;?>
<nav class="yard-filter"><strong><i class="bi bi-droplet me-1"></i>เลือกลานรับยาง</strong><a class="<?php echo $lan===''?'active':'';?>" href="?">ทั้งหมด</a><?php for($i=1;$i<=4;$i++):?><a class="<?php echo $lan===(string)$i?'active':'';?>" href="?lan=<?php echo $i;?>">ลานที่ <?php echo $i;?></a><?php endfor;?></nav>
<?php if($lan===''):?><section class="round-summary"><article class="round-card"><span>ปริมาณรวมรอบล่าสุด</span><strong><?php echo rubber_number($roundSummary['quantity']); ?> kg</strong></article><article class="round-card"><span>มูลค่ายางรวม</span><strong><?php echo rubber_number($roundSummary['value']); ?> บาท</strong></article><article class="round-card"><span>ยอดหักรวม</span><strong><?php echo rubber_number($roundSummary['expend']); ?> บาท</strong></article><article class="round-card"><span>ยอดสุทธิรวม</span><strong><?php echo rubber_number($roundSummary['netvalue']); ?> บาท</strong></article></section><?php endif;?>
<?php if($lan!==''):?>
<section class="panel"><div class="panel-head"><h2><?php echo $editRow?'แก้ไขรายการ #'.(int)$editRow['ru_id']:'เพิ่มรายการใหม่'; ?></h2><?php if($editRow):?><a href="<?php echo h(url_for('rubbers.php')); ?>" class="btn btn-light">ยกเลิก</a><?php endif;?></div><form method="post" id="rubberForm"><div class="form-body"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token());?>"><input type="hidden" name="action" value="<?php echo $editRow?'update':'create';?>"><?php if($editRow):?><input type="hidden" name="id" value="<?php echo (int)$editRow['ru_id'];?>"><?php endif;?>
<h3 class="section-title">เลือกสมาชิก</h3><div class="row g-3 align-items-end"><div class="col-lg-8"><label class="form-label" for="memberSearch">ค้นหา</label><input class="form-control" id="memberSearch" list="memberList" placeholder="ชื่อ / เลขที่ / กลุ่ม / ชั้น"><datalist id="memberList"><?php foreach($members as $m):?><option value="<?php echo h($m['mem_fullname']);?>" data-group="<?php echo h($m['mem_group']);?>" data-number="<?php echo h($m['mem_number']);?>" data-class="member"><?php echo h($m['mem_number'].' · กลุ่ม '.$m['mem_group']);?></option><?php endforeach;?></datalist></div><div class="col-lg-4"><label class="form-label">ประเภท</label><select class="form-select" name="class"><option value="member" <?php echo $form['ru_class']==='member'?'selected':'';?>>สมาชิก</option><option value="farmer" <?php echo $form['ru_class']==='farmer'?'selected':'';?>>เกษตรกรทั่วไป</option></select></div></div><hr class="my-4"><h3 class="section-title">ข้อมูลพื้นฐาน</h3>
<div class="row g-3"><div class="col-md-3"><label class="form-label">วันที่</label><input class="form-control" type="date" name="date" value="<?php echo h($form['ru_date']);?>" required></div><div class="col-md-3"><label class="form-label">ลาน</label><select class="form-select" name="lan"><?php for($i=1;$i<=4;$i++):?><option value="<?php echo $i;?>" <?php echo $form['ru_lan']==$i?'selected':'';?>>ลาน <?php echo $i;?></option><?php endfor;?></select></div><div class="col-md-2"><label class="form-label">กลุ่ม</label><input class="form-control" id="group" name="group" value="<?php echo h($form['ru_group']);?>"></div><div class="col-md-2"><label class="form-label">เลขที่</label><input class="form-control" id="number" name="number" value="<?php echo h($form['ru_number']);?>"></div><div class="col-md-2"><label class="form-label">ปริมาณ</label><input class="form-control calc" name="quantity" type="number" min="0.01" step="0.01" value="<?php echo h($form['ru_quantity']);?>" required></div><div class="col-12"><label class="form-label">ชื่อ-สกุล</label><input class="form-control" id="fullname" name="fullname" value="<?php echo h($form['ru_fullname']);?>" required></div></div>
<hr class="my-4"><h3 class="section-title">การหัก</h3><div class="deduct-grid"><?php foreach(['hoon'=>'หุ้น','loan'=>'เงินกู้','shortdebt'=>'หนี้สั้น','deposit'=>'เงินฝาก','tradeloan'=>'ลูกหนี้การค้า','insurance'=>'ประกันภัย'] as $key=>$label):?><div><label class="form-label"><?php echo $label;?></label><input class="form-control calc deduction text-end" name="<?php echo $key;?>" type="number" min="0" step="0.01" value="<?php echo h($form['ru_'.$key]);?>"></div><?php endforeach;?></div>
<div class="summary"><div class="summary-box">มูลค่ายาง = ราคา × ปริมาณ<strong id="gross">0.00</strong></div><div class="summary-box">ยอดหักรวม<strong id="deduct">0.00</strong></div><div class="summary-box">ยอดสุทธิที่ได้รับ<strong id="net">0.00</strong></div><input type="hidden" id="latestPrice" value="<?php echo $latestPrice;?>"></div></div><div class="form-footer"><span><?php echo $editRow?'กำลังแก้ไขรายการ':'สร้างรายการใหม่';?></span><button class="btn btn-save" type="submit"><i class="bi bi-floppy me-2"></i>บันทึก</button></div></form></section>
<?php endif;?>
<section class="panel"><div class="panel-head"><div><h2>รายการรวบรวมยาง</h2><div class="text-secondary">ผลลัพธ์ <?php echo number_format($total);?> รายการ | หน้า <?php echo $page;?>/<?php echo $pages;?></div></div><form class="search-row" method="get"><?php if($lan!==''):?><input type="hidden" name="lan" value="<?php echo h($lan);?>"><?php endif;?><input class="form-control" name="q" value="<?php echo h($search);?>" placeholder="ค้นหาชื่อ / เลขที่ / กลุ่ม"><button class="btn btn-dark"><i class="bi bi-search"></i></button><a class="btn btn-outline-success" href="?<?php echo http_build_query(['lan'=>$lan,'q'=>$search,'export'=>'csv']);?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a></form></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>ID</th><th>วันที่</th><th>ลาน</th><th>กลุ่ม</th><th>เลขที่</th><th>ชื่อ-สกุล</th><th class="text-end">ปริมาณ</th><th class="text-end">หุ้น</th><th class="text-end">เงินกู้</th><th class="text-end">หนี้สั้น</th><th class="text-end">เงินฝาก</th><th class="text-end">ลูกหนี้การค้า</th><th class="text-end">ประกันภัย</th><th class="text-end">จัดการ</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?php echo (int)$r['ru_id'];?></td><td><?php echo h(rubber_thai_date($r['ru_date']));?></td><td><?php echo h($r['ru_lan']);?></td><td><?php echo h($r['ru_group']);?></td><td><?php echo h($r['ru_number']);?></td><td><?php echo h($r['ru_fullname']);?> <span class="badge <?php echo $r['ru_class']==='member'?'member-badge':'farmer-badge';?>"><?php echo $r['ru_class']==='member'?'สมาชิก':'เกษตรกร';?></span></td><td class="text-end"><?php echo rubber_number($r['ru_quantity']);?></td><?php foreach(['ru_hoon','ru_loan','ru_shortdebt','ru_deposit','ru_tradeloan','ru_insurance'] as $f):?><td class="text-end"><?php echo rubber_number($r[$f]);?></td><?php endforeach;?><td><div class="table-actions"><a class="btn btn-warning" href="?edit=<?php echo (int)$r['ru_id'];?>&lan=<?php echo h($lan);?>"><i class="bi bi-pencil-square"></i></a><form method="post" onsubmit="return confirm('ยืนยันการลบรายการนี้?')"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token());?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['ru_id'];?>"><button class="btn btn-danger"><i class="bi bi-trash"></i></button></form><a class="btn btn-outline-dark" href="?receipt=<?php echo (int)$r['ru_id'];?>" target="_blank"><i class="bi bi-file-earmark-text"></i></a></div></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="14" class="empty">ไม่พบข้อมูลรวบรวมยาง</td></tr><?php endif;?></tbody></table></div><div class="p-3"><nav><ul class="pagination mb-0"><?php if($page>1):?><li class="page-item"><a class="page-link" href="?<?php echo http_build_query(['lan'=>$lan,'q'=>$search,'page'=>$page-1]);?>">ก่อนหน้า</a></li><?php endif;?><?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++):?><li class="page-item <?php echo $p===$page?'active':'';?>"><a class="page-link" href="?<?php echo http_build_query(['lan'=>$lan,'q'=>$search,'page'=>$p]);?>"><?php echo $p;?></a></li><?php endfor;?><?php if($page<$pages):?><li class="page-item"><a class="page-link" href="?<?php echo http_build_query(['lan'=>$lan,'q'=>$search,'page'=>$page+1]);?>">ถัดไป</a></li><?php endif;?></ul></nav></div></section>
</div></main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script>
const memberSearch=document.getElementById('memberSearch');if(memberSearch){memberSearch.addEventListener('change',()=>{const option=[...document.querySelectorAll('#memberList option')].find(o=>o.value===memberSearch.value);if(option){document.getElementById('fullname').value=option.value;document.getElementById('group').value=option.dataset.group;document.getElementById('number').value=option.dataset.number;}});function calculate(){const quantity=parseFloat(document.querySelector('[name="quantity"]').value)||0;const price=parseFloat(document.getElementById('latestPrice').value)||0;const gross=quantity*price;const deduct=[...document.querySelectorAll('.deduction')].reduce((sum,input)=>sum+(parseFloat(input.value)||0),0);document.getElementById('gross').textContent=gross.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});document.getElementById('deduct').textContent=deduct.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});document.getElementById('net').textContent=(gross-deduct).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});}document.querySelectorAll('.calc').forEach(input=>input.addEventListener('input',calculate));calculate();}
</script></body></html>
