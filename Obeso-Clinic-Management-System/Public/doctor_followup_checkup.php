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
require_once __DIR__ . "/../Class/Prediction.php";
$db = (new Database())->connect();
$followups = new Followups($db);
$predictor = new Prediction();

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

/* ================= SEARCH PATIENTS (AJAX) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search_patients'])) {
    header('Content-Type: application/json');
    $name = trim($_GET['search_patients']);
    $stmt = $db->prepare("SELECT patient_id, full_name FROM patients WHERE full_name LIKE ? LIMIT 10");
    $stmt->execute(["%$name%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

/* ================= FETCH PATIENT DETAILS ================= */
$patient = null;
if (isset($_GET['patient_id'])) {
    $patient_id = (int)$_GET['patient_id'];
    $patientStmt = $db->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $patientStmt->execute([$patient_id]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
}

/* ================= FETCH PAST CHECKUPS ================= */
$pastCheckups = [];
$topFutureIllnesses = [];
if ($patient && isset($patient['patient_id'])) {
    // Get past checkups
    $pastStmt = $db->prepare("
        SELECT checkup_id, checkup_date, diagnosis, temperature, blood_pressure, heart_rate, respiratory_rate
        FROM checkups
        WHERE patient_id = ?
        ORDER BY checkup_date DESC
        LIMIT 10
    ");
    $pastStmt->execute([$patient['patient_id']]);
    $pastCheckups = $pastStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get top 3 future illnesses from AI
    try {
        $ch = curl_init('http://127.0.0.1:8000/future-illnesses');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['patient_id' => $patient['patient_id']]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response && $httpCode === 200) {
            $aiData = json_decode($response, true);
            if (isset($aiData['future_illnesses'])) {
                $topFutureIllnesses = $aiData['future_illnesses'];
            }
        }
    } catch (Exception $e) {
        // Silently fail - AI service might not be running
    }
}

$predictionInsight = null;
if ($originalCheckup) {
    $predictionInsight = $predictor->predictFromCheckup($originalCheckup);
}

/* ================= HANDLE FORM SUBMISSION (CREATE FOLLOW-UP) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['followup_date'])) {
    $patient_id = (int)$_POST['patient_id']; // grab it first

    if ($patient_id <= 0) {
        $error = "Please select a valid patient.";
    } else {
        $data = [
            'patient_id' => $patient_id,
            'doc_id'     => $_SESSION['doc_id'] ?? 1,
            'checkup_id' => !empty($_POST['checkup_id']) ? (int)$_POST['checkup_id'] : null,
            'followup_date' => $_POST['followup_date'],
            'notes'      => $_POST['notes'],
            'status'     => $_POST['status'] ?? 'Pending'
        ];

        if ($followups->create($data)) {
            header("Location: doctor_followup_checkup.php?patient_id=" . $patient_id . "&checkup_id=" . ($originalCheckup['checkup_id'] ?? '') . "&success=1");
            exit();
        } else {
            $error = "Failed to save follow-up.";
        }
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
<script>
window.addEventListener('load', function() {
    Swal.fire({
        icon: 'success',
        title: 'Follow-Up Saved!',
        text: 'The follow-up has been created successfully.',
        confirmButtonColor: '#062e6b',
        confirmButtonText: 'OK'
    });
});
</script>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<!-- ================= ORIGINAL CHECKUP DETAILS ================= -->
<?php if ($originalCheckup): ?>
    <!-- PATIENT PREDICTION INSIGHT -->
    <?php if ($predictionInsight): ?>
        <div class="card shadow mb-4 border-start border-4 border-warning">
            <div class="card-body">
                <?php if (!empty($predictionInsight['error'])): ?>
                    <div class="alert alert-warning mb-0">
                        <strong>AI Insight:</strong> <?= htmlspecialchars($predictionInsight['message']) ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <h5 class="mb-2"><i class="fa fa-lightbulb"></i> Predicted future illness</h5>
                        <p class="mb-1">
                            <strong><?= htmlspecialchars($predictionInsight['disease']) ?></strong>
                            <span class="badge bg-secondary ms-2"><?= round($predictionInsight['confidence'], 1) ?>% confidence</span>
                        </p>
                        <?php if (!empty($predictionInsight['top3'])): ?>
                            <small class="text-muted">Top possible outcomes:</small>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($predictionInsight['top3'] as $item): ?>
                                    <li><?= htmlspecialchars($item['disease']) ?> (<?= round($item['confidence'], 1) ?>%)</li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if (!empty($predictionInsight['future_outcome'])): ?>
                            <div class="mt-3 p-3 bg-light rounded-3">
                                <strong>Future outcome risk:</strong>
                                <span class="badge bg-<?= $predictionInsight['future_outcome']['risk_level'] === 'High' ? 'danger' : ($predictionInsight['future_outcome']['risk_level'] === 'Moderate' ? 'warning text-dark' : 'success') ?>">
                                    <?= htmlspecialchars($predictionInsight['future_outcome']['risk_level']) ?>
                                </span>
                                <p class="mb-1 mt-2"><?= htmlspecialchars($predictionInsight['future_outcome']['summary']) ?></p>
                                <p class="text-muted small mb-0">
                                    <strong>Recommendation:</strong> <?= htmlspecialchars($predictionInsight['future_outcome']['recommendation']) ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <p class="text-muted small mb-0">This insight is generated from the patient’s past records and current checkup data.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
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

<!-- ================= PAST MEDICAL HISTORY ================= -->
<?php if (!empty($pastCheckups)): ?>
<div class="card shadow mb-4">
<div class="section-header">
<i class="fa fa-history me-2"></i> Past Medical History (Last 10 Checkups)
</div>
<div class="card-body">
<div class="table-responsive">
<table class="table table-striped table-hover">
<thead class="table-light">
<tr>
<th>Date</th>
<th>Diagnosis</th>
<th>Temp (°C)</th>
<th>BP</th>
<th>HR</th>
<th>RR</th>
</tr>
</thead>
<tbody>
<?php foreach ($pastCheckups as $checkup): ?>
<tr>
<td><?= htmlspecialchars($checkup['checkup_date']) ?></td>
<td><span class="badge bg-info text-dark"><?= htmlspecialchars($checkup['diagnosis']) ?></span></td>
<td><?= htmlspecialchars($checkup['temperature']) ?></td>
<td><?= htmlspecialchars($checkup['blood_pressure']) ?></td>
<td><?= htmlspecialchars($checkup['heart_rate']) ?></td>
<td><?= htmlspecialchars($checkup['respiratory_rate']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>
<?php endif; ?>

<!-- ================= TOP 3 FUTURE ILLNESSES ================= -->
<?php if (!empty($topFutureIllnesses)): ?>
<div class="card shadow mb-4 border-start border-4 border-success">
<div class="section-header" style="background: #28a745;">
<i class="fa fa-crystal-ball me-2"></i> AI: Top 3 Future Illnesses (Based on History)
</div>
<div class="card-body">
<div class="row">
<?php foreach ($topFutureIllnesses as $index => $illness): ?>
<div class="col-md-4 mb-3">
<div class="card border-0 bg-light">
<div class="card-body">
<h6 class="card-title">
<span class="badge bg-success">#<?= $index + 1 ?></span>
<?= htmlspecialchars($illness['disease']) ?>
</h6>
<p class="mb-2">
<strong>Likelihood:</strong> 
<div class="progress" style="height: 20px;">
<div class="progress-bar" role="progressbar" style="width: <?= $illness['likelihood'] ?>%;" aria-valuenow="<?= $illness['likelihood'] ?>" aria-valuemin="0" aria-valuemax="100">
<?= round($illness['likelihood'], 1) ?>%
</div>
</div>
</p>
<p class="small text-muted mb-0"><strong>Reason:</strong> <?= htmlspecialchars($illness['reason']) ?></p>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<p class="text-muted small mb-0"><i class="fa fa-info-circle"></i> This prediction is based on the patient's medical history and common disease patterns.</p>
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
<input type="hidden" name="checkup_id" value="<?= $originalCheckup['checkup_id'] ?? '' ?>">

<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Follow-Up Date</label>
<input type="date" class="form-control" name="followup_date" value="<?= date('Y-m-d') ?>" required>
</div>
<div class="col-md-6">
    <label class="form-label">Patient Name</label>
    <div class="position-relative">
        <input type="text" class="form-control" id="patientSearchInput"
               placeholder="Search patient..."
               value="<?= htmlspecialchars($patient['full_name'] ?? '') ?>"
               autocomplete="off"
               oninput="searchPatients(this.value)">
        <div id="patientDropdown" style="
            display:none;
            position:absolute;
            z-index:9999;
            width:100%;
            background:#fff;
            border:1px solid #c8d6ec;
            border-radius:0 0 10px 10px;
            box-shadow:0 6px 18px rgba(6,46,107,.12);
            max-height:220px;
            overflow-y:auto;">
        </div>
    </div>
    <input type="hidden" name="patient_id" id="selectedPatientId"
       value="<?= $patient['patient_id'] ?? '' ?>">
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
    <button type="submit" class="btn btn-primary" id="saveFollowUpBtn">
        <i class="fa fa-save"></i> Save Follow-Up
    </button>
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
let patientSearchTimer;
window._patientList = [];

function searchPatients(val) {
    clearTimeout(patientSearchTimer);
    const dropdown = document.getElementById('patientDropdown');
    if (val.trim().length < 2) {
        dropdown.style.display = 'none';
        return;
    }
    patientSearchTimer = setTimeout(() => {
        fetch(window.location.pathname + '?search_patients=' + encodeURIComponent(val.trim()))
            .then(r => r.json())
            .then(data => {
                window._patientList = data;
                if (!data.length) {
                    dropdown.innerHTML = `<div style="padding:12px 16px;color:#888;font-style:italic;text-align:center;">
                        <i class="fa fa-circle-xmark me-1 text-danger"></i>No patient found.</div>`;
                } else {
                    dropdown.innerHTML = data.map((p, i) => `
                        <div onclick="selectPatient(${i})"
                             style="padding:10px 16px;cursor:pointer;border-bottom:1px solid #eef2fa;
                                    font-size:.93rem;transition:background .13s;"
                             onmouseover="this.style.background='#eef2fa'"
                             onmouseout="this.style.background='#fff'">
                            <strong style="color:#062e6b;">${p.full_name}</strong>
                        </div>`).join('');
                }
                dropdown.style.display = 'block';
            })
            .catch(() => {
                dropdown.innerHTML = `<div style="padding:12px 16px;color:red;text-align:center;">
                    <i class="fa fa-triangle-exclamation me-1"></i>Search error.</div>`;
                dropdown.style.display = 'block';
            });
    }, 280);
}

function selectPatient(index) {
    const p = window._patientList[index];
    if (!p) return;
    document.getElementById('patientSearchInput').value  = p.full_name;
    document.getElementById('selectedPatientId').value   = p.patient_id;
    document.getElementById('patientDropdown').style.display = 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', e => {
    if (!e.target.closest('#patientSearchInput') && !e.target.closest('#patientDropdown')) {
        document.getElementById('patientDropdown').style.display = 'none';
    }
});

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
        const prevA = document.createElement('a');
        prevA.className = 'page-link';
        prevA.href = '#';
        prevA.textContent = 'Previous';
        prevA.addEventListener('click', function(e) {
            e.preventDefault();
            if (page > 1) { currentPage = page - 1; showPage(currentPage); }
        });
        prevLi.appendChild(prevA);
        paginationList.appendChild(prevLi);

        for (let i = 1; i <= totalPages; i++) {
            const li = document.createElement('li');
            li.className = `page-item ${i === page ? 'active' : ''}`;
            const a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            a.textContent = i;
            const pageNum = i;
            a.addEventListener('click', function(e) {
                e.preventDefault();
                currentPage = pageNum;
                showPage(currentPage);
            });
            li.appendChild(a);
            paginationList.appendChild(li);
        }

        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${page >= totalPages ? 'disabled' : ''}`;
        const nextA = document.createElement('a');
        nextA.className = 'page-link';
        nextA.href = '#';
        nextA.textContent = 'Next';
        nextA.addEventListener('click', function(e) {
            e.preventDefault();
            if (page < totalPages) { currentPage = page + 1; showPage(currentPage); }
        });
        nextLi.appendChild(nextA);
        paginationList.appendChild(nextLi);
    }

    if (rows.length > 0) {
        showPage(currentPage);
    }
});

document.querySelector('form[method="POST"]').addEventListener('submit', function(e) {
    const patientId = document.getElementById('selectedPatientId').value;
    if (!patientId || patientId == 0) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'No Patient Selected',
            text: 'Please search and select a patient before saving.',
            confirmButtonColor: '#062e6b'
        });
        return;
    }
});
</script>

</body>
</html>