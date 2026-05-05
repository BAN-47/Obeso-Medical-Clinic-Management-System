<?php
session_start();

/* ================= ACCESS CONTROL ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header("Location: access_denied.php");
    exit();
}

/* ================= DATABASE ================= */
require_once "../Config/database.php";
require_once __DIR__ . "/../Class/followups.php";
$db = (new Database())->connect();
$followups = new Followups($db);

/* ================= FETCH ORIGINAL CHECKUP DETAILS ================= */
$originalCheckup = null;
$medications = [];
if (isset($_GET['checkup_id'])) {
    $checkup_id = (int)$_GET['checkup_id'];
    $checkupStmt = $db->prepare("SELECT * FROM checkups WHERE checkup_id = ?");
    $checkupStmt->execute([$checkup_id]);
    $originalCheckup = $checkupStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch medications for the original checkup
    if ($originalCheckup) {
        $medStmt = $db->prepare("
            SELECT pm.*, m.generic_name, m.brand_name
            FROM prescribed_medications pm
            INNER JOIN medications m ON pm.medication_id = m.medication_id
            WHERE pm.checkup_id = ?
        ");
        $medStmt->execute([$checkup_id]);
        $medications = $medStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ================= FETCH PATIENT DETAILS ================= */
$patient = null;
if (isset($_GET['patient_id'])) {
    $patient_id = (int)$_GET['patient_id'];
    $patientStmt = $db->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $patientStmt->execute([$patient_id]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
}

/* ================= HANDLE FORM SUBMISSION (CREATE FOLLOW-UP) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['followup_date'])) {
    $data = [
        'patient_id' => (int)$_POST['patient_id'],
        'doc_id' => $_SESSION['doc_id'] ?? 1, // Assuming doc_id in session
        'checkup_id' => !empty($_POST['checkup_id']) ? (int)$_POST['checkup_id'] : null,
        'followup_date' => $_POST['followup_date'],
        'notes' => $_POST['notes'],
        'status' => $_POST['status'] ?? 'Pending'
    ];

    if ($followups->create($data)) {
        header("Location: doctor_followup_checkup.php?patient_id=" . $patient_id . "&checkup_id=" . ($originalCheckup['checkup_id'] ?? '') . "&success=1");
        exit();
    } else {
        $error = "Failed to save follow-up.";
    }
}

/* ================= SEARCH FOLLOW-UPS ================= */
$searchDate = $_GET['search_followup_date'] ?? '';
$allFollowUps = $followups->getAll();
if ($searchDate) {
    $allFollowUps = array_filter($allFollowUps, function($fu) use ($searchDate) {
        return $fu['followup_date'] === $searchDate;
    });
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
<div id="layoutSidenav_nav"><?php include "../Includes/doctorSidebar.php"; ?></div>
<div id="layoutSidenav_content">
<main class="container-fluid px-4 py-4">

<!-- ================= PAGE TITLE ================= */
<h3 class="mb-4"><i class="fa fa-plus-circle"></i> Create Follow-Up</h3>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success">Follow-up created successfully!</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<!-- ================= ORIGINAL CHECKUP DETAILS ================= -->
<?php if ($originalCheckup): ?>
<div class="card shadow mb-4">
<div class="section-header">
<i class="fa fa-stethoscope me-2"></i> Original Checkup Details (<?= $originalCheckup['checkup_date'] ?>)
</div>
<div class="card-body">
<p><strong>Diagnosis:</strong> <?= htmlspecialchars($originalCheckup['diagnosis']) ?></p>
<p><strong>Chief Complaint:</strong> <?= htmlspecialchars($originalCheckup['chief_complaint']) ?></p>
<p><strong>HPI:</strong> <?= htmlspecialchars($originalCheckup['history_present_illness']) ?></p>
<hr>
<div class="row text-center">
<div class="col">BP<br><strong><?= $originalCheckup['blood_pressure'] ?></strong></div>
<div class="col">RR<br><strong><?= $originalCheckup['respiratory_rate'] ?></strong></div>
<div class="col">WT<br><strong><?= $originalCheckup['weight'] ?></strong></div>
<div class="col">HR<br><strong><?= $originalCheckup['heart_rate'] ?></strong></div>
<div class="col">TEMP<br><strong><?= $originalCheckup['temperature'] ?></strong></div>
</div>
<?php if (!empty($medications)): ?>
<hr>
<h5>Original Medications</h5>
<table class="table table-bordered">
<thead><tr><th>Generic</th><th>Brand</th><th>Dose</th><th>Amount</th><th>Frequency</th><th>Duration</th></tr></thead>
<tbody>
<?php foreach ($medications as $m): ?>
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
<?php endif; ?>

<!-- ================= FOLLOW-UP FORM ================= -->
<div class="card shadow mb-4" style="margin-top: -40px;">
<div class="section-header">
<i class="fa fa-edit me-2"></i> Follow-Up Details
</div>
<div class="card-body">
<form method="POST" action="">
<input type="hidden" name="patient_id" value="<?= $patient['patient_id'] ?? '' ?>">
<input type="hidden" name="checkup_id" value="<?= $originalCheckup['checkup_id'] ?? '' ?>">

<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Follow-Up Date</label>
<input type="date" class="form-control" name="followup_date" value="<?= date('Y-m-d') ?>" required>
</div>
<div class="col-md-6">
<label class="form-label">Patient Name</label>
<input type="text" class="form-control" value="<?= htmlspecialchars($patient['full_name'] ?? '') ?>" readonly>
</div>
</div>

<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Status</label>
<select class="form-select" name="status">
<option>Pending</option>
<option>Completed</option>
<option>Missed</option>
</select>
</div>
</div>

<div class="row g-3">
<div class="col-md-12">
<label class="form-label">Notes</label>
<textarea class="form-control" name="notes" rows="3" placeholder="Additional instructions or notes"></textarea>
</div>
</div>

<div class="mt-4 text-end">
<button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Follow-Up</button>
<a href="doctor_medical_records_management.php?patient_id=<?= $patient['patient_id'] ?>" class="btn btn-secondary">Cancel</a>
</div>
</form>
</div>
</div>

<!-- ================= SEARCH FOLLOW-UPS ================= -->
<div class="card shadow mb-4">
<div class="section-header">
<i class="fa fa-search me-2"></i> Search Follow-Ups
</div>
<div class="card-body">
<form method="GET" action="">
<input type="hidden" name="patient_id" value="<?= $patient['patient_id'] ?? '' ?>">
<input type="hidden" name="checkup_id" value="<?= $originalCheckup['checkup_id'] ?? '' ?>">
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Follow-Up Date</label>
<input type="date" class="form-control" name="search_followup_date" value="<?= htmlspecialchars($searchDate) ?>">
</div>
<div class="col-md-2">
<button class="btn btn-primary w-100"><i class="fa fa-search"></i> Search</button>
</div>
</div>
</form>
</div>
</div>

<!-- ================= FOLLOW-UPS LIST ================= -->
<div class="card shadow">
<div class="section-header">
<i class="fa fa-list me-2"></i> All Follow-Ups<?= $searchDate ? " (Filtered by $searchDate)" : "" ?>
</div>
<div class="card-body table-responsive">
<table class="table table-bordered table-striped align-middle text-center" id="followups-table">
<thead class="table-light">
<tr>
<th>Patient</th>
<th>Follow-Up Date</th>
<th>Related Checkup</th>
<th>Status</th>
<th>Notes</th>
<th>Doctor</th>
</tr>
</thead>
<tbody id="followups-tbody">
<?php if ($allFollowUps): ?>
<?php foreach ($allFollowUps as $fu): ?>
<tr class="followup-row">
<td><?= htmlspecialchars($fu['patient_name']) ?></td>
<td><?= htmlspecialchars($fu['followup_date']) ?></td>
<td><?= $fu['related_checkup_date'] ? htmlspecialchars($fu['related_checkup_date']) : 'N/A' ?></td>
<td>
<?php
$badgeClass = 'bg-secondary';
if ($fu['status'] === 'Pending') $badgeClass = 'bg-warning';
elseif ($fu['status'] === 'Completed') $badgeClass = 'bg-success';
elseif ($fu['status'] === 'Missed') $badgeClass = 'bg-danger';
?>
<span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($fu['status']) ?></span>
</td>
<td><?= htmlspecialchars($fu['notes']) ?></td>
<td><?= htmlspecialchars($fu['doctor_name']) ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr>
<td colspan="6">No follow-ups found<?= $searchDate ? " for $searchDate" : "" ?>.</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<!-- ================= PAGINATION ================= -->
<nav class="mt-4" id="pagination-nav">
<ul class="pagination justify-content-center" id="pagination-list">
<!-- Pagination will be generated by JS -->
</ul>
</nav>

</main>
<?php include "../Includes/footer.html"; ?>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('.followup-row');
    const tbody = document.getElementById('followups-tbody');
    const paginationList = document.getElementById('pagination-list');
    const itemsPerPage = 10;
    let currentPage = 1;
    const totalPages = Math.ceil(rows.length / itemsPerPage);

    function showPage(page) {
        const start = (page - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        rows.forEach((row, index) => {
            row.style.display = (index >= start && index < end) ? '' : 'none';
        });
        updatePagination(page);
    }

    function updatePagination(page) {
        paginationList.innerHTML = '';
        const prevLi = document.createElement('li');
        prevLi.className = `page-item ${page <= 1 ? 'disabled' : ''}`;
        prevLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${page - 1})">Previous</a>`;
        paginationList.appendChild(prevLi);

        for (let i = 1; i <= totalPages; i++) {
            const li = document.createElement('li');
            li.className = `page-item ${i === page ? 'active' : ''}`;
            li.innerHTML = `<a class="page-link" href="#" onclick="changePage(${i})">${i}</a>`;
            paginationList.appendChild(li);
        }

        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${page >= totalPages ? 'disabled' : ''}`;
        nextLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${page + 1})">Next</a>`;
        paginationList.appendChild(nextLi);
    }

    window.changePage = function(page) {
        if (page >= 1 && page <= totalPages) {
            currentPage = page;
            showPage(page);
        }
    };

    if (rows.length > 0) {
        showPage(currentPage);
    }
});
</script>

</body>
</html>