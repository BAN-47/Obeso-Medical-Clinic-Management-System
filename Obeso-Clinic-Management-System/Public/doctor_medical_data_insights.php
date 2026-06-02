<?php
session_name('obeso_doctor');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* ================= ACCESS CONTROL ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header("Location: access_denied.php");
    exit();
}

/* ================= DATABASE ================= */
require_once "../Config/database.php";
$db = (new Database())->connect();

/* ================= MOST COMMON ILLNESS THIS MONTH ================= */
$stmt = $db->query("
    SELECT diagnosis, COUNT(*) AS total
    FROM checkups
    WHERE diagnosis IS NOT NULL
      AND diagnosis != ''
      AND MONTH(checkup_date) = MONTH(CURRENT_DATE())
      AND YEAR(checkup_date) = YEAR(CURRENT_DATE())
    GROUP BY diagnosis
    ORDER BY total DESC
");
$monthlyCases = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalCases = array_sum(array_column($monthlyCases, 'total'));

$topIllness = $monthlyCases[0] ?? null;
$percentage = ($topIllness && $totalCases > 0)
    ? round(($topIllness['total'] / $totalCases) * 100, 1)
    : 0;

/* ================= ILLNESS INFO ================= */
$illnessInfo = [
    'Fever' => 'Usually caused by viral or bacterial infections such as flu or dengue.',
    'Cough' => 'Often linked to respiratory infections, allergies, or smoking.',
    'Hypertension' => 'Related to stress, high salt intake, obesity, and inactivity.',
    'Diabetes' => 'Due to insulin resistance and uncontrolled blood sugar.',
    'Headache' => 'May be caused by stress, dehydration, or lack of sleep.'
];

$explanation = $topIllness
    ? ($illnessInfo[$topIllness['diagnosis']] ??
      'This condition may be influenced by lifestyle or environmental factors.')
    : '';

/* ================= ILLNESS PREDICTION ================= */
$predictStmt = $db->query("
    SELECT diagnosis, COUNT(*) AS total
    FROM checkups
    WHERE diagnosis IS NOT NULL
      AND diagnosis != ''
      AND checkup_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
    GROUP BY diagnosis
    ORDER BY total DESC
");
$prediction = $predictStmt->fetch(PDO::FETCH_ASSOC);
$predictedIllness = $prediction['diagnosis'] ?? 'No prediction';

/* ================= AUTO RECOMMENDATIONS ================= */
$recommendations = [
    'Fever' => 'Increase fluids, rest well, and monitor temperature regularly.',
    'Cough' => 'Avoid cold drinks, observe breathing, and rest the throat.',
    'Hypertension' => 'Reduce salt intake and monitor blood pressure daily.',
    'Diabetes' => 'Maintain diet control, exercise, and regular glucose checks.',
    'Headache' => 'Hydrate well, rest, and manage stress.'
];
$recommendationText = $recommendations[$predictedIllness]
    ?? 'Maintain a healthy lifestyle and attend regular checkups.';

/* ================= HIGH-RISK CLUSTERING ================= */
$clusterQuery = $db->query("
    SELECT blood_pressure, heart_rate, temperature
    FROM checkups
");
$clusters = ['High' => 0, 'Moderate' => 0, 'Low' => 0];

foreach ($clusterQuery as $row) {
    $risk = 0;
    if (!empty($row['blood_pressure']) && strpos($row['blood_pressure'], '/') !== false) {
        [$s, $d] = explode('/', $row['blood_pressure']);
        if ($s >= 140 || $d >= 90) $risk++;
    }
    if (!empty($row['heart_rate']) && $row['heart_rate'] >= 100) $risk++;
    if (!empty($row['temperature']) && $row['temperature'] >= 38) $risk++;

    if ($risk >= 2) $clusters['High']++;
    elseif ($risk == 1) $clusters['Moderate']++;
    else $clusters['Low']++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="../Includes/favicon_obeso.png">
<title>Obeso's Clinic Management System</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<link href="../Includes/sidebarStyle.css" rel="stylesheet">

<style>
.insight-box {
    background: linear-gradient(135deg,#fff3cd,#ffffff);
    border-left:6px solid #ffc107;
}
.chart-container {
    height:260px;
    max-width:420px;
    margin:auto;
}
.sb-sidenav .nav-link.active {
    background-color:#062e6bff !important;
    color:#fff !important;
    font-weight:600;
}
</style>
</head>

<body class="sb-nav-fixed">

<?php include "../Includes/header.html"; ?>
<?php include "../Includes/navbar_doctor.html"; ?>

<div id="layoutSidenav">

<!-- SIDEBAR -->
<div id="layoutSidenav_nav">
<?php include "../Includes/doctorSidebar.php"; ?>
</div>

<!-- CONTENT -->
<div id="layoutSidenav_content">
<main class="container-fluid px-4 py-4">

<!-- 📸 CAPTURE AREA -->
<div id="dashboardCapture">

<!-- MOST COMMON ILLNESS -->
<div class="card insight-box shadow mb-4" style="margin-top: -40px;">
<div class="card-body">
<h5 class="fw-bold text-warning">
<i class="fa fa-exclamation-triangle me-1"></i> Most Common Illness This Month
</h5>

<?php if ($topIllness): ?>
<h3><?= htmlspecialchars($topIllness['diagnosis']) ?></h3>

<div class="progress mb-3" style="height:22px;">
<div class="progress-bar bg-warning" style="width:<?= $percentage ?>%">
<?= $percentage ?>%
</div>
</div>

<p>
<strong><?= $percentage ?>%</strong> of patients were diagnosed with
<strong><?= htmlspecialchars($topIllness['diagnosis']) ?></strong>.
</p>

<p class="text-muted">
<i class="fa fa-info-circle"></i> <?= htmlspecialchars($explanation) ?>
</p>
<?php else: ?>
<p>No medical data recorded for this month.</p>
<?php endif; ?>
</div>
</div>

<!-- PREDICTION -->
<div class="card shadow mb-4 border-start border-4 border-warning">
<div class="card-body">
<h5>🔮 Illness Prediction (Next Month)</h5>
<h3 class="text-warning"><?= htmlspecialchars($predictedIllness) ?></h3>
<p><?= htmlspecialchars($recommendationText) ?></p>
</div>
</div>

<!-- CLUSTERING -->
<div class="card shadow mb-4">
<div class="card-body">
<h5>👥 High-Risk Patient Clustering</h5>
<div class="chart-container">
<canvas id="clusterChart"></canvas>
</div>
</div>
</div>

<!-- AI prediction -->
<?php require_once "../Public/ai_prediction.php"; ?>

</div><!-- END CAPTURE -->

</main>
<?php include "../Includes/footer.html"; ?>
</div>
</div>

<script>
new Chart(document.getElementById('clusterChart'), {
    type:'doughnut',
    data:{
        labels:['High Risk','Moderate Risk','Low Risk'],
        datasets:[{
            data:<?= json_encode(array_values($clusters)) ?>,
            backgroundColor:['#dc3545','#ffc107','#198754']
        }]
    },
    options:{ maintainAspectRatio:false }
});
</script>

</body>
</html>
