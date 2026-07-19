<?php
require_once __DIR__ . '/auth.php';
require_user_permission('placement');
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/navbar.php';

ensure_system_schema();
$user = current_user();
$yards = active_yards();
$requestedYardCode = trim((string) ($_GET['yard'] ?? $_POST['yard_code'] ?? ''));
$selectedYard = null;
foreach ($yards as $yardOption) {
  if ((string) $yardOption['yard_code'] === $requestedYardCode) {
    $selectedYard = $yardOption;
    break;
  }
}
$members = db()->query('SELECT mem_id, mem_group, mem_number, mem_fullname, mem_class FROM tbl_member ORDER BY CAST(mem_number AS UNSIGNED), mem_number')->fetchAll();
$error = '';
$flash = $_SESSION['bag_intake_flash'] ?? null;
unset($_SESSION['bag_intake_flash']);

function bag_redirect($yardCode = '')
{
  $url = url_for('bag-intake.php');
  if ($yardCode !== '') $url .= '?' . http_build_query(['yard' => $yardCode]);
  header('Location: ' . $url);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) throw new RuntimeException('แบบฟอร์มหมดอายุ กรุณาลองใหม่');
    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
      $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
      if (!$id) throw new RuntimeException('ไม่พบรายการที่ต้องการลบ');
      $stmt = db()->prepare('SELECT placement.wang_saveby, placement.wang_date, placement.wang_lan,
          placement.wang_mid, placement.wang_number, placement.wang_name, placement.wang_sack,
          workflow.workflow_status
        FROM tbl_wangyang placement
        LEFT JOIN tbl_rubber_workflow workflow ON workflow.weigh_date = placement.wang_date
          AND workflow.yard_code = placement.wang_lan AND workflow.member_id = placement.wang_mid
        WHERE placement.wang_id = :id');
      $stmt->execute(['id' => $id]);
      $deletedRow = $stmt->fetch();
      if (!$deletedRow) throw new RuntimeException('ไม่พบรายการที่ต้องการลบ');
      if ($deletedRow['workflow_status'] === 'paid') throw new RuntimeException('รายการนี้จ่ายเงินแล้ว จึงลบการวางยางไม่ได้ หากต้องแก้ข้อมูลให้ admin ดำเนินการจากหน้าชั่งน้ำหนักหรือรายการหัก');
      if (($user['user_level'] ?? '') !== 'admin' && $deletedRow['wang_saveby'] !== $user['user_fullname']) throw new RuntimeException('คุณไม่มีสิทธิ์ลบรายการนี้');
      db()->beginTransaction();
      db()->prepare('DELETE FROM tbl_wangyang WHERE wang_id = :id')->execute(['id' => $id]);
      audit_log('delete', 'placement', $id, 'ลบรายการวางยางของสมาชิก ' . $deletedRow['wang_number'], [
        'round_date' => $deletedRow['wang_date'], 'yard_code' => $deletedRow['wang_lan'],
        'member_number' => $deletedRow['wang_number'], 'bags' => (float) $deletedRow['wang_sack'],
      ]);
      db()->commit();
      $_SESSION['bag_intake_flash'] = ['type' => 'success', 'message' => 'ลบรายการวางยางเรียบร้อย'];
      bag_redirect($deletedRow['wang_lan']);
    }

    $auctionDate = trim($_POST['auction_date'] ?? '');
    $yardCode = trim($_POST['yard_code'] ?? '');
    $memberId = filter_var($_POST['member_id'] ?? 0, FILTER_VALIDATE_INT);
    $bags = filter_var($_POST['bags'] ?? 0, FILTER_VALIDATE_INT);
    $note = trim($_POST['note'] ?? '');
    $dateObject = DateTime::createFromFormat('Y-m-d', $auctionDate);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $auctionDate) throw new RuntimeException('วันที่ช่อง/ประมูลยางไม่ถูกต้อง');
    if (!$bags || $bags < 1 || $bags > 10000) throw new RuntimeException('จำนวนกระสอบต้องอยู่ระหว่าง 1–10,000');

    $stmt = db()->prepare('SELECT yard_code FROM tbl_yard WHERE yard_code = :code AND yard_status = "active"');
    $stmt->execute(['code' => $yardCode]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('กรุณาเลือกลานยางที่เปิดใช้งาน');

    $stmt = db()->prepare('SELECT * FROM tbl_member WHERE mem_id = :id LIMIT 1');
    $stmt->execute(['id' => $memberId]);
    $member = $stmt->fetch();
    if (!$member) throw new RuntimeException('ไม่พบสมาชิกที่เลือก');

    $stmt = db()->prepare('SELECT ws_weight_per_bag FROM tbl_wangyang_daily_summary WHERE ws_date = :date');
    $stmt->execute(['date' => $auctionDate]);
    $rate = (float) ($stmt->fetchColumn() ?: 0);
    $estimatedWeight = $bags * $rate;

    db()->beginTransaction();
    $stmt = db()->prepare('INSERT INTO tbl_wangyang
      (wang_date, wang_lan, wang_note, wang_mid, wang_group, wang_number, wang_name, wang_class, wang_sack, wang_weight, wang_status, wang_saveby)
      VALUES(:date, :yard, :note, :mid, :member_group, :number, :name, :member_class, :bags, :weight, "placed", :saveby)');
    $stmt->execute([
      'date' => $auctionDate, 'yard' => $yardCode, 'note' => $note, 'mid' => $member['mem_id'],
      'member_group' => $member['mem_group'], 'number' => $member['mem_number'], 'name' => $member['mem_fullname'],
      'member_class' => $member['mem_class'], 'bags' => $bags, 'weight' => $estimatedWeight, 'saveby' => $user['user_fullname'],
    ]);
    $placementId = (int) db()->lastInsertId();
    audit_log('create', 'placement', $placementId, 'บันทึกวางยาง ' . number_format($bags) . ' กระสอบ สำหรับสมาชิก ' . $member['mem_number'], [
      'round_date' => $auctionDate, 'yard_code' => $yardCode, 'member_id' => (int) $member['mem_id'],
      'member_number' => $member['mem_number'], 'bags' => (int) $bags, 'estimated_weight' => $estimatedWeight,
    ]);
    db()->commit();
    $_SESSION['bag_intake_flash'] = ['type' => 'success', 'message' => 'บันทึกวางยาง ' . number_format($bags) . ' กระสอบเรียบร้อย'];
    bag_redirect($yardCode);
  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $error = $e instanceof PDOException ? db_friendly_error($e) : $e->getMessage();
  }
}

$latestRows = [];
if ($selectedYard) {
  $stmt = db()->prepare('SELECT w.*, COALESCE(y.yard_name, CONCAT("ลาน ", w.wang_lan)) AS yard_name,
      workflow.workflow_status
    FROM tbl_wangyang w LEFT JOIN tbl_yard y ON y.yard_code = w.wang_lan
    LEFT JOIN tbl_rubber_workflow workflow ON workflow.weigh_date = w.wang_date
      AND workflow.yard_code = w.wang_lan AND workflow.member_id = w.wang_mid
    WHERE w.wang_lan = :yard_code ORDER BY w.wang_id DESC LIMIT 30');
  $stmt->execute(['yard_code' => $selectedYard['yard_code']]);
  $latestRows = $stmt->fetchAll();
}
$defaultDate = date('Y-m-d', strtotime('+2 days'));
$selectedMemberId = (int) ($_POST['member_id'] ?? 0);
$selectedMember = null;
foreach ($members as $memberOption) {
  if ((int) $memberOption['mem_id'] === $selectedMemberId) {
    $selectedMember = $memberOption;
    break;
  }
}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>บันทึกวางยาง</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="<?php echo h(navbar_url('navbar.css')); ?>" rel="stylesheet"><link href="<?php echo h(url_for('operations.css')); ?>" rel="stylesheet"></head>
<body><?php render_topbar(); ?><main class="ops-shell">
<section class="ops-hero"><div><h1><i class="bi bi-box-seam me-2"></i>บันทึกการวางยาง</h1><p>รับยางล่วงหน้าก่อนวันช่องยาง 2 วัน แยกจำนวนกระสอบตามสมาชิกและลาน</p></div><span class="pill">ผู้บันทึก: <?php echo h($user['user_fullname']); ?></span></section>
<?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?> mt-3"><?php echo h($flash['message']); ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo h($error); ?></div><?php endif; ?>
<?php if (!$yards): ?><div class="alert alert-warning mt-3">ยังไม่มีลานยาง กรุณา <a href="<?php echo h(url_for('setup.php')); ?>">ตั้งค่าระบบ</a> ก่อนเริ่มบันทึก</div><?php endif; ?>
<?php if ($yards && !$selectedYard): ?>
<section class="yard-choice-panel"><div class="yard-choice-head"><span class="yard-choice-step">ขั้นตอนที่ 1</span><h2>เลือกลานที่ต้องการวางยาง</h2><p>กดปุ่มลานก่อน แล้วระบบจะแสดงช่องค้นหาสมาชิกและบันทึกจำนวนกระสอบ</p></div><div class="yard-choice-grid"><?php foreach ($yards as $index => $yard): ?><a class="yard-choice-card" href="<?php echo h(url_for('bag-intake.php') . '?' . http_build_query(['yard' => $yard['yard_code']])); ?>"><span class="yard-choice-number"><?php echo number_format($index + 1); ?></span><span class="yard-choice-icon"><i class="bi bi-geo-alt-fill"></i></span><strong><?php echo h($yard['yard_name']); ?></strong><small>รหัสลาน <?php echo h($yard['yard_code']); ?></small><span class="yard-choice-action">เลือกใช้ลานนี้ <i class="bi bi-arrow-right"></i></span></a><?php endforeach; ?></div></section>
<?php elseif ($selectedYard): ?>
<section class="selected-yard-bar"><div><span class="selected-yard-icon"><i class="bi bi-geo-alt-fill"></i></span><span><small>กำลังบันทึกข้อมูลที่</small><strong><?php echo h($selectedYard['yard_name']); ?></strong></span></div><a class="btn btn-outline-success" href="<?php echo h(url_for('bag-intake.php')); ?>"><i class="bi bi-arrow-left-right me-1"></i>เปลี่ยนลาน</a></section>
<div class="ops-grid">
<section class="ops-card sticky"><div class="ops-card-head"><h2>รายการรับวางยาง</h2></div><form class="ops-card-body" method="post"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="create">
<div class="mb-3"><label class="form-label">วันที่ช่อง/ประมูลยาง</label><input class="form-control" type="date" name="auction_date" required value="<?php echo h($_POST['auction_date'] ?? $defaultDate); ?>"><div class="form-hint mt-1">กำหนดเริ่มต้นเป็น 2 วันจากวันนี้</div></div>
<input type="hidden" name="yard_code" value="<?php echo h($selectedYard['yard_code']); ?>"><div class="locked-yard-field"><span>ลานยาง</span><strong><i class="bi bi-geo-alt-fill me-1"></i><?php echo h($selectedYard['yard_name']); ?></strong><small>ลานถูกกำหนดจากขั้นตอนก่อนหน้า</small></div>
<div class="mb-3 member-picker"><label class="form-label" for="memberSearch">ค้นหาและเลือกสมาชิก</label><div class="member-search-wrap"><i class="bi bi-search member-search-icon"></i><input id="memberSearch" class="form-control member-search-input" type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="memberSuggestions" placeholder="พิมพ์เลขสมาชิกหรือชื่อ" value="<?php echo $selectedMember ? h($selectedMember['mem_number'] . ' · ' . $selectedMember['mem_fullname']) : ''; ?>" required><button id="memberClear" class="member-clear" type="button" aria-label="ล้างสมาชิก" <?php echo $selectedMember ? '' : 'hidden'; ?>><i class="bi bi-x-lg"></i></button></div><input id="memberId" type="hidden" name="member_id" value="<?php echo $selectedMemberId ?: ''; ?>"><div id="memberSuggestions" class="member-suggestions" role="listbox" aria-label="รายชื่อสมาชิก" hidden></div><div id="selectedMember" class="selected-member member-selected-card" <?php echo $selectedMember ? '' : 'hidden'; ?>><?php if ($selectedMember): ?><strong><i class="bi bi-person-check-fill me-1"></i><?php echo h($selectedMember['mem_number'] . ' · ' . $selectedMember['mem_fullname']); ?></strong><small>กลุ่ม <?php echo h($selectedMember['mem_group']); ?> · <?php echo ($selectedMember['mem_class'] ?? '') === 'member' ? 'สมาชิก' : 'เกษตรกรทั่วไป'; ?></small><?php endif; ?></div><div class="form-hint mt-1">พิมพ์เพื่อค้นหา แล้วกดเลือกรายชื่อที่แสดงขึ้นมา</div></div>
<div class="mb-3"><label class="form-label">จำนวนกระสอบ</label><input class="form-control" type="number" name="bags" min="1" max="10000" required value="<?php echo h($_POST['bags'] ?? ''); ?>"></div>
<div class="mb-3"><label class="form-label">หมายเหตุ</label><textarea class="form-control" name="note" rows="3"><?php echo h($_POST['note'] ?? ''); ?></textarea></div>
<button class="btn btn-green w-100" <?php echo !$yards ? 'disabled' : ''; ?>><i class="bi bi-floppy me-1"></i>บันทึกจำนวนกระสอบ</button></form></section>
<section class="ops-card"><div class="ops-card-head"><div><h2>รายการล่าสุด · <?php echo h($selectedYard['yard_name']); ?></h2><small class="text-secondary">แสดงเฉพาะรายการของลานที่เลือก</small></div><a class="btn btn-sm btn-outline-success" href="<?php echo h(url_for('bag-report.php')); ?>">เปิดรายงาน</a></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>วันช่องยาง</th><th>สมาชิก</th><th class="num">กระสอบ</th><th class="num">ประมาณ kg</th><th>ผู้บันทึก</th><th></th></tr></thead><tbody>
<?php foreach ($latestRows as $row): ?><tr><td><?php echo h($row['wang_date']); ?></td><td><strong><?php echo h($row['wang_number']); ?></strong><br><small><?php echo h($row['wang_name']); ?></small></td><td class="num fw-bold"><?php echo number_format((float) $row['wang_sack'], 0); ?></td><td class="num"><?php echo number_format((float) $row['wang_weight'], 2); ?></td><td><small><?php echo h($row['wang_saveby']); ?><br><?php echo h($row['wang_savedate']); ?></small></td><td><?php if ($row['workflow_status'] === 'paid'): ?><span class="text-secondary" title="ล็อกหลังจ่ายเงิน"><i class="bi bi-lock-fill"></i></span><?php elseif (($user['user_level'] ?? '') === 'admin' || $row['wang_saveby'] === $user['user_fullname']): ?><form method="post" onsubmit="return confirm('ยืนยันการลบรายการนี้?')"><input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $row['wang_id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$latestRows): ?><tr><td colspan="6" class="empty">ยังไม่มีรายการวางยางในลานนี้</td></tr><?php endif; ?>
</tbody></table></div></section></div>
<?php endif; ?>
</main><script>
(function(){
  var members=<?php echo json_encode(array_values($members), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var search=document.getElementById('memberSearch');
  var memberId=document.getElementById('memberId');
  var suggestions=document.getElementById('memberSuggestions');
  var selectedCard=document.getElementById('selectedMember');
  var clearButton=document.getElementById('memberClear');
  if(!search||!memberId||!suggestions)return;
  var activeIndex=-1;
  var visibleMembers=[];
  var maxResults=12;

  function normalize(value){return String(value||'').toLocaleLowerCase('th-TH').replace(/\s+/g,' ').trim();}
  function memberLabel(member){return member.mem_number+' · '+member.mem_fullname;}
  function memberClassLabel(member){return member.mem_class==='member'?'สมาชิก':'เกษตรกรทั่วไป';}
  function closeList(){suggestions.hidden=true;search.setAttribute('aria-expanded','false');search.removeAttribute('aria-activedescendant');activeIndex=-1;}
  function setActive(index){
    var options=suggestions.querySelectorAll('[role="option"]');
    if(!options.length)return;
    activeIndex=Math.max(0,Math.min(index,options.length-1));
    options.forEach(function(option,i){option.classList.toggle('active',i===activeIndex);});
    options[activeIndex].scrollIntoView({block:'nearest'});
    search.setAttribute('aria-activedescendant',options[activeIndex].id);
  }
  function selectMember(member){
    memberId.value=member.mem_id;
    search.value=memberLabel(member);
    search.setCustomValidity('');
    clearButton.hidden=false;
    selectedCard.hidden=false;
    selectedCard.innerHTML='<strong><i class="bi bi-person-check-fill me-1"></i>'+escapeHtml(memberLabel(member))+'</strong><small>กลุ่ม '+escapeHtml(member.mem_group||'-')+' · '+memberClassLabel(member)+'</small>';
    closeList();
  }
  function escapeHtml(value){var node=document.createElement('span');node.textContent=String(value||'');return node.innerHTML;}
  function renderList(){
    var term=normalize(search.value);
    visibleMembers=members.filter(function(member){return !term||normalize([member.mem_number,member.mem_fullname,member.mem_group].join(' ')).indexOf(term)!==-1;});
    var shown=visibleMembers.slice(0,maxResults);
    suggestions.innerHTML='';
    if(!shown.length){suggestions.innerHTML='<div class="member-no-result"><i class="bi bi-person-x"></i><strong>ไม่พบสมาชิก</strong><span>ลองค้นหาด้วยชื่อหรือเลขสมาชิกอื่น</span></div>';}
    shown.forEach(function(member,index){
      var option=document.createElement('button');
      option.type='button';option.className='member-suggestion';option.id='memberOption'+index;option.setAttribute('role','option');
      option.innerHTML='<span class="member-avatar"><i class="bi bi-person-fill"></i></span><span class="member-suggestion-copy"><strong>'+escapeHtml(member.mem_number)+' · '+escapeHtml(member.mem_fullname)+'</strong><small>กลุ่ม '+escapeHtml(member.mem_group||'-')+' · '+memberClassLabel(member)+'</small></span><i class="bi bi-chevron-right member-pick-arrow"></i>';
      option.addEventListener('mousedown',function(event){event.preventDefault();selectMember(member);});
      suggestions.appendChild(option);
    });
    if(visibleMembers.length>maxResults){var more=document.createElement('div');more.className='member-result-more';more.textContent='พบ '+visibleMembers.length.toLocaleString('th-TH')+' รายการ · พิมพ์เพิ่มเพื่อจำกัดผลลัพธ์';suggestions.appendChild(more);}
    suggestions.hidden=false;search.setAttribute('aria-expanded','true');activeIndex=-1;
  }
  search.addEventListener('focus',renderList);
  search.addEventListener('input',function(){memberId.value='';selectedCard.hidden=true;clearButton.hidden=!search.value;search.setCustomValidity('กรุณาเลือกสมาชิกจากรายชื่อ');renderList();});
  search.addEventListener('keydown',function(event){
    if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();if(suggestions.hidden)renderList();setActive(activeIndex+(event.key==='ArrowDown'?1:-1));}
    else if(event.key==='Enter'&&!suggestions.hidden&&activeIndex>=0){event.preventDefault();selectMember(visibleMembers[activeIndex]);}
    else if(event.key==='Escape'){closeList();}
  });
  clearButton.addEventListener('click',function(){memberId.value='';search.value='';selectedCard.hidden=true;clearButton.hidden=true;search.setCustomValidity('กรุณาเลือกสมาชิกจากรายชื่อ');search.focus();renderList();});
  document.addEventListener('click',function(event){if(!event.target.closest('.member-picker'))closeList();});
  search.form.addEventListener('submit',function(event){if(!memberId.value){event.preventDefault();search.setCustomValidity('กรุณาเลือกสมาชิกจากรายชื่อ');search.reportValidity();search.focus();renderList();}});
  if(memberId.value)search.setCustomValidity('');
}());
</script></body></html>
