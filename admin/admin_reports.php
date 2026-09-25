<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/status_constants.php';
requireRole('Admin');

function reportQuery($conn, $sql, $types = '', $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('Admin reports query preparation failed: ' . mysqli_error($conn));
        return [];
    }

    if ($types !== '') {
        $bindParams = [$stmt, $types];
        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bindParams);
    }

    if (!mysqli_stmt_execute($stmt)) {
        error_log('Admin reports query execution failed: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

function reportScalar($conn, $sql, $types = '', $params = [], $fallback = 0)
{
    $rows = reportQuery($conn, $sql, $types, $params);
    return $rows ? ($rows[0]['value'] ?? $fallback) : $fallback;
}

function validReportDate($value, $fallback)
{
    $date = DateTime::createFromFormat('Y-m-d', (string) $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$range = $_GET['range'] ?? 'today';
$startDate = $today;
$endDate = $today;

switch ($range) {
    case 'week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        break;
    case 'month':
        $startDate = date('Y-m-01');
        break;
    case 'custom':
        $startDate = validReportDate($_GET['start_date'] ?? '', $today);
        $endDate = validReportDate($_GET['end_date'] ?? '', $today);
        break;
    default:
        $range = 'today';
}

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$departmentId = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
$departmentId = $departmentId && $departmentId > 0 ? $departmentId : null;
$departmentFilter = $departmentId ?? 0;
$departments = reportQuery($conn, 'SELECT DepartmentID, DepartmentName FROM departments ORDER BY DepartmentName');
$filterTypes = 'ssii';
$filterParams = [$startDate, $endDate, $departmentFilter, $departmentFilter];
$baseAppointmentFilter = "a.AppointmentDate BETWEEN ? AND ?
    AND (? = 0 OR a.DepartmentID = ?)
    AND a.Status NOT IN ('Cancelled', 'No Show')";

$totalPatients = (int) reportScalar($conn, "SELECT COUNT(DISTINCT a.PatientID) AS value FROM appointments a WHERE $baseAppointmentFilter", $filterTypes, $filterParams);
$completed = (int) reportScalar($conn, "SELECT COUNT(*) AS value FROM appointments a WHERE $baseAppointmentFilter AND a.Status = 'Completed'", $filterTypes, $filterParams);
$averageConsultMinutes = (int) reportScalar(
    $conn,
    "SELECT COALESCE(ROUND(AVG(TIMESTAMPDIFF(MINUTE, TIMESTAMP(c.ConsultationDate, c.ConsultationTime), c.UpdatedAt))), 0) AS value
       FROM consultations c
       INNER JOIN appointments a ON a.AppointmentID = c.AppointmentID
      WHERE c.ConsultationDate BETWEEN ? AND ?
        AND (? = 0 OR a.DepartmentID = ?)
        AND c.Status = 'Completed'",
    $filterTypes,
    $filterParams
);
$activeStaff = (int) reportScalar(
    $conn,
    "SELECT COUNT(*) AS value FROM staff s INNER JOIN users u ON u.UserID = s.UserID WHERE u.Status = 'Active' AND (? = 0 OR s.DepartmentID = ?)",
    'ii',
    [$departmentFilter, $departmentFilter]
);

$doctorRows = reportQuery(
    $conn,
    "SELECT s.StaffID, CONCAT('Dr. ', u.FirstName, ' ', u.LastName) AS DoctorName,
            SUM(CASE WHEN a.Status = 'Completed' THEN 1 ELSE 0 END) AS CompletedCount,
            SUM(CASE WHEN a.Status IN ('Pending', 'Scheduled', 'Checked In', 'Called', 'In Consultation') THEN 1 ELSE 0 END) AS ActiveCount
       FROM staff s
       INNER JOIN users u ON u.UserID = s.UserID
       LEFT JOIN appointments a ON a.StaffID = s.StaffID AND a.AppointmentDate BETWEEN ? AND ?
          AND (? = 0 OR a.DepartmentID = ?) AND a.Status NOT IN ('Cancelled', 'No Show')
      WHERE s.StaffRole = 'Doctor' AND u.Status = 'Active' AND (? = 0 OR s.DepartmentID = ?)
      GROUP BY s.StaffID, u.FirstName, u.LastName
      ORDER BY CompletedCount DESC, DoctorName",
    'ssiiii',
    [$startDate, $endDate, $departmentFilter, $departmentFilter, $departmentFilter, $departmentFilter]
);

$departmentRows = reportQuery(
    $conn,
    "SELECT d.DepartmentID, d.DepartmentName, COUNT(a.AppointmentID) AS TotalAppointments,
            SUM(CASE WHEN a.AppointmentTime < '12:00:00' THEN 1 ELSE 0 END) AS MorningCount,
            SUM(CASE WHEN a.AppointmentTime >= '12:00:00' THEN 1 ELSE 0 END) AS AfternoonCount,
            COALESCE(ROUND(AVG(CASE WHEN q.QueueTime IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(q.QueueDate, q.QueueTime), q.UpdatedAt) END)), 0) AS AverageWait
       FROM departments d
       LEFT JOIN appointments a ON a.DepartmentID = d.DepartmentID AND a.AppointmentDate BETWEEN ? AND ? AND a.Status NOT IN ('Cancelled', 'No Show')
       LEFT JOIN queue q ON q.AppointmentID = a.AppointmentID
      WHERE (? = 0 OR d.DepartmentID = ?)
      GROUP BY d.DepartmentID, d.DepartmentName
      HAVING TotalAppointments > 0
      ORDER BY TotalAppointments DESC, d.DepartmentName",
    'ssii',
    [$startDate, $endDate, $departmentFilter, $departmentFilter]
);

$trendRows = reportQuery($conn, "SELECT a.AppointmentDate AS ReportDate, COUNT(*) AS TotalAppointments FROM appointments a WHERE $baseAppointmentFilter GROUP BY a.AppointmentDate ORDER BY a.AppointmentDate", $filterTypes, $filterParams);
$dateCursor = new DateTime($startDate);
$dateLimit = new DateTime($endDate);
$trendMap = [];
foreach ($trendRows as $row) {
    $trendMap[$row['ReportDate']] = (int) $row['TotalAppointments'];
}
$trendLabels = [];
$trendValues = [];
while ($dateCursor <= $dateLimit) {
    $dateKey = $dateCursor->format('Y-m-d');
    $trendLabels[] = $dateCursor->format('M j');
    $trendValues[] = $trendMap[$dateKey] ?? 0;
    $dateCursor->modify('+1 day');
}

$exportUrl = 'admin_reports.php?' . http_build_query([
    'range' => $range,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'department_id' => $departmentId,
    'export' => 'csv'
]);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="curora-report-' . $startDate . '-to-' . $endDate . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Curora Report', $startDate . ' to ' . $endDate]);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Patients', $totalPatients]);
    fputcsv($output, ['Completed Appointments', $completed]);
    fputcsv($output, ['Average Consultation Time (minutes)', $averageConsultMinutes]);
    fputcsv($output, ['Active Staff', $activeStaff]);
    fputcsv($output, []);
    fputcsv($output, ['Department', 'Appointments', 'Morning', 'Afternoon', 'Average Wait (minutes)']);
    foreach ($departmentRows as $row) {
        fputcsv($output, [$row['DepartmentName'], $row['TotalAppointments'], $row['MorningCount'], $row['AfternoonCount'], $row['AverageWait']]);
    }
    fclose($output);
    exit;
}

$navItems = [
    ['admin_dashboard.php', 'Dashboard'], ['admin_department.php', 'Departments'], ['admin_doctor_management.php', 'Doctors'],
    ['admin_patient_management.php', 'Patients'], ['admin_staff_management.php', 'Staff'], ['admin_reports.php', 'Reports'],
    ['admin_announcements.php', 'Announcement'], ['admin_profile.php', 'Profile'], ['admin_system_settings.php', 'System Settings']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports &amp; Analytics — Curora Admin Portal</title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/admin/admin_reports.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="sidebar-brand"><div class="brand-icon"><img src="../assets/images/curora-icon.png" alt="Curora"></div><div class="brand-text"><div class="brand-title">Curora</div><div class="brand-sub">Admin Portal</div></div></div>
    <ul class="nav-list">
      <?php foreach ($navItems as [$href, $label]): ?><li class="nav-item<?= $label === 'Reports' ? ' active' : '' ?>"><a href="<?= e($href) ?>"><?= e($label) ?></a></li><?php endforeach; ?>
    </ul>
    <?php include __DIR__ . '/../includes/admin_sidebar_footer.php'; ?>
  </aside>

  <main class="main">
    <div class="page-header"><div><h1>Reports &amp; Analytics</h1><p>Hospital performance metrics and insights</p></div><div class="header-actions"><a class="export-button" href="<?= e($exportUrl) ?>">Export CSV</a><button class="print-button" type="button" onclick="window.print()">Print / PDF</button></div></div>
    <form class="report-toolbar" method="get" action="admin_reports.php">
      <div class="toolbar-group"><label for="range">Date range</label><select id="range" name="range"><option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option><option value="week" <?= $range === 'week' ? 'selected' : '' ?>>This week</option><option value="month" <?= $range === 'month' ? 'selected' : '' ?>>This month</option><option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option></select></div>
      <div class="toolbar-group custom-date"><label for="start_date">From</label><input type="date" id="start_date" name="start_date" value="<?= e($startDate) ?>"></div>
      <div class="toolbar-group custom-date"><label for="end_date">To</label><input type="date" id="end_date" name="end_date" value="<?= e($endDate) ?>"></div>
      <div class="toolbar-group"><label for="department_id">Department</label><select id="department_id" name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['DepartmentID'] ?>" <?= $departmentId === (int) $department['DepartmentID'] ? 'selected' : '' ?>><?= e($department['DepartmentName']) ?></option><?php endforeach; ?></select></div>
      <button class="apply-button" type="submit">Apply filters</button>
    </form>

    <div class="kpi-grid"><div class="kpi-card"><div class="kpi-number"><?= $totalPatients ?></div><div class="kpi-label">Total Patients</div></div><div class="kpi-card"><div class="kpi-number"><?= $completed ?></div><div class="kpi-label">Completed Appointments</div></div><div class="kpi-card"><div class="kpi-number"><?= $averageConsultMinutes ?>m</div><div class="kpi-label">Avg Consult Time</div></div><div class="kpi-card"><div class="kpi-number"><?= $activeStaff ?></div><div class="kpi-label">Active Staff</div></div></div>

    <div class="chart-grid"><section class="panel chart-panel"><div class="panel-head"><div class="panel-title">Patient Volume Trend</div><div class="panel-sub">Appointments across the selected date range</div></div><div class="chart-wrap"><canvas id="volumeChart"></canvas></div></section><section class="panel chart-panel"><div class="panel-head"><div class="panel-title">Department Utilization</div><div class="panel-sub">Appointment volume by department</div></div><div class="chart-wrap doughnut-wrap"><canvas id="departmentChart"></canvas></div></section></div>

    <div class="two-column"><section class="panel"><div class="panel-head"><div class="panel-title">Doctor Workload</div><div class="panel-sub">Completed and active appointments in this period</div></div><div class="doctor-list"><?php if (!$doctorRows): ?><div class="empty-state">No active doctors match the selected filters.</div><?php else: foreach ($doctorRows as $doctor): $done = (int) $doctor['CompletedCount']; $active = (int) $doctor['ActiveCount']; $progress = min(100, (int) round(($done / 20) * 100)); ?><div class="doctor-item"><div class="doctor-info"><span class="doctor-name"><?= e($doctor['DoctorName']) ?></span><span class="doctor-stats"><?= $done ?> completed, <?= $active ?> active</span></div><div class="progress-bar"><div class="progress-fill" style="width: <?= $progress ?>%"></div></div><div class="progress-label"><?= $done ?>/20</div></div><?php endforeach; endif; ?></div></section><section class="panel"><div class="panel-head"><div class="panel-title">Department Performance</div><div class="panel-sub">Patient volume and average wait times</div></div><div class="department-list"><?php if (!$departmentRows): ?><div class="empty-state">No appointment data exists for the selected filters.</div><?php else: foreach ($departmentRows as $department): $total = (int) $department['TotalAppointments']; $percentage = (int) round(($total / 20) * 100); ?><div class="department-item"><div class="dept-header"><div class="dept-code"><?= e(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $department['DepartmentName']), 0, 4))) ?></div><div class="dept-name"><?= e($department['DepartmentName']) ?></div></div><div class="dept-stats"><span><?= (int) $department['MorningCount'] ?> morning</span><span><?= (int) $department['AfternoonCount'] ?> afternoon</span><span>Avg wait: <?= (int) $department['AverageWait'] ?>m</span></div><div class="dept-progress"><div class="progress-bar"><div class="progress-fill" style="width: <?= min(100, $percentage) ?>%"></div></div><div class="dept-total"><?= $total ?>/20</div><div class="dept-percentage"><?= $percentage ?>%</div></div></div><?php endforeach; endif; ?></div></section></div>
  </main>
</div>
<script>
const trendLabels = <?= json_encode($trendLabels) ?>;
const trendValues = <?= json_encode($trendValues) ?>;
const departmentLabels = <?= json_encode(array_column($departmentRows, 'DepartmentName')) ?>;
const departmentValues = <?= json_encode(array_map('intval', array_column($departmentRows, 'TotalAppointments'))) ?>;
const chartColors = ['#149385', '#2563eb', '#d97706', '#7c3aed', '#dc2626', '#0891b2'];
new Chart(document.getElementById('volumeChart'), {type:'line', data:{labels:trendLabels,datasets:[{label:'Appointments',data:trendValues,borderColor:'#149385',backgroundColor:'rgba(20,147,133,.12)',fill:true,tension:.35,pointRadius:3}]}, options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
new Chart(document.getElementById('departmentChart'), {type:'doughnut',data:{labels:departmentLabels.length?departmentLabels:['No data'],datasets:[{data:departmentValues.length?departmentValues:[1],backgroundColor:departmentValues.length?chartColors:['#e2e8f0'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
const rangeSelect = document.getElementById('range');
function toggleCustomDates(){document.querySelectorAll('.custom-date').forEach((field)=>field.classList.toggle('is-hidden',rangeSelect.value!=='custom'));}
rangeSelect.addEventListener('change',toggleCustomDates); toggleCustomDates();
</script>
</body>
</html>
