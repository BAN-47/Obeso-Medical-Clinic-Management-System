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
require_once __DIR__ . "/../Class/checkups.php";
require_once __DIR__ . "/../Class/medications.php";
require_once __DIR__ . "/../Class/prescribed_medication.php";

$db = (new Database())->connect();

/* ================= FETCH DOCTORS ================= */
$doctorStmt = $db->query("SELECT doc_id, doc_fullname FROM doctors ORDER BY doc_fullname");
$doctors = $doctorStmt->fetchAll(PDO::FETCH_ASSOC);

$currentDoctorId = $_SESSION['doc_id'] ?? null;
$currentDoctorName = null;
if ($currentDoctorId !== null) {
    foreach ($doctors as $doc) {
        if ($doc['doc_id'] == $currentDoctorId) {
            $currentDoctorName = $doc['doc_fullname'];
            break;
        }
    }
}

$checkupObj = new Checkup($db);
$medObj     = new Medication($db);
$presObj    = new PrescribedMedication($db);

/* ================= SAVE ALL ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    try {
        $db->beginTransaction();

        $patient_id = (int)$_POST['patient_id'];

        $existingCheckup = $checkupObj->exists(
            $patient_id,
            $_POST['checkup_date'],
            $_POST['doc_id'] ?? null,
            $_POST['diagnosis'] ?? null
        );

        if ($existingCheckup) {
            throw new Exception("Duplicate checkup detected for this patient on the same date with the same doctor and diagnosis.");
        }

        $checkup_id = $checkupObj->add(
            $patient_id,
            $_POST['checkup_date'],
            $_POST['doc_id'] ?? null,
            !empty($_POST['chief_complaint']) ? $_POST['chief_complaint'] : null,
            !empty($_POST['history_present_illness']) ? $_POST['history_present_illness'] : null,
            !empty($_POST['diagnosis']) ? $_POST['diagnosis'] : null,
            !empty($_POST['blood_pressure']) ? $_POST['blood_pressure'] : null,
            !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null,
            !empty($_POST['weight']) ? $_POST['weight'] : null,
            !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null,
            !empty($_POST['temperature']) ? $_POST['temperature'] : null,
            !empty($_POST['doc_fullname']) ? $_POST['doc_fullname'] : null
        );

        if (!empty($_POST['generic_name'])) {
            foreach ($_POST['generic_name'] as $i => $generic) {
                if (trim($generic) === '') continue;

                $medObj->add($generic, $_POST['brand_name'][$i] ?? null);
                $med_id = $db->lastInsertId();

                $presObj->add(
                    $checkup_id,
                    $med_id,
                    $_POST['generic_name'][$i],
                    $_POST['brand_name'][$i] ?? null,
                    $_POST['dose'][$i] ?? null,
                    $_POST['amount'][$i] ?? null,
                    $_POST['frequency'][$i] ?? null,
                    $_POST['duration'][$i] ?? null
                );
            }
        }

        $db->commit();
        
        // Trigger AI model retrain in background (non-blocking)
        @file_get_contents('http://127.0.0.1:8000/retrain', false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 5
            ]
        ]));
        
        header("Location: doctor_medical_records_management.php?patient_id={$patient_id}&success=1");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

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

    $pendingStmt = $db->prepare("SELECT * FROM checkups WHERE patient_id = ? AND status = 'pending' ORDER BY checkup_id DESC LIMIT 1");
    $pendingStmt->execute([$pid]);
    $pendingCheckup = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    if ($searchDate) {
        $countCheckupStmt = $db->prepare("SELECT COUNT(*) FROM checkups WHERE patient_id = ? AND checkup_date = ? AND status = 'completed'");
        $countCheckupStmt->execute([$pid, $searchDate]);
        $totalCheckups = $countCheckupStmt->fetchColumn();

        $cstmt = $db->prepare("SELECT * FROM checkups WHERE patient_id = :pid AND checkup_date = :searchDate AND status = 'completed' ORDER BY checkup_date DESC LIMIT :checkupLimit OFFSET :checkupOffset");
        $cstmt->bindValue(':pid', $pid, PDO::PARAM_INT);
        $cstmt->bindValue(':searchDate', $searchDate, PDO::PARAM_STR);
        $cstmt->bindValue(':checkupLimit', $checkupLimit, PDO::PARAM_INT);
        $cstmt->bindValue(':checkupOffset', $checkupOffset, PDO::PARAM_INT);
        $cstmt->execute();
    } else {
        $countCheckupStmt = $db->prepare("SELECT COUNT(*) FROM checkups WHERE patient_id = ? AND status = 'completed'");
        $countCheckupStmt->execute([$pid]);
        $totalCheckups = $countCheckupStmt->fetchColumn();

        $cstmt = $db->prepare("SELECT * FROM checkups WHERE patient_id = :pid AND status = 'completed' ORDER BY checkup_date DESC LIMIT :checkupLimit OFFSET :checkupOffset");
        $cstmt->bindValue(':pid', $pid, PDO::PARAM_INT);
        $cstmt->bindValue(':checkupLimit', $checkupLimit, PDO::PARAM_INT);
        $cstmt->bindValue(':checkupOffset', $checkupOffset, PDO::PARAM_INT);
        $cstmt->execute();
    }

    $checkups = $cstmt->fetchAll(PDO::FETCH_ASSOC);
    $totalCheckupPages = max(1, ceil($totalCheckups / $checkupLimit));

    foreach ($checkups as $i => $c) {
        $mstmt = $db->prepare("SELECT pm.* FROM prescribed_medications pm WHERE pm.checkup_id = ?");
        $mstmt->execute([$c['checkup_id']]);
        $checkups[$i]['medications'] = $mstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$aiPredictionsData = null;
if (!empty($patient)) {
    $sourceCheckup = !empty($pendingCheckup) ? $pendingCheckup : (!empty($checkups) ? $checkups[0] : null);
    if ($sourceCheckup) {
        $aiPredictionsData = [
            'patient_id' => $patient['patient_id'],
            'diagnosis' => $sourceCheckup['diagnosis'] ?? '',
            'chief_complaint' => $sourceCheckup['chief_complaint'] ?? '',
            'history_present_illness' => $sourceCheckup['history_present_illness'] ?? '',
            'blood_pressure' => $sourceCheckup['blood_pressure'] ?? '',
            'respiratory_rate' => $sourceCheckup['respiratory_rate'] ?? '',
            'heart_rate' => $sourceCheckup['heart_rate'] ?? '',
            'temperature' => $sourceCheckup['temperature'] ?? '',
        ];
    }
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
    <style>
        .section-header {
            background: #062e6b;
            color: #fff;
            padding: 12px 18px;
            border-radius: 14px 14px 0 0;
        }

        .folder-card {
            transition: .2s;
        }

        .folder-card:hover {
            transform: translateY(-4px);
        }

        .sb-sidenav .nav-link.active {
            background-color: #062e6bff !important;
            color: #fff !important;
            font-weight: 600;
        }

        .vitals-tile {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 6px;
            text-align: center;
        }

        .vitals-tile .label {
            font-size: 0.75rem;
            color: #6c757d;
            margin-bottom: 4px;
        }

        .vitals-tile .value {
            font-size: 1rem;
            font-weight: 600;
            color: #062e6b;
        }
    </style>
</head>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function addMedication() {
        document.getElementById('medications').insertAdjacentHTML('beforeend', `
    <div class="row g-2 mb-2">
        <div class="col"><input name="generic_name[]" class="form-control" placeholder="Generic"></div>
        <div class="col"><input name="brand_name[]" class="form-control" placeholder="Brand"></div>
        <div class="col"><input name="dose[]" class="form-control" placeholder="Dose"></div>
        <div class="col"><input name="amount[]" class="form-control" placeholder="Amount"></div>
        <div class="col"><input name="frequency[]" class="form-control" placeholder="Frequency"></div>
        <div class="col"><input name="duration[]" class="form-control" placeholder="Duration"></div>
    </div>
    `);
    }

    function fillDoctorFields(select) {
        const selected = select.options[select.selectedIndex];
        document.getElementById('doc_id_input').value = selected.value;
        document.getElementById('doc_fullname_input').value = selected.getAttribute('data-fullname') || '';
    }

    function confirmSave() {
        if (confirm("Are you sure you want to save this record?\n\nThis action is permanent and cannot be undone.")) {
            document.getElementById('newRecordForm').submit();
        }
    }
</script>

<body class="sb-nav-fixed">
    <?php include "../Includes/header.html"; ?>
    <?php include "../Includes/navbar_doctor.html"; ?>

    <div id="layoutSidenav">
        <div id="layoutSidenav_nav"><?php include "../Includes/doctorSidebar.php"; ?></div>
        <div id="layoutSidenav_content">
            <main class="container-fluid px-4 py-4">

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

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
                                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>

                <?php else: ?>

                    <div class="d-flex justify-content-between mb-3">
                        <a href="doctor_medical_records_management.php" class="btn btn-secondary">
                            <i class="fa fa-arrow-left"></i> Back
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRecordModal">
                            <i class="fa-solid fa-plus me-1"></i> Add New Record
                        </button>
                    </div>

                    <!-- ================= NEW RECORD MODAL ================= -->
                    <div class="modal fade" id="newRecordModal" tabindex="-1" aria-labelledby="newRecordModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-xl modal-dialog-scrollable">
                            <div class="modal-content">

                                <div class="modal-header" style="background:#062e6b;">
                                    <h5 class="modal-title text-white" id="newRecordModalLabel">
                                        <i class="fa-solid fa-file-medical me-2"></i> Add New Record
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>

                                <div class="modal-body">
                                    <form method="POST" id="newRecordForm">
                                        <input type="hidden" name="patient_id" value="<?= $patient['patient_id'] ?>">
                                        <input type="hidden" name="save_all" value="1">

                                        <!-- Patient Info (read-only) -->
                                        <div class="card mb-4 shadow-sm">
                                            <div class="section-header"><i class="fa-solid fa-user me-2"></i> Patient Information</div>
                                            <div class="card-body row g-3">
                                                <div class="col-md-6"><label class="form-label text-muted small">Full Name</label><input class="form-control" value="<?= htmlspecialchars($patient['full_name']) ?>" readonly></div>
                                                <div class="col-md-3"><label class="form-label text-muted small">Birthday</label><input class="form-control" value="<?= htmlspecialchars($patient['birthday']) ?>" readonly></div>
                                                <div class="col-md-3"><label class="form-label text-muted small">Age</label><input class="form-control" value="<?= htmlspecialchars($patient['age']) ?>" readonly></div>
                                                <div class="col-md-3"><label class="form-label text-muted small">Sex</label><input class="form-control" value="<?= htmlspecialchars($patient['sex']) ?>" readonly></div>
                                                <div class="col-md-3"><label class="form-label text-muted small">Civil Status</label><input class="form-control" value="<?= htmlspecialchars($patient['civil_status']) ?>" readonly></div>
                                                <div class="col-md-3"><label class="form-label text-muted small">Contact Number</label><input class="form-control" value="<?= htmlspecialchars($patient['contact_number']) ?>" readonly></div>
                                                <div class="col-md-3"><label class="form-label text-muted small">Occupation</label><input class="form-control" value="<?= htmlspecialchars($patient['occupation']) ?>" readonly></div>
                                                <div class="col-md-6"><label class="form-label text-muted small">Contact Person</label><input class="form-control" value="<?= htmlspecialchars($patient['contact_person']) ?>" readonly></div>
                                                <div class="col-md-3"><label class="form-label text-muted small">Contact Person Age</label><input class="form-control" value="<?= htmlspecialchars($patient['contact_person_age']) ?>" readonly></div>
                                                <div class="col-md-3"><label class="form-label text-muted small">Religion</label><input class="form-control" value="<?= htmlspecialchars($patient['religion']) ?>" readonly></div>
                                                <div class="col-12"><label class="form-label text-muted small">Address</label><textarea class="form-control" readonly><?= htmlspecialchars($patient['address']) ?></textarea></div>
                                            </div>
                                        </div>

                                        <!-- Chief Complaint & Vitals (from pending checkup, read-only display in modal) -->
                                        <div class="card mb-4 shadow-sm">
                                            <div class="section-header d-flex justify-content-between align-items-center">
                                                <span><i class="fa-solid fa-heart-pulse me-2"></i> Presenting Complaint &amp; Vitals</span>
                                                <?php if (!empty($pendingCheckup['checkup_date'])): ?>
                                                    <span class="badge bg-light text-dark fs-6">
                                                        </i> Date Recorded: <?= htmlspecialchars($pendingCheckup['checkup_date']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label text-muted small">Chief Complaint</label>
                                                    <textarea name="chief_complaint" class="form-control" readonly><?= htmlspecialchars($pendingCheckup['chief_complaint'] ?? '') ?></textarea>
                                                </div>
                                                <div class="row g-2">
                                                    <div class="col">
                                                        <label class="form-label text-muted small">BP</label>
                                                        <input name="blood_pressure" class="form-control text-center" placeholder="BP" value="<?= htmlspecialchars($pendingCheckup['blood_pressure'] ?? '') ?>">
                                                    </div>
                                                    <div class="col">
                                                        <label class="form-label text-muted small">RR</label>
                                                        <input name="respiratory_rate" class="form-control text-center" placeholder="RR" readonly value="<?= htmlspecialchars($pendingCheckup['respiratory_rate'] ?? '') ?>">
                                                    </div>
                                                    <div class="col">
                                                        <label class="form-label text-muted small">WT</label>
                                                        <input name="weight" class="form-control text-center" placeholder="WT" readonly value="<?= htmlspecialchars($pendingCheckup['weight'] ?? '') ?>">
                                                    </div>
                                                    <div class="col">
                                                        <label class="form-label text-muted small">HR</label>
                                                        <input name="heart_rate" class="form-control text-center" placeholder="HR" readonly value="<?= htmlspecialchars($pendingCheckup['heart_rate'] ?? '') ?>">
                                                    </div>
                                                    <div class="col">
                                                        <label class="form-label text-muted small">TEMP</label>
                                                        <input name="temperature" class="form-control text-center" placeholder="TEMP" readonly value="<?= htmlspecialchars($pendingCheckup['temperature'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Checkup -->
                                        <div class="card mb-4 shadow-sm">
                                            <div class="section-header"><i class="fa-solid fa-stethoscope me-2"></i> Checkup</div>
                                            <div class="card-body">
                                                <div class="row g-3">
                                                    <div class="col-md-4"><label class="form-label text-muted small">Checkup Date</label><input type="date" name="checkup_date" class="form-control" required></div>
                                                    <div class="col-md-8"><label class="form-label text-muted small">Diagnosis</label><input name="diagnosis" class="form-control" placeholder="Diagnosis"></div>
                                                </div>
                                                <div class="mt-3">
                                                    <label class="form-label text-muted small">SOAP Notes (HPI / O / A)</label>
                                                    <textarea
                                                        id="soap_input"
                                                        name="history_present_illness"
                                                        class="form-control"
                                                        rows="10"
                                                        placeholder="HPI only (O, A, P will be auto-handled in system)">
                                                    </textarea>
                                                </div>
                                                <div class="row g-3 mt-3">
                                                    <div class="col-md-10 mt-2">
                                                        <label class="form-label text-muted small">Doctor</label>
                                                        <?php if ($currentDoctorId && $currentDoctorName): ?>
                                                            <input type="text" class="form-control" value="<?= htmlspecialchars($currentDoctorName) ?>" readonly>
                                                            <input type="hidden" name="doc_id" value="<?= htmlspecialchars($currentDoctorId) ?>">
                                                            <input type="hidden" name="doc_fullname" value="<?= htmlspecialchars($currentDoctorName) ?>">
                                                        <?php else: ?>
                                                            <select class="form-select" id="doctorDropdown" onchange="fillDoctorFields(this)" required>
                                                                <option value="">— Select Doctor —</option>
                                                                <?php foreach ($doctors as $doc): ?>
                                                                    <option value="<?= $doc['doc_id'] ?>" data-fullname="<?= htmlspecialchars($doc['doc_fullname']) ?>">
                                                                        <?= htmlspecialchars($doc['doc_fullname']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <input type="hidden" name="doc_id" id="doc_id_input">
                                                            <input type="hidden" name="doc_fullname" id="doc_fullname_input">
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Medications -->

                                    </form>
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" onclick="confirmSave()">
                                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Record
                                    </button>
                                </div>

                            </div>
                        </div>
                    </div>
                    <!-- ================= END MODAL ================= -->

                    <!-- ================= PATIENT INFO DISPLAY ================= -->
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

                    <!-- ================= AI DATA MINING INSIGHTS ================= -->
                    <div class="card shadow mb-4" id="aiInsightsCard" style="display: none;">
                        <div class="section-header">
                            <i class="fa-solid fa-brain me-2"></i> AI Data Mining Insights
                        </div>
                        <div class="card-body" id="aiInsightsBody">
                            <div class="text-center py-4">
                                <i class="fa-solid fa-spinner fa-spin fa-2x text-primary"></i>
                                <p class="mt-3 mb-0">Analyzing patient history and vitals...</p>
                            </div>
                        </div>
                    </div>
                    <div class="card shadow mb-4 border-danger" id="aiInsightsErrorCard" style="display: none;">
                        <div class="section-header bg-danger text-white">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i> AI Data Mining Notice
                        </div>
                        <div class="card-body text-danger" id="aiInsightsErrorBody"></div>
                    </div>

                    <!-- ================= PRESENTING COMPLAINT & VITALS DISPLAY ================= -->
                    <div class="card shadow mb-4">
                        <div class="section-header d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fa-solid fa-heart-pulse me-2"></i> Presenting Complaint &amp; Vitals
                            </span>
                            <?php if (!empty($pendingCheckup['checkup_date'])): ?>
                                <span class="badge bg-light text-dark fs-6">
                                    <i class="fa fa-calendar me-1"></i> Date Recorded: <?= htmlspecialchars($pendingCheckup['checkup_date']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong>Chief Complaint:</strong>
                                <p class="mt-1 mb-0"><?= htmlspecialchars($pendingCheckup['chief_complaint'] ?? '—') ?></p>
                            </div>
                            <hr>
                            <div class="row g-3 text-center" style="margin-top: 5px;">
                                <div class="col">
                                    <div class="vitals-tile">
                                        <div class="label"><strong>Blood Pressure</strong></div>
                                        <div class="value"><?= htmlspecialchars($pendingCheckup['blood_pressure'] ?? '—') ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="vitals-tile">
                                        <div class="label"><strong>Respiratory Rate</strong></div>
                                        <div class="value"><?= htmlspecialchars($pendingCheckup['respiratory_rate'] ?? '—') ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="vitals-tile">
                                        <div class="label"><strong>Weight</strong></div>
                                        <div class="value"><?= htmlspecialchars($pendingCheckup['weight'] ?? '—') ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="vitals-tile">
                                        <div class="label"><strong>Heart Rate</strong></div>
                                        <div class="value"><?= htmlspecialchars($pendingCheckup['heart_rate'] ?? '—') ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="vitals-tile">
                                        <div class="label"><strong>Temperature</strong></div>
                                        <div class="value"><?= htmlspecialchars($pendingCheckup['temperature'] ?? '—') ?></div>
                                    </div>
                                </div>
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

                    <?php if (!empty($checkups)): ?>
                        <?php foreach ($checkups as $c): ?>
                            <div class="card shadow mb-4">
                                <div class="section-header">
                                    <i class="fa fa-stethoscope me-2"></i>
                                    Checkup — <?= $c['checkup_date'] ?> (Doctor: <?= htmlspecialchars($c['doc_fullname']) ?>)
                                </div>
                                <div class="card-body">
                                    <p><strong>Diagnosis:</strong> <?= htmlspecialchars($c['diagnosis']) ?></p>
                                    <p><strong>Chief Complaint:</strong> <?= htmlspecialchars($c['chief_complaint']) ?></p>
                                    <p><strong>HPI:</strong><div class="bg-light p-3 rounded border"><?= nl2br(htmlspecialchars($c['history_present_illness'] ?? 'No HPI recorded.')) ?></div></p>

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
                                                    <th>Generic</th>
                                                    <th>Brand</th>
                                                    <th>Dose</th>
                                                    <th>Amount</th>
                                                    <th>Frequency</th>
                                                    <th>Duration</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($c['medications'] as $m): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($m['pres_generic_name']) ?></td>
                                                        <td><?= htmlspecialchars($m['pres_brand_name']) ?></td>
                                                        <td><?= htmlspecialchars($m['dose']) ?></td>
                                                        <td><?= htmlspecialchars($m['amount']) ?></td>
                                                        <td><?= htmlspecialchars($m['frequency']) ?></td>
                                                        <td><?= htmlspecialchars($m['duration']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>

                                    <div class="mt-3">
                                        <a href="export_prescription.php?checkup_id=<?= $c['checkup_id'] ?>" class="btn btn-danger">
                                            <i class="fa fa-file-pdf"></i> Export Prescription PDF
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- ================= CHECKUP PAGINATION ================= -->
                            <nav class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?= ($checkupPage <= 1) ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?patient_id=<?= $patient['patient_id'] ?>&checkup_date=<?= urlencode($searchDate) ?>&checkup_page=<?= $checkupPage - 1 ?>">Previous</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $totalCheckupPages; $i++): ?>
                                        <li class="page-item <?= ($i == $checkupPage) ? 'active' : '' ?>">
                                            <a class="page-link" href="?patient_id=<?= $patient['patient_id'] ?>&checkup_date=<?= urlencode($searchDate) ?>&checkup_page=<?= $i ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= ($checkupPage >= $totalCheckupPages) ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?patient_id=<?= $patient['patient_id'] ?>&checkup_date=<?= urlencode($searchDate) ?>&checkup_page=<?= $checkupPage + 1 ?>">Next</a>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../Public/autoFormatType.js"></script>
    <script>
        const AI_PREDICTION_PAYLOAD = <?= json_encode($aiPredictionsData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
        const AI_API_URL = 'http://127.0.0.1:8000/predict';

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function setAiInsights(html) {
            const card = document.getElementById('aiInsightsCard');
            const body = document.getElementById('aiInsightsBody');
            if (!card || !body) return;
            body.innerHTML = html;
            card.style.display = 'block';
        }

        function setAiError(message) {
            const card = document.getElementById('aiInsightsErrorCard');
            const body = document.getElementById('aiInsightsErrorBody');
            if (!card || !body) return;
            body.textContent = message;
            card.style.display = 'block';
        }

        async function loadAiInsights() {
            if (!AI_PREDICTION_PAYLOAD || Object.keys(AI_PREDICTION_PAYLOAD).length === 0) {
                return;
            }

            try {
                const response = await fetch(AI_API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(AI_PREDICTION_PAYLOAD)
                });

                const data = await response.json();
                if (!response.ok || data.error) {
                    throw new Error(data.error || 'Unable to retrieve AI insights.');
                }

                const top3 = data.top3 || [];
                const followup = data.followup || {};
                const history = data.history_used
                    ? `Based on ${escapeHtml(data.past_checkups)} prior completed checkup(s).`
                    : 'Based on current patient symptoms and available history.';

                const top3Html = top3.length
                    ? `<ul class="mb-0 ps-3">${top3.map(i => `<li>${escapeHtml(i.disease)} — ${escapeHtml(i.confidence)}%</li>`).join('')}</ul>`
                    : '<div class="text-muted">No top predictions available.</div>';

                const actionsHtml = followup.actions && followup.actions.length
                    ? `<div class="mt-2"><strong>Action items:</strong><ul class="mb-0 ps-3">${followup.actions.map(a => `<li>${escapeHtml(a)}</li>`).join('')}</ul></div>`
                    : '';

                const testsHtml = followup.tests && followup.tests.length
                    ? `<div class="mt-2"><strong>Suggested tests:</strong><ul class="mb-0 ps-3">${followup.tests.map(t => `<li>${escapeHtml(t)}</li>`).join('')}</ul></div>`
                    : '';

                setAiInsights(`
                    <div class="row gx-3 gy-3">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded h-100">
                                <strong>Predicted Diagnosis</strong>
                                <div class="fs-4 fw-bold mt-2">${escapeHtml(data.current_diagnosis || data.disease || 'Unknown')}</div>
                                <div class="text-muted">Confidence: ${escapeHtml(data.confidence?.toFixed?.(1) ?? data.confidence ?? 0)}%</div>
                                ${data.supporting_evidence && data.supporting_evidence.length ? `<div class="mt-2 text-muted"><small>Evidence: ${escapeHtml(data.supporting_evidence.join(', '))}</small></div>` : ''}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded h-100">
                                <strong>Top likely diagnoses</strong>
                                ${top3Html}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded h-100">
                                <strong>Follow-up recommendation</strong>
                                <div class="mt-2">${followup.urgent ? '<span class="badge bg-danger">Urgent</span>' : '<span class="badge bg-success">Standard</span>'}</div>
                                <div class="mt-2">Follow up in ${escapeHtml(followup.days ?? 7)} day(s).</div>
                                ${actionsHtml}
                                ${testsHtml}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-muted">${history}</div>
                `);
            } catch (err) {
                setAiError(err.message || 'AI service is unavailable.');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadAiInsights();
            
            // If page just redirected after save, show success and refresh AI predictions with new data
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('success') === '1') {
                // Show success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show';
                alertDiv.innerHTML = `
                    <strong>Success!</strong> Checkup record saved. AI is analyzing with new patient data...
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                const main = document.querySelector('main');
                if (main) main.insertBefore(alertDiv, main.firstChild);
                
                // Wait 3 seconds for model retrain, then refresh AI predictions
                setTimeout(function() {
                    loadAiInsights();
                }, 3000);
                
                // Clean up URL (remove success param)
                window.history.replaceState({}, document.title, window.location.pathname + window.location.search.replace(/[?&]success=1/, ''));
            }
        });
    </script>
</body>
</html>