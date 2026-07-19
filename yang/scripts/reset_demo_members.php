<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit("CLI only\n");
}

require_once dirname(__DIR__) . '/workflow.php';

const MEMBER_DEMO_MARKER = '[WORKFLOW_DEMO_V2]';
const EXTRA_DUMMY_MEMBERS = 100;
const MEMBER_GROUP_COUNTS = [
  1 => 15, 2 => 13, 3 => 5, 4 => 5, 5 => 5,
  6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 4,
  11 => 3, 12 => 3, 13 => 3, 14 => 3, 15 => 3,
  16 => 3, 17 => 3, 18 => 3, 19 => 3,
  20 => 2, 21 => 2, 22 => 2, 23 => 2, 24 => 1, 25 => 1,
];

ensure_workflow_schema();
$pdo = db();

$memberIds = $pdo->query('SELECT DISTINCT member.mem_id
  FROM tbl_member member
  INNER JOIN tbl_wangyang placement ON placement.wang_mid = member.mem_id
  WHERE placement.wang_note LIKE "' . MEMBER_DEMO_MARKER . '%"
  ORDER BY member.mem_id')->fetchAll(PDO::FETCH_COLUMN);

if (count($memberIds) !== 12) {
  fwrite(STDERR, 'ยกเลิก: คาดว่าจะพบสมาชิกที่ผูกกับ Workflow Demo 12 ราย แต่พบ ' . count($memberIds) . " ราย\n");
  exit(1);
}

$memberGroupsBySequence = [];
for ($sequence = 1; $sequence <= 12; $sequence++) {
  $memberGroupsBySequence[$sequence] = (string) $sequence;
}
foreach (MEMBER_GROUP_COUNTS as $group => $targetCount) {
  $remaining = $targetCount - ($group <= 12 ? 1 : 0);
  for ($index = 0; $index < $remaining; $index++) {
    $memberGroupsBySequence[] = (string) $group;
  }
}
if (count($memberGroupsBySequence) !== 100 || array_sum(MEMBER_GROUP_COUNTS) !== 100) {
  fwrite(STDERR, "ยกเลิก: จำนวนสมาชิกตามกลุ่มต้องรวมกันเท่ากับ 100 ราย\n");
  exit(1);
}

$dummyMembers = [];
for ($index = 1; $index <= 12; $index++) {
  $dummyMembers[] = [
    'group' => $memberGroupsBySequence[$index],
    'number' => 'DM' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
    'fullname' => 'สมาชิกตัวอย่าง ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
    'birthtext' => str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '/01/40',
    'class' => 'member',
    'pin' => '6600' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
  ];
}

$pdo->beginTransaction();
try {
  $updateMember = $pdo->prepare('UPDATE tbl_member SET
    mem_group = :member_group, mem_number = :member_number, mem_fullname = :member_name,
    mem_birthtext = :birthtext, mem_class = :member_class, mem_personcode = :pin,
    mem_saveby = "DEMO SYSTEM", mem_savedate = CURDATE()
    WHERE mem_id = :member_id');
  $updatePlacement = $pdo->prepare('UPDATE tbl_wangyang SET
    wang_group = :member_group, wang_number = :member_number, wang_name = :member_name,
    wang_class = :member_class
    WHERE wang_mid = :member_id AND wang_note LIKE "' . MEMBER_DEMO_MARKER . '%"');
  $updateWorkflow = $pdo->prepare('UPDATE tbl_rubber_workflow SET
    member_group = :member_group, member_number = :member_number, member_name = :member_name
    WHERE member_id = :member_id');

  foreach ($memberIds as $index => $memberId) {
    $dummy = $dummyMembers[$index];
    $data = [
      'member_group' => $dummy['group'],
      'member_number' => $dummy['number'],
      'member_name' => $dummy['fullname'],
      'member_class' => $dummy['class'],
      'member_id' => (int) $memberId,
    ];
    $updateMember->execute($data + ['birthtext' => $dummy['birthtext'], 'pin' => $dummy['pin']]);
    $updatePlacement->execute($data);
    $updateWorkflow->execute([
      'member_group' => $dummy['group'],
      'member_number' => $dummy['number'],
      'member_name' => $dummy['fullname'],
      'member_id' => (int) $memberId,
    ]);
  }

  $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
  $deleteStmt = $pdo->prepare('DELETE FROM tbl_member WHERE mem_id NOT IN (' . $placeholders . ')');
  $deleteStmt->execute(array_map('intval', $memberIds));
  $deletedCount = $deleteStmt->rowCount();

  $insertMember = $pdo->prepare('INSERT INTO tbl_member
    (mem_group, mem_number, mem_fullname, mem_birthtext, mem_class, mem_personcode, mem_saveby, mem_savedate)
    VALUES(:member_group, :member_number, :member_name, :birthtext, :member_class, :pin, "DEMO MEMBER SEED", CURDATE())');
  for ($offset = 1; $offset <= EXTRA_DUMMY_MEMBERS; $offset++) {
    $sequence = 12 + $offset;
    $day = (($sequence - 1) % 28) + 1;
    $month = ((int) floor(($sequence - 1) / 28) % 12) + 1;
    $isFarmer = $sequence > 100;
    $farmerSequence = $sequence - 100;
    $insertMember->execute([
      'member_group' => $isFarmer ? 'a' : $memberGroupsBySequence[$sequence],
      'member_number' => $isFarmer
        ? 'FR' . str_pad((string) $farmerSequence, 3, '0', STR_PAD_LEFT)
        : 'DM' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
      'member_name' => $isFarmer
        ? 'เกษตรกรทั่วไปตัวอย่าง ' . str_pad((string) $farmerSequence, 2, '0', STR_PAD_LEFT)
        : 'สมาชิกตัวอย่าง ' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
      'birthtext' => str_pad((string) $day, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '/40',
      'member_class' => $isFarmer ? 'farmer' : 'member',
      'pin' => ($isFarmer ? '670' : '660') . str_pad((string) ($isFarmer ? $farmerSequence : $sequence), 3, '0', STR_PAD_LEFT),
    ]);
  }

  $pdo->commit();
  echo json_encode([
    'result' => 'ok',
    'preserved_member_ids' => array_map('intval', $memberIds),
    'workflow_dummy_members' => count($dummyMembers),
    'additional_dummy_members' => EXTRA_DUMMY_MEMBERS,
    'total_dummy_members' => count($dummyMembers) + EXTRA_DUMMY_MEMBERS,
    'cooperative_members' => 100,
    'general_farmers' => 12,
    'member_group_counts' => MEMBER_GROUP_COUNTS,
    'deleted_old_members' => $deletedCount,
    'login_examples' => [
      'member' => ['member_number' => 'DM001', 'pin' => '660001'],
      'farmer' => ['member_number' => 'FR001', 'pin' => '670001'],
    ],
  ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, 'Reset demo members failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
