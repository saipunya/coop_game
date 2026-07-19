<?php
require_once __DIR__ . '/system.php';

function ensure_workflow_schema()
{
  ensure_system_schema();
}

function sync_workflow_records()
{
  ensure_workflow_schema();
  db()->exec('INSERT INTO tbl_rubber_workflow
    (weigh_date, yard_code, member_id, member_number, member_name, member_group, placement_at, total_bags, estimated_weight, workflow_status)
    SELECT wang_date, wang_lan, wang_mid, MAX(wang_number), MAX(wang_name), MAX(wang_group), MIN(wang_savedate),
      SUM(wang_sack), SUM(wang_weight), "placed"
    FROM tbl_wangyang
    WHERE wang_status IN ("placed", "weighed", "deducted", "paid")
    GROUP BY wang_date, wang_lan, wang_mid
    ON DUPLICATE KEY UPDATE
      member_number = VALUES(member_number), member_name = VALUES(member_name), member_group = VALUES(member_group),
      placement_at = COALESCE(placement_at, VALUES(placement_at)),
      total_bags = VALUES(total_bags), estimated_weight = VALUES(estimated_weight)');

  db()->exec('DELETE workflow FROM tbl_rubber_workflow workflow
    LEFT JOIN tbl_wangyang placement
      ON placement.wang_date = workflow.weigh_date
      AND placement.wang_lan = workflow.yard_code
      AND placement.wang_mid = workflow.member_id
      AND placement.wang_status IN ("placed", "weighed", "deducted", "paid")
    WHERE placement.wang_id IS NULL AND workflow.workflow_status = "placed"');
}

function workflow_status_label($status)
{
  $labels = [
    'placed' => 'รอชั่ง',
    'weighed' => 'ชั่งแล้ว',
    'deducted' => 'บันทึกยอดหักแล้ว',
    'paid' => 'จ่ายเงินแล้ว',
  ];
  return $labels[$status] ?? $status;
}

function workflow_status_class($status)
{
  $classes = ['placed' => 'status-wait', 'weighed' => 'status-weighed', 'deducted' => 'status-deducted', 'paid' => 'status-paid'];
  return $classes[$status] ?? 'status-wait';
}

function update_placement_status($workflowId, $status)
{
  $stmt = db()->prepare('SELECT weigh_date, yard_code, member_id FROM tbl_rubber_workflow WHERE workflow_id = :id');
  $stmt->execute(['id' => $workflowId]);
  $row = $stmt->fetch();
  if (!$row) return;
  $stmt = db()->prepare('UPDATE tbl_wangyang SET wang_status = :status
    WHERE wang_date = :weigh_date AND wang_lan = :yard_code AND wang_mid = :member_id');
  $stmt->execute([
    'status' => $status,
    'weigh_date' => $row['weigh_date'],
    'yard_code' => $row['yard_code'],
    'member_id' => $row['member_id'],
  ]);
}

function workflow_receipt_no($workflowId, $weighDate)
{
  return 'RC' . str_replace('-', '', $weighDate) . str_pad((string) $workflowId, 6, '0', STR_PAD_LEFT);
}

function workflow_redirect($page, $params = [])
{
  $url = url_for($page);
  if ($params) $url .= '?' . http_build_query($params);
  header('Location: ' . $url);
  exit;
}
?>
