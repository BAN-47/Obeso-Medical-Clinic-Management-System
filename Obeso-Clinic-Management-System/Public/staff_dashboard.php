<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_name('obeso_staff');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* ================= ACCESS CONTROL ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: access_denied.php");
    exit();
}

/* ================= DATABASE ================= */
require_once "../Config/database.php";
$db = (new Database())->connect();

/* ================= STAFF INFO ================= */
$stmt = $db->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    die("Staff not found.");
}

/* ================= DATA MINING QUERIES ================= */

/* TOTAL PATIENTS */
$totalPatients = $db->query("SELECT COUNT(*) FROM patients")->fetchColumn();

/* TOTAL CHECKUPS */
$totalCheckups = $db->query("SELECT COUNT(*) FROM checkups")->fetchColumn();

/* AVERAGE SYSTOLIC BP */
$avgBP = $db->query("
    SELECT ROUND(AVG(SUBSTRING_INDEX(blood_pressure,'/',1))) 
    FROM checkups
")->fetchColumn();

/* WEEKLY CHECKUPS */
$weeklyStmt = $db->prepare("
    SELECT DAYNAME(checkup_date) AS day, COUNT(*) AS total
    FROM checkups
    WHERE checkup_date >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DAYNAME(checkup_date)
");
$weeklyStmt->execute();

$weekMap = [
    'Monday'=>0,'Tuesday'=>0,'Wednesday'=>0,'Thursday'=>0,
    'Friday'=>0,'Saturday'=>0,'Sunday'=>0
];

while ($row = $weeklyStmt->fetch(PDO::FETCH_ASSOC)) {
    $weekMap[$row['day']] = (int)$row['total'];
}

/* TOP DIAGNOSES */
$diagStmt = $db->query("
    SELECT diagnosis, COUNT(*) AS total
    FROM checkups
    GROUP BY diagnosis
    ORDER BY total DESC
    LIMIT 5
");

$diagLabels = [];
$diagCounts = [];

while ($d = $diagStmt->fetch(PDO::FETCH_ASSOC)) {
    $diagLabels[] = $d['diagnosis'];
    $diagCounts[] = $d['total'];
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
<link href="../Includes/sidebarStyle.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
.stat-card {
    border-radius: 14px;
    padding: 20px;
    color: #fff;
    text-align: center;
}
.bg-blue { background: #0d6efd; }
.bg-green { background: #198754; }
.bg-orange { background: #fd7e14; }
.bg-purple { background: #6f42c1; }
.sb-sidenav .nav-link.active {
    background-color: #062e6bff !important;
    color: #fff !important;
    font-weight: 600;
}
</style>
</head>

<body class="sb-nav-fixed">

<?php include "../Includes/header.html"; ?>
<?php include "../Includes/navbar_staff.html"; ?>

<div id="layoutSidenav">
<div id="layoutSidenav_nav"><?php include "../Includes/staffSidebar.php"; ?></div>

<div id="layoutSidenav_content">
<main class="container-fluid px-4 py-4">

<!-- ================= WELCOME ================= -->
<div class="mb-4" style="margin-top: -40px;">
<h4 class="fw-bold" style="font-size: 35px;">
Welcome, <?= htmlspecialchars($staff['staff_first_name']) ?>
</h4>
<p class="text-muted">Here’s a summary of your clinical activity</p>
</div>

<!-- ================= STATS ================= -->
<div class="row g-4 mb-4">

<div class="col-md-3">
<div class="stat-card bg-blue">
<i class="fa fa-users fa-2x mb-2"></i>
<h6>Total Patients</h6>
<h3><?= $totalPatients ?></h3>
</div>
</div>

<div class="col-md-3">
<div class="stat-card bg-green">
<i class="fa fa-stethoscope fa-2x mb-2"></i>
<h6>Total Checkups</h6>
<h3><?= $totalCheckups ?></h3>
</div>
</div>

<div class="col-md-3">
<div class="stat-card bg-orange">
<i class="fa fa-heartbeat fa-2x mb-2"></i>
<h6>Avg Systolic BP</h6>
<h3><?= $avgBP ?? 'N/A' ?></h3>
</div>
</div>

</div>

<!-- ================= CHARTS ================= -->
<div class="row g-4">

<div class="col-lg-7">
<div class="card shadow">
<div class="card-body">
<h5 class="text-primary mb-3">📈 Weekly Checkup Trend</h5>
<canvas id="weeklyChart"></canvas>
</div>
</div>
</div>

<div class="col-lg-5">
<div class="card shadow">
<div class="card-body">
<h5 class="text-primary mb-3">🧠 Top Diagnoses</h5>
<canvas id="diagnosisChart"></canvas>
</div>
</div>
</div>

</div>

</main>
<?php include "../Includes/footer.html"; ?>
</div>
</div>

<script>
/* WEEKLY CHECKUPS */
new Chart(document.getElementById('weeklyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_keys($weekMap)) ?>,
        datasets: [{
            label: 'Checkups',
            data: <?= json_encode(array_values($weekMap)) ?>,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        scales: { y: { beginAtZero: true } }
    }
});

/* TOP DIAGNOSES */
new Chart(document.getElementById('diagnosisChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($diagLabels) ?>,
        datasets: [{
            label: 'Cases',
            data: <?= json_encode($diagCounts) ?>
        }]
    },
    options: {
        indexAxis: 'y',
        scales: { x: { beginAtZero: true } }
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
