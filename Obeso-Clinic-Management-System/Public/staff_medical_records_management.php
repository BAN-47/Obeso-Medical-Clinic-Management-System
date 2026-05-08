<?php
session_start();

/* ================= ACCESS CONTROL ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: access_denied.php");
    exit();
}

/* ================= DATABASE ================= */
require_once "../Config/database.php";
require_once __DIR__ . "/../Class/checkups.php";
require_once __DIR__ . "/../Class/medications.php";
require_once __DIR__ . "/../Class/prescribed_medication.php";
$db = (new Database())->connect();

/* ================= SEARCH PATIENT ================= */
$search = $_GET['search'] ?? '';
$limit  = 9;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

/* ================= FETCH PATIENTS ================= */
if ($search) {
    $countStmt = $db->prepare("SELECT COUNT(DISTINCT patient_id) FROM patients WHERE full_name LIKE :search");
    $countStmt->execute([':search' => "%$search%"]);
    $totalPatients = $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM patients WHERE full_name LIKE :search GROUP BY patient_id ORDER BY full_name LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
} else {
    $totalPatients = $db->query("SELECT COUNT(DISTINCT patient_id) FROM patients")->fetchColumn();
    $stmt = $db->prepare("SELECT * FROM patients GROUP BY patient_id ORDER BY full_name LIMIT :limit OFFSET :offset");
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, ceil($totalPatients / $limit));

/* ================= FETCH PATIENT RECORD ================= */
$patient = null;
$checkups = [];
$searchDate = $_GET['checkup_date'] ?? '';
$checkupLimit = 4;
$checkupPage = max(1, (int)($_GET['checkup_page'] ?? 1));
$checkupOffset = ($checkupPage - 1) * $checkupLimit;

if (isset($_GET['patient_id'])) {
    $pid = (int)$_GET['patient_id'];
    $patientStmt = $db->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $patientStmt->execute([$pid]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if ($searchDate) {
        $countCheckupStmt = $db->prepare("SELECT COUNT(*) FROM checkups WHERE patient_id = ? AND checkup_date = ?");
        $countCheckupStmt->execute([$pid, $searchDate]);
        $totalCheckups = $countCheckupStmt->fetchColumn();

        $cstmt = $db->prepare("SELECT * FROM checkups WHERE patient_id = :pid AND checkup_date = :searchDate ORDER BY checkup_date DESC LIMIT :checkupLimit OFFSET :checkupOffset");
        $cstmt->bindValue(':pid', $pid, PDO::PARAM_INT);
        $cstmt->bindValue(':searchDate', $searchDate, PDO::PARAM_STR);
        $cstmt->bindValue(':checkupLimit', $checkupLimit, PDO::PARAM_INT);
        $cstmt->bindValue(':checkupOffset', $checkupOffset, PDO::PARAM_INT);
        $cstmt->execute();
    } else {
        $countCheckupStmt = $db->prepare("SELECT COUNT(*) FROM checkups WHERE patient_id = ?");
        $countCheckupStmt->execute([$pid]);
        $totalCheckups = $countCheckupStmt->fetchColumn();

        $cstmt = $db->prepare("SELECT * FROM checkups WHERE patient_id = :pid ORDER BY checkup_date DESC LIMIT :checkupLimit OFFSET :checkupOffset");
        $cstmt->bindValue(':pid', $pid, PDO::PARAM_INT);
        $cstmt->bindValue(':checkupLimit', $checkupLimit, PDO::PARAM_INT);
        $cstmt->bindValue(':checkupOffset', $checkupOffset, PDO::PARAM_INT);
        $cstmt->execute();
    }
    $checkups = $cstmt->fetchAll(PDO::FETCH_ASSOC);
    $totalCheckupPages = max(1, ceil($totalCheckups / $checkupLimit));

    foreach ($checkups as $i => $c) {
        $mstmt = $db->prepare("
            SELECT pm.*, m.generic_name, m.brand_name
            FROM prescribed_medications pm
            INNER JOIN medications m ON pm.medication_id = m.medication_id
            WHERE pm.checkup_id = ?
        ");
        $mstmt->execute([$c['checkup_id']]);
        $checkups[$i]['medications'] = $mstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Obeso's Clinic Management System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
<link href="../Includes/sidebarStyle.css" rel="stylesheet">
<style>
.section-header { background:#062e6b; color:#fff; padding:12px 18px; border-radius:14px 14px 0 0; }
.folder-card { transition:.2s; } 
.folder-card:hover { transform: translateY(-4px); }
.sb-sidenav .nav-link.active { background-color:#062e6bff !important; color:#fff !important; font-weight:600; }
</style>
</head>

<body class="sb-nav-fixed">
<?php include "../Includes/header.html"; ?>
<?php include "../Includes/navbar_staff.html"; ?>

<div id="layoutSidenav">
<div id="layoutSidenav_nav"><?php include "../Includes/staffSidebar.php"; ?></div>
<div id="layoutSidenav_content">
<main class="container-fluid px-4 py-4">

<!-- ================= PATIENT SEARCH ================= -->
<form class="row g-2 mb-4">
<div class="col-md-4">
<input type="text" name="search" class="form-control" placeholder="Search patient..." value="<?= htmlspecialchars($search) ?>">
</div>
<div class="col-md-2">
<button class="btn btn-primary w-100"><i class="fa fa-search"></i> Search</button>
</div>
</form>

<?php if (!$patient): ?>
<!-- ================= PATIENT LIST ================= -->
<div class="row g-4">
<?php foreach ($patients as $p): ?>
<div class="col-md-4">
<div class="card shadow folder-card">
<div class="section-header">
<i class="fa fa-folder me-2"></i><?= htmlspecialchars($p['full_name']) ?> 
</div>
<div class="card-body">
<p>
<strong>Sex:</strong> <?= $p['sex'] ?><br>
<strong>Age:</strong> <?= $p['age'] ?><br>
<strong>Contact:</strong> <?= $p['contact_number'] ?>
</p>
<a href="?patient_id=<?= $p['patient_id'] ?>" class="btn btn-outline-primary w-100">
<i class="fa fa-folder-open"></i> Open Records
</a>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<nav class="mt-4">
<ul class="pagination justify-content-center">
<li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
<a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>">Previous</a>
</li>
<?php for ($i=1;$i<=$totalPages;$i++): ?>
<li class="page-item <?= ($i==$page)?'active':'' ?>">
<a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
</li>
<?php endfor; ?>
<li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
<a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>">Next</a>
</li>
</ul>
</nav>

<?php else: ?>
<!-- ================= PATIENT DETAILS ================= -->
<a href="staff_medical_records_management.php" class="btn btn-secondary mb-3">
<i class="fa fa-arrow-left"></i> Back
</a>

<div class="card shadow mb-4">
<div class="section-header">
<i class="fa fa-user me-2"></i> Patient Information
</div>
<div class="card-body">
<div class="row mb-2">
<div class="col-md-4"><strong>Name:</strong> <?= htmlspecialchars($patient['full_name']) ?></div>
<div class="col-md-2"><strong>Age:</strong> <?= $patient['age'] ?></div>
<div class="col-md-2"><strong>Sex:</strong> <?= $patient['sex'] ?></div>
<div class="col-md-4"><strong>Contact:</strong> <?= $patient['contact_number'] ?></div>
</div>

<div class="row mb-2">
<div class="col-md-4"><strong>Civil Status:</strong> <?= htmlspecialchars($patient['civil_status']) ?></div>
<div class="col-md-2"><strong>Religion:</strong> <?= htmlspecialchars($patient['religion']) ?></div>
<div class="col-md-3"><strong>Occupation:</strong> <?= htmlspecialchars($patient['occupation']) ?></div>
</div>

<div class="row mb-2">
<div class="col-md-4"><strong>Contact Person:</strong> <?= htmlspecialchars($patient['contact_person']) ?></div>
<div class="col-md-2"><strong>Contact Person Age:</strong> <?= htmlspecialchars($patient['contact_person_age']) ?></div>
</div>

<div class="mt-2">
<strong>Address:</strong> <?= htmlspecialchars($patient['address']) ?>
</div>
</div>
</div>

<!-- ================= CHECKUP DATE FILTER ================= -->
<form class="row g-2 mb-4">
<div class="col-md-3">
<input type="date" name="checkup_date" class="form-control" value="<?= htmlspecialchars($searchDate) ?>">
<input type="hidden" name="patient_id" value="<?= $patient['patient_id'] ?>">
</div>
<div class="col-md-2">
<button class="btn btn-primary w-100"><i class="fa fa-search"></i> Search for Checkup Date</button>
</div>
</form>

<?php if ($checkups): ?>
<?php foreach ($checkups as $c): ?>
<div class="card shadow mb-4">
<div class="section-header">
<i class="fa fa-stethoscope me-2"></i>
Checkup — <?= $c['checkup_date'] ?> (Doctor: <?= htmlspecialchars($c['doc_fullname']) ?>)
</div>
<div class="card-body">
<p><strong>Diagnosis:</strong> <?= htmlspecialchars($c['diagnosis']) ?></p>
<p><strong>Chief Complaint:</strong> <?= htmlspecialchars($c['chief_complaint']) ?></p>
<p><strong>HPI:</strong> <?= htmlspecialchars($c['history_present_illness']) ?></p>

<hr>
<div class="row text-center">
<div class="col">BP<br><strong><?= $c['blood_pressure'] ?></strong></div>
<div class="col">RR<br><strong><?= $c['respiratory_rate'] ?></strong></div>
<div class="col">WT<br><strong><?= $c['weight'] ?></strong></div>
<div class="col">HR<br><strong><?= $c['heart_rate'] ?></strong></div>
<div class="col">TEMP<br><strong><?= $c['temperature'] ?></strong></div>
</div>

<?php if (!empty($c['medications'])): ?>
<hr>
<h5>Medications</h5>
<table class="table table-bordered">
<thead>
<tr>
<th>Generic</th><th>Brand</th><th>Dose</th><th>Amount</th><th>Frequency</th><th>Duration</th>
</tr>
</thead>
<tbody>
<?php foreach ($c['medications'] as $m): ?>
<tr>
<td><?= htmlspecialchars($m['generic_name']) ?></td>
<td><?= htmlspecialchars($m['brand_name']) ?></td>
<td><?= htmlspecialchars($m['dose']) ?></td>
<td><?= htmlspecialchars($m['amount']) ?></td>
<td><?= htmlspecialchars($m['frequency']) ?></td>
<td><?= htmlspecialchars($m['duration']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>

<!-- ================= CHECKUP PAGINATION ================= -->
<nav class="mt-4">
<ul class="pagination justify-content-center">
<li class="page-item <?= ($checkupPage <= 1) ? 'disabled' : '' ?>">
<a class="page-link" href="?patient_id=<?= $patient['patient_id'] ?>&checkup_date=<?= urlencode($searchDate) ?>&checkup_page=<?= $checkupPage-1 ?>">Previous</a>
</li>
<?php for ($i=1;$i<=$totalCheckupPages;$i++): ?>
<li class="page-item <?= ($i==$checkupPage)?'active':'' ?>">
<a class="page-link" href="?patient_id=<?= $patient['patient_id'] ?>&checkup_date=<?= urlencode($searchDate) ?>&checkup_page=<?= $i ?>"><?= $i ?></a>
</li>
<?php endfor; ?>
<li class="page-item <?= ($checkupPage >= $totalCheckupPages) ? 'disabled' : '' ?>">
<a class="page-link" href="?patient_id=<?= $patient['patient_id'] ?>&checkup_date=<?= urlencode($searchDate) ?>&checkup_page=<?= $checkupPage+1 ?>">Next</a>
</li>
</ul>
</nav>

<?php else: ?>
<div class="alert alert-warning">No checkups found for this patient<?= $searchDate ? " on $searchDate" : "" ?>.</div>
<?php endif; ?>
<?php endif; ?>
</main>
<?php include "../Includes/footer.html"; ?>
</div>
</div>
</body>
</html>