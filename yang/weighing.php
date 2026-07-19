<?php
require_once __DIR__ . '/auth.php';
require_user_permission('weighing');
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/navbar.php';

sync_workflow_records();
$user = current_user();
$todayDate = (string) db()->query('SELECT CURDATE()')->fetchColumn();
$yards = active_yards();
$requestedYardCode = trim((string) ($_GET['yard'] ?? ''));
$selectedYard = null;
foreach ($yards as $yardOption) {
  if ((string) $yardOption['yard_code'] === $requestedYardCode) {
    $selectedYard = $yardOption;
    break;
  }
}

$flash = $_SESSION['workflow_flash'] ?? null;
unset($_SESSION['workflow_flash']);
$error = '';

function weighing_thai_date($value)
{
  if (!$value) return '-';
  $timestamp = strtotime($value);
  if (!$timestamp) return (string) $value;
  return date('d/m/', $timestamp) . ((int) date('Y', $timestamp) + 543);
}

function weighing_redirect_params()
{
  return [
    'yard' => trim((string) ($_GET['yard'] ?? '')),
    'date' => trim((string) ($_GET['date'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? 'open')),
    'member' => trim((string) ($_GET['member'] ?? '')),
  ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่');
    $id = filter_var($_POST['workflow_id'] ?? 0, FILTER_VALIDATE_INT);
    $weight = filter_var($_POST['actual_weight'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!$id) throw new RuntimeException('ไม่พบรายการที่ต้องการชั่ง');
    if ($weight === false || $weight <= 0 || $weight > 1000000) throw new RuntimeException('น้ำหนักจริงต้องมากกว่า 0 กิโลกรัม');

    db()->beginTransaction();
    $stmt = db()->prepare('SELECT workflow_status, weigh_date, yard_code, member_number, actual_weight
      FROM tbl_rubber_workflow WHERE workflow_id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $workflow = $stmt->fetch();
    if (!$workflow || !in_array($workflow['workflow_status'], ['placed', 'weighed'], true)) {
      throw new RuntimeException('รายการนี้ผ่านขั้นบันทึกยอดหักแล้ว จึงแก้น้ำหนักไม่ได้');
    }
    if (!$selectedYard || (string) $workflow['yard_code'] !== (string) $selectedYard['yard_code']) {
      throw new RuntimeException('รายการชั่งไม่ตรงกับลานที่เลือก');
    }

    $stmt = db()->prepare('UPDATE tbl_rubber_workflow SET actual_weight = :weight, workflow_status = "weighed",
      weighed_by = :staff, weighed_at = NOW() WHERE workflow_id = :id');
    $stmt->execute(['weight' => $weight, 'staff' => $user['user_fullname'], 'id' => $id]);
    update_placement_status($id, 'weighed');
    audit_log('weigh', 'workflow', $id, 'บันทึกน้ำหนักจริง ' . number_format($weight, 2) . ' kg สำหรับสมาชิก ' . $workflow['member_number'], [
      'round_date' => $workflow['weigh_date'],
      'yard_code' => $workflow['yard_code'],
      'actual_weigh_date' => $todayDate,
      'member_number' => $workflow['member_number'],
      'previous_weight' => (float) $workflow['actual_weight'],
      'actual_weight' => (float) $weight,
    ]);
    db()->commit();
    $_SESSION['workflow_flash'] = [
      'type' => 'success',
      'message' => 'บันทึกน้ำหนักจริงเรียบร้อย · วันชั่งน้ำหนักคือวันนี้ ' . weighing_thai_date($todayDate),
    ];
    workflow_redirect('weighing.php', weighing_redirect_params());
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

$date = trim((string) ($_GET['date'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'open'));
$memberFilter = filter_var($_GET['member'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$searchMembers = [];
$selectedMember = null;
$rows = [];

if ($selectedYard) {
  $stmt = db()->prepare('SELECT member_id, MAX(member_number) AS member_number, MAX(member_name) AS member_name,
      MAX(member_group) AS member_group, COUNT(*) AS round_count
    FROM tbl_rubber_workflow
    WHERE yard_code = :yard AND workflow_status IN ("placed", "weighed")
    GROUP BY member_id ORDER BY CAST(MAX(member_number) AS UNSIGNED), MAX(member_number)');
  $stmt->execute(['yard' => $selectedYard['yard_code']]);
  $searchMembers = $stmt->fetchAll();
  foreach ($searchMembers as $memberOption) {
    if ((int) $memberOption['member_id'] === $memberFilter) {
      $selectedMember = $memberOption;
      break;
    }
  }
  if ($memberFilter && !$selectedMember) $memberFilter = 0;

  $where = ['workflow.yard_code = :yard'];
  $params = ['yard' => $selectedYard['yard_code']];
  if ($date !== '') {
    $where[] = 'workflow.weigh_date = :date';
    $params['date'] = $date;
  }
  if ($memberFilter) {
    $where[] = 'workflow.member_id = :member_id';
    $params['member_id'] = $memberFilter;
  }
  if ($statusFilter === 'open') {
    $where[] = 'workflow.workflow_status IN ("placed", "weighed")';
  } elseif (in_array($statusFilter, ['placed', 'weighed', 'deducted', 'paid'], true)) {
    $where[] = 'workflow.workflow_status = :status';
    $params['status'] = $statusFilter;
  }

  $sql = 'SELECT workflow.*, COALESCE(yard.yard_name, CONCAT("ลาน ", workflow.yard_code)) AS yard_name
    FROM tbl_rubber_workflow workflow
    LEFT JOIN tbl_yard yard ON yard.yard_code = workflow.yard_code
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY workflow.weigh_date DESC, workflow.workflow_status = "placed" DESC, workflow.member_number LIMIT 300';
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>บันทึกน้ำหนักยาง</title>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet">
  <link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet">
</head>
<body>
<?php render_topbar(); ?>
<main class="ops-shell weighing-shell">
  <section class="ops-hero weighing-hero">
    <div>
      <h1><i class="bi bi-speedometer2 me-2"></i>บันทึกน้ำหนักยาง</h1>
      <p>เลือกลาน ค้นหาสมาชิก แล้วบันทึกน้ำหนักจริง โดยวันชั่งจะเป็นวันที่บันทึก</p>
    </div>
    <span class="pill">วันนี้ <?php echo h(weighing_thai_date($todayDate)); ?></span>
  </section>

  <?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?> mt-3"><?php echo h($flash['message']); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif; ?>
  <?php if (!$yards): ?><div class="alert alert-warning mt-3">ยังไม่มีลานยาง กรุณา <a href="<?php echo h(url_for('setup.php')); ?>">ตั้งค่าระบบ</a> ก่อนเริ่มชั่ง</div><?php endif; ?>

  <?php if ($yards && !$selectedYard): ?>
    <section class="yard-choice-panel">
      <div class="yard-choice-head">
        <span class="yard-choice-step">ขั้นตอนที่ 1</span>
        <h2>เลือกลานที่ต้องการชั่งยาง</h2>
        <p>เมื่อเลือกลานแล้ว ระบบจะแสดงช่องค้นหาสมาชิกและรายการรอชั่งเฉพาะลานนั้น</p>
      </div>
      <div class="yard-choice-grid">
        <?php foreach ($yards as $index => $yard): ?>
          <a class="yard-choice-card" href="<?php echo h(url_for('weighing.php') . '?' . http_build_query(['yard' => $yard['yard_code']])); ?>">
            <span class="yard-choice-number"><?php echo number_format($index + 1); ?></span>
            <span class="yard-choice-icon"><i class="bi bi-speedometer2"></i></span>
            <strong><?php echo h($yard['yard_name']); ?></strong>
            <small>รหัสลาน <?php echo h($yard['yard_code']); ?></small>
            <span class="yard-choice-action">เปิดรายการชั่ง <i class="bi bi-arrow-right"></i></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php elseif ($selectedYard): ?>
    <section class="selected-yard-bar weighing-yard-bar">
      <div>
        <span class="selected-yard-icon"><i class="bi bi-geo-alt-fill"></i></span>
        <span><small>กำลังชั่งยางที่</small><strong><?php echo h($selectedYard['yard_name']); ?></strong></span>
      </div>
      <a class="btn btn-outline-success" href="<?php echo h(url_for('weighing.php')); ?>"><i class="bi bi-arrow-left-right me-1"></i>เปลี่ยนลาน</a>
    </section>

    <section class="weigh-date-guide">
      <div><span class="date-guide-icon placement"><i class="bi bi-box-seam-fill"></i></span><span><small>วันรับยาง / วางยาง</small><strong>วันที่เจ้าหน้าที่รับกระสอบ</strong></span></div>
      <i class="bi bi-chevron-right"></i>
      <div><span class="date-guide-icon weighing"><i class="bi bi-speedometer2"></i></span><span><small>วันชั่งน้ำหนักจริง</small><strong>วันที่กดบันทึกน้ำหนัก</strong></span></div>
      <i class="bi bi-chevron-right"></i>
      <div><span class="date-guide-icon round"><i class="bi bi-tags-fill"></i></span><span><small>รอบวันที่ราคา</small><strong>ใช้คำนวณยอดในรอบ</strong></span></div>
    </section>

    <section class="ops-card mt-3 weighing-filter-card">
      <div class="ops-card-body">
        <form id="weighingFilter" class="weighing-filter" method="get">
          <input type="hidden" name="yard" value="<?php echo h($selectedYard['yard_code']); ?>">
          <div class="weigh-member-filter member-picker">
            <label class="form-label" for="weighMemberSearch">ค้นหาสมาชิกในลานนี้</label>
            <div class="member-search-wrap">
              <i class="bi bi-search member-search-icon"></i>
              <input id="weighMemberSearch" class="form-control member-search-input" type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="weighMemberSuggestions" placeholder="พิมพ์เลขสมาชิกหรือชื่อ" value="<?php echo $selectedMember ? h($selectedMember['member_number'] . ' · ' . $selectedMember['member_name']) : ''; ?>">
              <button id="weighMemberClear" class="member-clear" type="button" aria-label="ล้างสมาชิก" <?php echo $selectedMember ? '' : 'hidden'; ?>><i class="bi bi-x-lg"></i></button>
            </div>
            <input id="weighMemberId" type="hidden" name="member" value="<?php echo $memberFilter ?: ''; ?>">
            <div id="weighMemberSuggestions" class="member-suggestions" role="listbox" aria-label="สมาชิกที่มีรายการชั่งในลานนี้" hidden></div>
            <div class="form-hint mt-1">เลือกชื่อจากรายการ แล้วระบบจะแสดงงานชั่งของสมาชิกทันที</div>
          </div>
          <div class="weigh-filter-date">
            <label class="form-label">รอบวันที่ราคา</label>
            <input class="form-control" type="date" name="date" value="<?php echo h($date); ?>">
          </div>
          <div class="weigh-filter-status">
            <label class="form-label">สถานะ</label>
            <select class="form-select" name="status">
              <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>งานชั่งทั้งหมด</option>
              <option value="placed" <?php echo $statusFilter === 'placed' ? 'selected' : ''; ?>>รอชั่ง</option>
              <option value="weighed" <?php echo $statusFilter === 'weighed' ? 'selected' : ''; ?>>ชั่งแล้ว</option>
              <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>ทุกสถานะ</option>
            </select>
          </div>
          <button class="btn btn-dark weigh-filter-submit"><i class="bi bi-search me-1"></i>ค้นหา</button>
        </form>
      </div>
    </section>

    <section class="ops-card mt-3">
      <div class="ops-card-head weighing-list-head">
        <div>
          <h2>รายการชั่ง · <?php echo h($selectedYard['yard_name']); ?></h2>
          <small class="text-secondary"><?php echo $selectedMember ? 'สมาชิก ' . h($selectedMember['member_number'] . ' · ' . $selectedMember['member_name']) : 'กดบันทึกแล้ว วันชั่งจริงจะเป็น ' . h(weighing_thai_date($todayDate)); ?></small>
        </div>
        <span class="badge-soft"><?php echo number_format(count($rows)); ?> รายการ</span>
      </div>

      <div class="table-responsive weighing-desktop-list">
        <table class="table table-hover mb-0 weighing-table">
          <thead><tr><th>วันรับยาง / วางยาง</th><th>รอบวันที่ราคา</th><th>วันชั่งน้ำหนักจริง</th><th>ลาน</th><th>สมาชิก</th><th class="num">กระสอบ</th><th class="num">ประมาณ kg</th><th>สถานะ</th><th>กรอกน้ำหนักจริง (kg)</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><div class="date-cell placement-date"><strong><?php echo h(weighing_thai_date($row['placement_at'] ?: $row['created_at'])); ?></strong><small><?php echo $row['placement_at'] ? h(date('H:i', strtotime($row['placement_at'])) . ' น.') : 'วันที่รับเข้าระบบ'; ?></small></div></td>
              <td><div class="date-cell round-date"><strong><?php echo h(weighing_thai_date($row['weigh_date'])); ?></strong><small>วันที่รอบราคา</small></div></td>
              <td><?php if ($row['weighed_at']): ?><div class="date-cell actual-date"><strong><?php echo h(weighing_thai_date($row['weighed_at'])); ?></strong><small><?php echo h(date('H:i', strtotime($row['weighed_at'])) . ' น. · ' . ($row['weighed_by'] ?: '-')); ?></small></div><?php else: ?><div class="date-cell pending-date"><strong>ยังไม่ชั่ง</strong><small>บันทึกวันนี้ = <?php echo h(weighing_thai_date($todayDate)); ?></small></div><?php endif; ?></td>
              <td><?php echo h($row['yard_name']); ?></td>
              <td><strong><?php echo h($row['member_number']); ?></strong><br><small><?php echo h($row['member_name']); ?> · กลุ่ม <?php echo h($row['member_group']); ?></small></td>
              <td class="num"><?php echo number_format((float) $row['total_bags'], 0); ?></td>
              <td class="num"><?php echo number_format((float) $row['estimated_weight'], 2); ?></td>
              <td><span class="workflow-status <?php echo h(workflow_status_class($row['workflow_status'])); ?>"><?php echo h(workflow_status_label($row['workflow_status'])); ?></span></td>
              <td><?php if (in_array($row['workflow_status'], ['placed', 'weighed'], true)): ?><div class="weight-entry"><form class="weight-form" method="post"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="workflow_id" value="<?php echo (int) $row['workflow_id']; ?>"><input class="form-control" type="number" name="actual_weight" min="0.01" max="1000000" step="0.01" inputmode="decimal" required value="<?php echo (float) $row['actual_weight'] > 0 ? h($row['actual_weight']) : ''; ?>" placeholder="0.00"><button class="btn btn-green" type="submit"><i class="bi bi-check2"></i><span>บันทึก</span></button></form><small><i class="bi bi-calendar-check me-1"></i>บันทึกวันชั่งเป็นวันนี้อัตโนมัติ</small></div><?php else: ?><strong><?php echo number_format((float) $row['actual_weight'], 2); ?> kg</strong><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td class="empty" colspan="9">ไม่พบรายการชั่งตามตัวกรอง</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="weighing-mobile-list">
        <?php foreach ($rows as $row): ?>
          <article class="mobile-weigh-card">
            <div class="mobile-weigh-head">
              <div><strong><?php echo h($row['member_number'] . ' · ' . $row['member_name']); ?></strong><small>รอบราคา <?php echo h(weighing_thai_date($row['weigh_date'])); ?></small></div>
              <span class="mobile-yard-badge"><i class="bi bi-geo-alt-fill"></i><?php echo h($row['yard_name']); ?></span>
            </div>
            <div class="mobile-weigh-facts">
              <div><span>จำนวนกระสอบ</span><strong><?php echo number_format((float) $row['total_bags'], 0); ?></strong></div>
              <div><span>น้ำหนักประมาณการ</span><strong><?php echo number_format((float) $row['estimated_weight'], 2); ?> <small>kg</small></strong></div>
            </div>
            <div class="mobile-weight-entry">
              <div class="mobile-weight-label"><span>น้ำหนักจริง (kg)</span><small><?php echo $row['weighed_at'] ? 'ชั่งแล้ว ' . h(weighing_thai_date($row['weighed_at'])) : 'บันทึกวันนี้ ' . h(weighing_thai_date($todayDate)); ?></small></div>
              <?php if (in_array($row['workflow_status'], ['placed', 'weighed'], true)): ?>
                <form class="mobile-weight-form" method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                  <input type="hidden" name="workflow_id" value="<?php echo (int) $row['workflow_id']; ?>">
                  <input class="form-control" type="number" name="actual_weight" min="0.01" max="1000000" step="0.01" inputmode="decimal" required value="<?php echo (float) $row['actual_weight'] > 0 ? h($row['actual_weight']) : ''; ?>" placeholder="0.00">
                  <button class="btn btn-green" type="submit"><i class="bi bi-check2-circle"></i> บันทึก</button>
                </form>
              <?php else: ?><strong class="mobile-final-weight"><?php echo number_format((float) $row['actual_weight'], 2); ?> kg</strong><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="empty">ไม่พบรายการชั่งตามตัวกรอง</div><?php endif; ?>
      </div>
    </section>
  <?php endif; ?>
</main>

<?php if ($selectedYard): ?>
<script>
(function () {
  var members = <?php echo json_encode(array_values($searchMembers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var form = document.getElementById('weighingFilter');
  var search = document.getElementById('weighMemberSearch');
  var memberId = document.getElementById('weighMemberId');
  var suggestions = document.getElementById('weighMemberSuggestions');
  var clearButton = document.getElementById('weighMemberClear');
  if (!form || !search || !memberId || !suggestions || !clearButton) return;

  var activeIndex = -1;
  var visibleMembers = [];
  var maxResults = 12;
  function normalize(value) { return String(value || '').toLocaleLowerCase('th-TH').replace(/\s+/g, ' ').trim(); }
  function escapeHtml(value) { var node = document.createElement('span'); node.textContent = String(value || ''); return node.innerHTML; }
  function label(member) { return member.member_number + ' · ' + member.member_name; }
  function closeList() { suggestions.hidden = true; search.setAttribute('aria-expanded', 'false'); search.removeAttribute('aria-activedescendant'); activeIndex = -1; }
  function selectMember(member) { memberId.value = member.member_id; search.value = label(member); clearButton.hidden = false; closeList(); form.submit(); }
  function setActive(index) {
    var options = suggestions.querySelectorAll('[role="option"]');
    if (!options.length) return;
    activeIndex = Math.max(0, Math.min(index, options.length - 1));
    options.forEach(function (option, optionIndex) { option.classList.toggle('active', optionIndex === activeIndex); });
    options[activeIndex].scrollIntoView({ block: 'nearest' });
    search.setAttribute('aria-activedescendant', options[activeIndex].id);
  }
  function renderList() {
    var term = normalize(search.value);
    visibleMembers = members.filter(function (member) {
      return !term || normalize([member.member_number, member.member_name, member.member_group].join(' ')).indexOf(term) !== -1;
    });
    var shown = visibleMembers.slice(0, maxResults);
    suggestions.innerHTML = '';
    if (!shown.length) suggestions.innerHTML = '<div class="member-no-result"><i class="bi bi-person-x"></i><strong>ไม่พบสมาชิกในลานนี้</strong><span>ลองค้นหาด้วยชื่อหรือเลขสมาชิกอื่น</span></div>';
    shown.forEach(function (member, index) {
      var option = document.createElement('button');
      option.type = 'button';
      option.className = 'member-suggestion';
      option.id = 'weighMemberOption' + index;
      option.setAttribute('role', 'option');
      option.innerHTML = '<span class="member-avatar"><i class="bi bi-person-fill"></i></span><span class="member-suggestion-copy"><strong>' + escapeHtml(label(member)) + '</strong><small>กลุ่ม ' + escapeHtml(member.member_group || '-') + ' · ' + Number(member.round_count || 0).toLocaleString('th-TH') + ' รอบ</small></span><i class="bi bi-chevron-right member-pick-arrow"></i>';
      option.addEventListener('mousedown', function (event) { event.preventDefault(); selectMember(member); });
      suggestions.appendChild(option);
    });
    if (visibleMembers.length > maxResults) {
      var more = document.createElement('div');
      more.className = 'member-result-more';
      more.textContent = 'พบ ' + visibleMembers.length.toLocaleString('th-TH') + ' รายการ · พิมพ์เพิ่มเพื่อจำกัดผลลัพธ์';
      suggestions.appendChild(more);
    }
    suggestions.hidden = false;
    search.setAttribute('aria-expanded', 'true');
    activeIndex = -1;
  }

  search.addEventListener('focus', renderList);
  search.addEventListener('input', function () { memberId.value = ''; clearButton.hidden = !search.value; renderList(); });
  search.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (suggestions.hidden) renderList();
      setActive(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
    } else if (event.key === 'Enter' && !suggestions.hidden && activeIndex >= 0) {
      event.preventDefault();
      selectMember(visibleMembers[activeIndex]);
    } else if (event.key === 'Escape') closeList();
  });
  clearButton.addEventListener('click', function () { memberId.value = ''; search.value = ''; clearButton.hidden = true; form.submit(); });
  document.addEventListener('click', function (event) { if (!event.target.closest('.weigh-member-filter')) closeList(); });
})();
</script>
<?php endif; ?>
</body>
</html>
