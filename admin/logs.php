<?php
require __DIR__ . '/_guard.php';
chatweb_admin_require_permission($conn, 'audit.view');
$logs = mysqli_query($conn, "SELECT * FROM audit_logs ORDER BY id DESC LIMIT 100");
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Audit Logs | Admin</title>
<style>body{margin:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif}.main{padding:28px}.panel{background:#fff;border:1px solid #dbe3ee;border-radius:8px;overflow:auto}table{width:100%;border-collapse:collapse;min-width:760px}th,td{text-align:left;padding:13px;border-bottom:1px solid #eef2f7}th{color:#64748b}</style></head>
<body><main class="main"><a href="index.php">Dashboard</a><h1>Audit Logs</h1><section class="panel"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>IP</th></tr></thead><tbody>
<?php while ($log = mysqli_fetch_assoc($logs)) { ?><tr><td><?php echo htmlspecialchars($log['created_at']); ?></td><td><?php echo htmlspecialchars($log['actor_type'] . ':' . ($log['actor_id'] ?? '-')); ?></td><td><?php echo htmlspecialchars($log['action']); ?></td><td><?php echo htmlspecialchars(($log['target_type'] ?? '-') . ':' . ($log['target_id'] ?? '-')); ?></td><td><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td></tr><?php } ?>
</tbody></table></section></main></body></html>
