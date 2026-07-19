<?php
$dbFile = "/opt/netscan/netscan.db";

try {
    $db = new SQLite3($dbFile);
} catch (Exception $e) {
    die("Could not connect to database.");
}

function riskClass($risk) {
    switch ($risk) {
        case "High Risk": return "risk-high";
        case "Medium Risk": return "risk-medium";
        case "Low Risk": return "risk-low";
        default: return "risk-none";
    }
}

$latestTimeResult = $db->query("SELECT scan_time FROM scans ORDER BY id DESC LIMIT 1");
$latestTimeRow = $latestTimeResult->fetchArray(SQLITE3_ASSOC);
$latestTime = $latestTimeRow ? $latestTimeRow['scan_time'] : null;

$latestDevices = [];
if ($latestTime) {
    $stmt = $db->prepare("SELECT ip_address, hostname, open_ports, risk FROM scans WHERE scan_time = :time ORDER BY ip_address");
    $stmt->bindValue(':time', $latestTime, SQLITE3_TEXT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $latestDevices[] = $row;
    }
}

$historyResult = $db->query("SELECT scan_time, ip_address, hostname, open_ports, risk FROM scans ORDER BY id DESC LIMIT 200");
$historyRows = [];
while ($row = $historyResult->fetchArray(SQLITE3_ASSOC)) {
    $historyRows[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Network Scan Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f4f4f4; }
        h1, h2 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; background: white; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
        th { background: #333; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }
        .meta { color: #666; margin-bottom: 20px; }

        tr.risk-high, span.risk-high { background: #f8d7da; color: #721c24; font-weight: bold; }
        tr.risk-medium, span.risk-medium { background: #fff3cd; color: #856404; font-weight: bold; }
        tr.risk-low, span.risk-low { background: #d1ecf1; color: #0c5460; }
        tr.risk-none, span.risk-none { background: #d4edda; color: #155724; }

        .legend span { display: inline-block; padding: 4px 10px; margin-right: 8px; border-radius: 4px; font-size: 0.9em; }
    </style>
</head>
<body>

    <h1>Network Scan Dashboard</h1>
    <p class="meta">Page loaded: <?php echo date("Y-m-d H:i:s"); ?></p>

    <p class="legend">
        <span class="risk-high">High Risk</span>
        <span class="risk-medium">Medium Risk</span>
        <span class="risk-low">Low Risk</span>
        <span class="risk-none">No Risk Flagged</span>
    </p>

    <h2>Latest Scan (<?php echo htmlspecialchars($latestTime ?? "No data yet"); ?>)</h2>
    <table>
        <tr><th>IP Address</th><th>Hostname</th><th>Open Ports</th><th>Risk</th></tr>
        <?php if (empty($latestDevices)): ?>
            <tr><td colspan="4">No devices found in the latest scan.</td></tr>
        <?php else: ?>
            <?php foreach ($latestDevices as $device): ?>
                <tr class="<?php echo riskClass($device['risk']); ?>">
                    <td><?php echo htmlspecialchars($device['ip_address']); ?></td>
                    <td><?php echo htmlspecialchars($device['hostname']); ?></td>
                    <td><?php echo htmlspecialchars($device['open_ports'] ?? 'not scanned'); ?></td>
                    <td><?php echo htmlspecialchars($device['risk'] ?? 'Unknown'); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <h2>Scan History (last 200 entries)</h2>
    <table>
        <tr><th>Scan Time</th><th>IP Address</th><th>Hostname</th><th>Open Ports</th><th>Risk</th></tr>
        <?php foreach ($historyRows as $row): ?>
            <tr class="<?php echo riskClass($row['risk']); ?>">
                <td><?php echo htmlspecialchars($row['scan_time']); ?></td>
                <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                <td><?php echo htmlspecialchars($row['hostname']); ?></td>
                <td><?php echo htmlspecialchars($row['open_ports'] ?? 'not scanned'); ?></td>
                <td><?php echo htmlspecialchars($row['risk'] ?? 'Unknown'); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

</body>
</html>