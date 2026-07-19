<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/navbar.php';

$user = current_user();
$isAdmin = $user && ($user['user_level'] ?? '') === 'admin';
ensure_system_schema();
$error = '';
$flash = $_SESSION['price_flash'] ?? null;
unset($_SESSION['price_flash']);

function price_redirect($params = '')
{
  $path = url_for('price.php') . ($params !== '' ? '?' . ltrim($params, '?') : '');
  header('Location: ' . $path);
  exit;
}

function require_price_admin($isAdmin)
{
  if (!$isAdmin) {
    http_response_code(403);
    throw new RuntimeException('เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถแก้ไขข้อมูลราคาได้');
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    require_price_admin($isAdmin);

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
      throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่อีกครั้ง');
    }

    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'delete') {
      if (!$id) {
        throw new RuntimeException('ไม่พบรายการราคาที่ต้องการลบ');
      }

      $stmt = db()->prepare('SELECT pr_date FROM tbl_price WHERE pr_id = :id');
      $stmt->execute(['id' => $id]);
      $priceDate = $stmt->fetchColumn();
      if ($priceDate === false) throw new RuntimeException('ไม่พบรายการราคาที่ต้องการลบ');
      $stmt = db()->prepare('SELECT COUNT(*) FROM tbl_rubber_workflow WHERE weigh_date = :date AND workflow_status IN ("deducted", "paid")');
      $stmt->execute(['date' => $priceDate]);
      if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('ลบราคานี้ไม่ได้ เนื่องจากมีการคำนวณยอดหรือจ่ายเงินแล้ว');
      $stmt = db()->prepare('DELETE FROM tbl_price WHERE pr_id = :id');
      $stmt->execute(['id' => $id]);
      audit_log('delete', 'price', $id, 'ลบราคายางรอบวันที่ ' . $priceDate, ['price_date' => $priceDate]);
      $_SESSION['price_flash'] = ['type' => 'success', 'message' => 'ลบข้อมูลราคาเรียบร้อยแล้ว'];
      price_redirect();
    }

    if (!in_array($action, ['create', 'update'], true)) {
      throw new RuntimeException('คำสั่งไม่ถูกต้อง');
    }

    $date = trim($_POST['date'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);

    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
      throw new RuntimeException('กรุณาระบุวันที่ราคาให้ถูกต้อง');
    }
    if (!is_numeric($price) || (float) $price <= 0 || (float) $price > 999999) {
      throw new RuntimeException('ราคาต้องเป็นตัวเลขมากกว่า 0');
    }

    $data = [
      'year' => (int) $dateObject->format('Y') + 543,
      'date' => $date,
      'round' => '1',
      'price' => number_format((float) $price, 2, '.', ''),
      'saveby' => $user['user_fullname'],
      'savedate' => date('Y-m-d'),
    ];

    $stmt = db()->prepare('SELECT pr_id FROM tbl_price WHERE pr_date = :date AND (:id_zero = 0 OR pr_id <> :id_exclude) LIMIT 1');
    $stmt->execute(['date' => $date, 'id_zero' => $id ?: 0, 'id_exclude' => $id ?: 0]);
    if ($stmt->fetchColumn()) throw new RuntimeException('วันที่นี้มีราคายางอยู่แล้ว สามารถบันทึกได้เพียงราคาเดียวต่อวัน');

    if ($action === 'update') {
      $stmt = db()->prepare('SELECT pr_date FROM tbl_price WHERE pr_id = :id');
      $stmt->execute(['id' => $id]);
      $oldDate = $stmt->fetchColumn();
      if ($oldDate !== false) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM tbl_rubber_workflow WHERE weigh_date = :date AND workflow_status IN ("deducted", "paid")');
        $stmt->execute(['date' => $oldDate]);
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('แก้ไขราคานี้ไม่ได้ เนื่องจากมีการคำนวณยอดหรือจ่ายเงินแล้ว');
      }
    }

    if ($action === 'create') {
      $stmt = db()->prepare('
        INSERT INTO tbl_price (pr_year, pr_date, pr_number, pr_price, pr_saveby, pr_savedate)
        VALUES (:year, :date, :round, :price, :saveby, :savedate)
      ');
      $message = 'เพิ่มข้อมูลราคาเรียบร้อยแล้ว';
    } else {
      if (!$id) {
        throw new RuntimeException('ไม่พบรายการราคาที่ต้องการแก้ไข');
      }
      $data['id'] = $id;
      $stmt = db()->prepare('
        UPDATE tbl_price
        SET pr_year = :year, pr_date = :date, pr_number = :round,
            pr_price = :price, pr_saveby = :saveby, pr_savedate = :savedate
        WHERE pr_id = :id
      ');
      $message = 'แก้ไขข้อมูลราคาเรียบร้อยแล้ว';
    }

    $stmt->execute($data);
    $priceId = $action === 'create' ? (int) db()->lastInsertId() : (int) $id;
    audit_log($action, 'price', $priceId, ($action === 'create' ? 'เพิ่ม' : 'แก้ไข') . 'ราคายางรอบวันที่ ' . $date . ' เป็น ' . number_format((float) $price, 2) . ' บาท/kg', [
      'price_date' => $date, 'price' => (float) $price, 'round_number' => '1',
    ]);
    $_SESSION['price_flash'] = ['type' => 'success', 'message' => $message];
    price_redirect();
  } catch (Throwable $e) {
    if ($e instanceof PDOException) {
      error_log('Price CRUD failed: ' . $e->getMessage());
      $error = db_friendly_error($e);
    } else {
      $error = $e->getMessage();
    }
  }
}

$editPrice = null;
$prices = [];
$latestPrice = null;
$search = trim($_GET['q'] ?? '');
$year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT) ?: 0;

try {
  if ($isAdmin && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId) {
      $stmt = db()->prepare('SELECT * FROM tbl_price WHERE pr_id = :id LIMIT 1');
      $stmt->execute(['id' => $editId]);
      $editPrice = $stmt->fetch() ?: null;
    }
  }

  $where = [];
  $params = [];
  if ($search !== '') {
    $where[] = 'pr_saveby LIKE :search';
    $params['search'] = '%' . $search . '%';
  }
  if ($year > 0) {
    $where[] = 'pr_year = :year';
    $params['year'] = $year;
  }

  $sql = 'SELECT * FROM tbl_price';
  if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY pr_date DESC, pr_id DESC';
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $prices = $stmt->fetchAll();

  $latestStmt = db()->query('SELECT * FROM tbl_price ORDER BY pr_date DESC, pr_id DESC LIMIT 1');
  $latestPrice = $latestStmt->fetch() ?: null;
} catch (Throwable $e) {
  error_log('Price list failed: ' . $e->getMessage());
  $error = db_friendly_error($e);
}
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>บันทึกราคายาง</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
  :root {
    --primary: #651fff;
    --primary-dark: #4d14ce;
    --ink: #2e2638;
  }

  body {
    min-height: 100vh;
    font-family: "Sarabun", sans-serif;
    color: var(--ink);
    background: #f5f3f8;
  }

  .page-shell {
    max-width: 1400px;
    margin: auto;
    padding: 34px 22px 60px;
  }

  .hero {
    padding: 28px;
    border-radius: 18px;
    color: #fff;
    background: linear-gradient(120deg, #4d14ce, #7d3cff 62%, #9d72ff);
    box-shadow: 0 18px 44px rgba(101, 31, 255, .22);
  }

  .latest-value {
    font-size: clamp(2rem, 4vw, 3.5rem);
    font-weight: 800;
    line-height: 1;
  }

  .panel {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 8px 28px rgba(45, 30, 61, .08);
  }

  .panel .card-header {
    padding: 20px 22px;
    border-bottom-color: #eee9f3;
    background: #fff;
    border-radius: 16px 16px 0 0;
  }

  .form-control,
  .form-select {
    min-height: 46px;
    border-color: #ddd6e5;
    border-radius: 10px;
  }

  .form-control:focus,
  .form-select:focus {
    border-color: #8757ef;
    box-shadow: 0 0 0 .2rem rgba(101, 31, 255, .12);
  }

  .btn-primary {
    --bs-btn-bg: var(--primary);
    --bs-btn-border-color: var(--primary);
    --bs-btn-hover-bg: var(--primary-dark);
    --bs-btn-hover-border-color: var(--primary-dark);
  }

  .price-pill {
    display: inline-flex;
    padding: 7px 12px;
    border-radius: 999px;
    background: #eee7ff;
    color: #5b21d2;
    font-weight: 800;
  }

  .admin-badge {
    color: #146c43;
    background: #e5f6ed;
  }

  .viewer-badge {
    color: #685f72;
    background: #efecf2;
  }

  .table> :not(caption)>*>* {
    padding: 14px 16px;
    border-color: #eeeaf2;
  }

  .table thead th {
    color: #776e80;
    font-size: 14px;
    letter-spacing: .03em;
    background: #faf9fb;
    white-space: nowrap;
  }

  .empty {
    padding: 60px 20px;
    text-align: center;
    color: #918899;
  }

  @media (max-width: 767px) {
    .page-shell {
      padding: 20px 12px 40px;
    }

    .hero {
      padding: 22px;
    }
  }
  </style>
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
</head>

<body>
  <?php render_topbar(); ?>
  <div class="app">
    <?php render_sidebar('price'); ?>
    <main class="content">
      <div class="page-shell">
        <section class="hero mb-4">
          <div class="row align-items-center g-4">
            <div class="col-md">
              <div class="text-white-50 mb-2">ราคายางล่าสุด</div>
              <div class="latest-value">
                <?php echo $latestPrice ? number_format((float) $latestPrice['pr_price'], 2) : '—'; ?> <small
                  class="fs-5 fw-normal">บาท / kg</small></div>
              <?php if ($latestPrice): ?>
              <div class="mt-3"><i
                  class="bi bi-calendar3 me-2"></i><?php echo h(date('d/m/Y', strtotime($latestPrice['pr_date']))); ?> ·
                ราคาประจำวันชั่ง</div>
              <?php endif; ?>
            </div>
            <div class="col-md-auto text-md-end">
              <h1 class="h3 fw-bold mb-2">จัดการราคายาง</h1>
              <p class="mb-0 text-white-50">หนึ่งราคาใช้สำหรับการชั่งและขายยางทั้งหมดในวันนั้น</p>
            </div>
          </div>
        </section>

        <?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?> alert-dismissible fade show"><i
            class="bi bi-check-circle-fill me-2"></i><?php echo h($flash['message']); ?><button class="btn-close"
            data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><i
            class="bi bi-exclamation-triangle-fill me-2"></i><?php echo h($error); ?></div><?php endif; ?>

        <div class="row g-4">
          <?php if ($isAdmin): ?>
          <div class="col-xl-4">
            <section class="card panel sticky-xl-top" style="top: 24px;">
              <div class="card-header">
                <h2 class="h5 fw-bold mb-1"><i
                    class="bi <?php echo $editPrice ? 'bi-pencil-square' : 'bi-plus-circle'; ?> text-primary me-2"></i><?php echo $editPrice ? 'แก้ไขราคา' : 'บันทึกราคาใหม่'; ?>
                </h2>
                <small class="text-secondary">สำหรับผู้ดูแลระบบเท่านั้น</small>
              </div>
              <div class="card-body p-4">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                  <input type="hidden" name="action" value="<?php echo $editPrice ? 'update' : 'create'; ?>">
                  <?php if ($editPrice): ?><input type="hidden" name="id"
                    value="<?php echo (int) $editPrice['pr_id']; ?>"><?php endif; ?>
                  <div class="mb-3"><label class="form-label fw-semibold" for="date">วันที่ราคา</label><input
                      class="form-control" id="date" name="date" type="date"
                      value="<?php echo h($editPrice['pr_date'] ?? date('Y-m-d')); ?>" required></div>
                  <div class="mb-4"><label class="form-label fw-semibold" for="price">ราคา (บาท/kg)</label>
                    <div class="input-group"><input class="form-control" id="price" name="price" type="number"
                        min="0.01" max="999999" step="0.01" value="<?php echo h($editPrice['pr_price'] ?? ''); ?>"
                        required><span class="input-group-text">บาท</span></div>
                  </div>
                  <div class="d-grid gap-2"><button class="btn btn-primary py-2 fw-bold" type="submit"><i
                        class="bi bi-floppy me-2"></i><?php echo $editPrice ? 'บันทึกการแก้ไข' : 'เพิ่มข้อมูลราคา'; ?></button><?php if ($editPrice): ?><a
                      class="btn btn-light" href="<?php echo h(url_for('price.php')); ?>">ยกเลิก</a><?php endif; ?>
                  </div>
                </form>
              </div>
            </section>
          </div>
          <?php endif; ?>

          <div class="<?php echo $isAdmin ? 'col-xl-8' : 'col-12'; ?>">
            <section class="card panel overflow-hidden">
              <div class="card-header">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                  <div>
                    <h2 class="h5 fw-bold mb-1">ประวัติราคายาง</h2><small class="text-secondary">พบ
                      <?php echo number_format(count($prices)); ?> รายการ</small>
                  </div>
                  <form class="d-flex gap-2" method="get">
                    <input class="form-control form-control-sm" name="q" value="<?php echo h($search); ?>"
                      placeholder="ค้นหาผู้บันทึก">
                    <input class="form-control form-control-sm" name="year" type="number"
                      value="<?php echo $year ?: ''; ?>" placeholder="ปี พ.ศ." style="max-width:120px">
                    <button class="btn btn-dark" aria-label="ค้นหา"><i class="bi bi-search"></i></button>
                  </form>
                </div>
              </div>
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead>
                    <tr>
                      <th>วันที่</th>
                      <th>ปี พ.ศ.</th>
                      <th class="text-end">ราคา</th>
                      <?php if ($isAdmin): ?><th class="text-end">จัดการ</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($prices as $row): ?>
                    <tr>
                      <td class="fw-semibold"><?php echo h(date('d/m/Y', strtotime($row['pr_date']))); ?></td>
                      <td><?php echo h($row['pr_year']); ?></td>
                      <td class="text-end"><span
                          class="price-pill"><?php echo number_format((float) $row['pr_price'], 2); ?> ฿</span></td>
                      <?php if ($isAdmin): ?> <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int) $row['pr_id']; ?>"
                          aria-label="แก้ไข"><i class="bi bi-pencil"></i></a>
                        <form class="d-inline" method="post" onsubmit="return confirm('ยืนยันการลบราคานี้?');">
                          <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input
                            type="hidden" name="action" value="delete"><input type="hidden" name="id"
                            value="<?php echo (int) $row['pr_id']; ?>">
                          <button class="btn btn-sm btn-outline-danger" aria-label="ลบ"><i
                              class="bi bi-trash"></i></button>
                        </form>
                      </td>
                      <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$prices): ?><tr>
                      <td colspan="<?php echo $isAdmin ? 5 : 4; ?>" class="empty"><i
                          class="bi bi-inbox fs-1 d-block mb-2"></i>ไม่พบข้อมูลราคา</td>
                    </tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>
      </div>
    </main>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
