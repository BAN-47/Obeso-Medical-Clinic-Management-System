<?php
session_start();

/* ================= ACCESS CONTROL ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: access_denied.php");
    exit();
}

/* ================= DATABASE ================= */
require_once "../Config/database.php";

$db = (new Database())->connect();

/* ================= DAILY SLOT COUNT ================= */
$today = date('Y-m-d');
$slotStmt = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(created_at) = ?");
$slotStmt->execute([$today]);
$slotsUsed  = (int)$slotStmt->fetchColumn();
$slotsTotal = 50;
$slotsLeft  = max(0, $slotsTotal - $slotsUsed);
$slotsFull  = $slotsLeft === 0;

/* ================= SEARCH OLD PATIENT (AJAX) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search_patient'])) {
    header('Content-Type: application/json');
    $name = trim($_GET['search_patient']);
    $stmt = $db->prepare("SELECT * FROM patients WHERE full_name LIKE ? LIMIT 10");
    $stmt->execute(["%$name%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
    exit();
}

/* ================= QUEUE OLD PATIENT (AJAX) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['queue_old_patient'])) {
    header('Content-Type: application/json');
    try {
        $patient_id = (int)$_POST['patient_id'];
        $today = date('Y-m-d');

        $check = $db->prepare("SELECT queue_id FROM queue WHERE patient_id = ? AND DATE(created_at) = ?");
        $check->execute([$patient_id, $today]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'This patient is already in the queue today.']);
            exit();
        }

        $slotCheck = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(created_at) = ?");
        $slotCheck->execute([$today]);
        if ((int)$slotCheck->fetchColumn() >= 50) {
            echo json_encode(['success' => false, 'error' => 'Sorry, the 50-patient limit for today has been reached.']);
            exit();
        }

        $qStmt = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(created_at) = ?");
        $qStmt->execute([$today]);
        $qCount = (int)$qStmt->fetchColumn() + 1;
        $queueNumber = 'Q-' . str_pad($qCount, 3, '0', STR_PAD_LEFT);

        // INSERT PENDING CHECKUP WITH VITALS
        $cInsert = $db->prepare(
            "INSERT INTO checkups 
             (patient_id, doc_id, doc_fullname, checkup_date, chief_complaint,
              blood_pressure, respiratory_rate, weight, heart_rate, temperature, status)
             VALUES (?, 1, 'Pending Assignment', ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        $cInsert->execute([
            $patient_id,
            $today,
            !empty($_POST['chief_complaint'])   ? $_POST['chief_complaint']        : null,
            !empty($_POST['blood_pressure'])    ? $_POST['blood_pressure']         : null,
            !empty($_POST['respiratory_rate'])  ? (int)$_POST['respiratory_rate']  : null,
            !empty($_POST['weight'])            ? $_POST['weight']                 : null,
            !empty($_POST['heart_rate'])        ? (int)$_POST['heart_rate']        : null,
            !empty($_POST['temperature'])       ? $_POST['temperature']            : null,
        ]);

        $qInsert = $db->prepare("INSERT INTO queue (patient_id, queue_number) VALUES (?, ?)");
        $qInsert->execute([$patient_id, $queueNumber]);

        echo json_encode(['success' => true, 'queue_number' => $queueNumber]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

/* ================= SAVE NEW PATIENT (AJAX JSON) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    header('Content-Type: application/json');
    try {
        $db->beginTransaction();

        // 1. Check slot limit
        $slotCheck = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(created_at) = ?");
        $slotCheck->execute([date('Y-m-d')]);
        if ((int)$slotCheck->fetchColumn() >= 50) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Sorry, the 50-patient limit for today has been reached.']);
            exit();
        }

        // 2. Find or create patient
        $stmt = $db->prepare("SELECT patient_id FROM patients WHERE full_name = ? AND birthday = ? LIMIT 1");
        $stmt->execute([$_POST['full_name'], $_POST['birthday']]);
        $existingPatient = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingPatient) {
            $patient_id = $existingPatient['patient_id'];
        } else {
            $stmt = $db->prepare(
                "INSERT INTO patients
                 (full_name, address, birthday, age, sex, civil_status, religion, occupation,
                  contact_person, contact_person_age, contact_number)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $_POST['full_name'],
                $_POST['address']            ?? null,
                $_POST['birthday'],
                $_POST['age'],
                $_POST['sex'],
                $_POST['civil_status']       ?? null,
                $_POST['religion']           ?? null,
                $_POST['occupation']         ?? null,
                $_POST['contact_person']     ?? null,
                $_POST['contact_person_age'] ?? null,
                $_POST['contact_number'],
            ]);
            $patient_id = $db->lastInsertId();
        }

       /* ================= STEP 3: SAVE TO CHECKUPS & QUEUE ================= */
        $today = date('Y-m-d');
        
        // 1. Generate the Queue Number
        $qStmt = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(created_at) = ?");
        $qStmt->execute([$today]);
        $qCount      = (int)$qStmt->fetchColumn() + 1;
        $queueNumber = 'Q-' . str_pad($qCount, 3, '0', STR_PAD_LEFT);

        // 2. Insert vitals into checkups as pending (doctor will complete later)
        $cInsert = $db->prepare(
            "INSERT INTO checkups 
             (patient_id, doc_id, doc_fullname, checkup_date, chief_complaint, 
              blood_pressure, respiratory_rate, weight, heart_rate, temperature, status) 
             VALUES (?, 1, 'Pending Assignment', ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        $cInsert->execute([
            $patient_id,
            $today,
            !empty($_POST['chief_complaint']) ? $_POST['chief_complaint'] : null,
            !empty($_POST['blood_pressure']) ? $_POST['blood_pressure'] : null,
            !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null,
            !empty($_POST['weight']) ? $_POST['weight'] : null,
            !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null,
            !empty($_POST['temperature']) ? $_POST['temperature'] : null,
        ]);

        // 3. Insert into QUEUE table
        $qInsert = $db->prepare(
            "INSERT INTO queue (patient_id, queue_number) VALUES (?, ?)"
        );
        $qInsert->execute([
            $patient_id,
            $queueNumber
        ]);

        $db->commit();

        echo json_encode([
            'success'      => true,
            'patient_id'   => $patient_id,
            'queue_number' => $queueNumber,
        ]);
        exit();

    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Obeso's Clinic Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
<link href="../Includes/sidebarStyle.css" rel="stylesheet">

<style>
.section-card { border-radius: 14px; }
.section-header {
    background: #062e6b;
    color: #fff;
    padding: 12px 18px;
    border-radius: 14px 14px 0 0;
}
.sb-sidenav .nav-link.active {
    background-color: #062e6bff !important;
    color: #fff !important;
    font-weight: 600;
}
.mode-toggle {
    display: flex;
    gap: 0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(6,46,107,.18);
    width: fit-content;
}
.mode-btn {
    padding: 10px 28px;
    font-weight: 600;
    font-size: .97rem;
    border: 2px solid #062e6b;
    background: #fff;
    color: #062e6b;
    cursor: pointer;
    transition: background .18s, color .18s, box-shadow .18s;
    outline: none;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.mode-btn:first-child { border-radius: 12px 0 0 12px; border-right: 1px solid #062e6b; }
.mode-btn:last-child  { border-radius: 0 12px 12px 0; border-left: 1px solid #062e6b; }
.mode-btn.active {
    background: #062e6b;
    color: #fff;
    box-shadow: 0 2px 8px rgba(6,46,107,.22);
}
.mode-btn:not(.active):hover { background: #e8eef8; }
.panel { display: none; }
.panel.active { display: block; }
.search-wrap { position: relative; }
.search-wrap input { padding-right: 44px; }
.search-wrap .search-icon {
    position: absolute; right: 14px; top: 50%;
    transform: translateY(-50%);
    color: #062e6b; pointer-events: none;
}
#searchResults {
    position: absolute;
    z-index: 9999;
    width: 100%;
    background: #fff;
    border: 1px solid #c8d6ec;
    border-radius: 0 0 10px 10px;
    box-shadow: 0 6px 18px rgba(6,46,107,.12);
    max-height: 260px;
    overflow-y: auto;
    display: none;
}
#searchResults .result-item {
    padding: 11px 16px;
    cursor: pointer;
    border-bottom: 1px solid #eef2fa;
    transition: background .13s;
}
#searchResults .result-item:last-child { border-bottom: none; }
#searchResults .result-item:hover { background: #eef2fa; }
#searchResults .result-item .name { font-weight: 600; color: #062e6b; }
#searchResults .result-item .meta { font-size: .82rem; color: #6c757d; }
#searchResults .no-result {
    padding: 14px 16px;
    color: #888;
    font-style: italic;
    text-align: center;
}
#patientInfoCard {
    display: none;
    animation: fadeSlideIn .25s ease;
}
@keyframes fadeSlideIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.pi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px 16px;
    padding: 16px 18px;
}
.pi-field { font-size: .93rem; color: #1a2a45; }
.pi-field .pi-lbl { font-weight: 700; color: #1a2a45; }
.pi-field .pi-val { font-weight: 400; }
.pi-full { grid-column: 1 / -1; }
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(6,46,107,.45);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    animation: fadeOverlay .2s ease;
}
.modal-overlay.show { display: flex; }
@keyframes fadeOverlay {
    from { opacity: 0; } to { opacity: 1; }
}
.modal-box {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 16px 48px rgba(6,46,107,.22);
    padding: 36px 40px 30px;
    max-width: 420px;
    width: 90%;
    text-align: center;
    animation: slideUp .22s ease;
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
}
.modal-box h5 { font-weight: 700; color: #062e6b; margin-bottom: 8px; }
.modal-box p  { color: #5a6a82; font-size: .96rem; margin-bottom: 24px; }
.modal-actions { display: flex; gap: 12px; justify-content: center; }
.modal-actions .btn { min-width: 110px; border-radius: 10px; font-weight: 600; }
.queue-number {
    font-size: 5rem;
    font-weight: 900;
    color: #062e6b;
    line-height: 1;
    letter-spacing: -2px;
}
.queue-badge {
    background: #e8f0fe;
    border-radius: 12px;
    padding: 18px 24px;
    margin: 10px 0 20px;
    display: inline-block;
}
.slots-badge {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #f0f7ff;
    border: 2px solid #c8d6ec;
    border-radius: 14px;
    padding: 10px 20px;
    min-width: 200px;
}
.slots-badge.slots-low {
    background: #fff8e1;
    border-color: #f9a825;
}
.slots-badge.slots-full {
    background: #fff0f0;
    border-color: #dc3545;
}
.slots-icon {
    font-size: 1.6rem;
    color: #062e6b;
    line-height: 1;
}
.slots-badge.slots-low  .slots-icon { color: #f9a825; }
.slots-badge.slots-full .slots-icon { color: #dc3545; }
.slots-count {
    font-size: 1.1rem;
    font-weight: 800;
    color: #062e6b;
    line-height: 1.2;
}
.slots-badge.slots-low  .slots-count { color: #e65100; }
.slots-badge.slots-full .slots-count { color: #dc3545; }
.full-day-banner {
    background: #fff0f0;
    border: 2px solid #dc3545;
    border-radius: 12px;
    padding: 18px 22px;
    color: #dc3545;
    font-weight: 700;
    font-size: 1.05rem;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}
</style>
</head>

<body class="sb-nav-fixed">

<?php include "../Includes/header.html"; ?>
<?php include "../Includes/navbar_staff.html"; ?>

<div id="layoutSidenav">
<div id="layoutSidenav_nav"><?php include "../Includes/staffSidebar.php"; ?></div>

<div id="layoutSidenav_content">

<!-- Confirmation Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <h5 style="font-size: 1.25rem; font-weight: 700;">Queue this Patient?</h5>
        <p>Are you sure you want to save and add this patient to the queue?</p>
        <div class="modal-actions">
            <button class="btn btn-outline-secondary" onclick="closeConfirmModal()">
                <i class="fa-solid fa-xmark me-1"></i> No, Cancel
            </button>
            <button class="btn btn-primary" onclick="confirmQueue()">
                <i class="fa-solid fa-check me-1"></i> Yes, Queue
            </button>
        </div>
    </div>
</div>

<!-- Queue Number Modal -->
<div class="modal-overlay" id="queueModal">
    <div class="modal-box">
        <h5>Patient Queued!</h5>
        <p>The patient has been saved. Here is their queue number:</p>
        <div class="queue-badge">
            <div class="queue-number" id="queueNumberDisplay">—</div>
        </div>
        <p class="text-muted" style="font-size:.85rem;">Please inform the patient of this number.</p>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="closeQueueModal()">
                <i class="fa-solid fa-door-open me-1"></i>Done
            </button>
        </div>
    </div>
</div>

<main class="container-fluid px-4 py-4">

<?php if ($slotsFull): ?>
<div class="full-day-banner">
    <i class="fa-solid fa-ban fa-lg"></i>
    <span>Today's queue is full (<?= $slotsTotal ?> / <?= $slotsTotal ?> patients). No more patients can be queued today.</span>
</div>
<?php endif; ?>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success">Saved successfully!</div>
<?php endif; ?>

<!-- MODE TOGGLE -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div class="mode-toggle">
        <button class="mode-btn active" id="btnNew" onclick="switchMode('new')">
            <i class="fa-solid fa-user-plus"></i> New Patient
        </button>
        <button class="mode-btn" id="btnOld" onclick="switchMode('old')">
            <i class="fa-solid fa-magnifying-glass"></i> Old Patient
        </button>
    </div>

    <div class="slots-badge <?= $slotsFull ? 'slots-full' : ($slotsLeft <= 10 ? 'slots-low' : '') ?>">
        <?php if ($slotsFull): ?>
            <i class="fa-solid fa-ban me-2"></i>
            <span>Today's queue is full. No more patients can be added (<?= date('F j, Y') ?>).</span>
        <?php else: ?>
            <span>Only <strong><span id="slotsLeftDisplay"><?= $slotsLeft ?></span></strong> patient slot<?= $slotsLeft !== 1 ? 's' : '' ?> left for today (<?= date('F j, Y') ?>).</span>
        <?php endif; ?>
    </div>
</div>

<!-- NEW PATIENT PANEL -->
<div class="panel active" id="panelNew">
    <form method="POST">
        <input type="hidden" name="patient_id" id="patient_id">

        <div class="card section-card mb-4 shadow-sm">
            <div class="section-header">
                <i class="fa-solid fa-user me-2"></i> Patient Information
            </div>
            <div class="card-body row g-3">
                <div class="col-md-6"><input name="full_name" class="form-control" placeholder="Full Name" required></div>
                <div class="col-md-3"><input type="date" name="birthday" class="form-control" placeholder="Birthday" required></div>
                <div class="col-md-3"><input type="number" name="age" class="form-control" placeholder="Age" required></div>

                <div class="col-md-3">
                    <select name="sex" class="form-select" required>
                        <option value="">Sex</option>
                        <option>Male</option><option>Female</option><option>Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="civil_status" class="form-select">
                        <option value="">Civil Status</option>
                        <option>Single</option><option>Married</option><option>Widowed</option><option>Divorced</option>
                    </select>
                </div>
                <div class="col-md-3"><input name="contact_number" class="form-control" placeholder="Contact Number" required></div>
                <div class="col-md-3"><input name="occupation" class="form-control" placeholder="Occupation"></div>

                <div class="col-md-6"><input name="contact_person" class="form-control" placeholder="Contact Person"></div>
                <div class="col-md-3"><input type="number" name="contact_person_age" class="form-control" placeholder="Contact Person Age"></div>
                <div class="col-md-3"><input name="religion" class="form-control" placeholder="Religion"></div>

                <div class="col-12"><textarea name="address" class="form-control" placeholder="Address" required></textarea></div>

                <div class="mt-3"><label class="form-label text-muted small">Chief Complaint</label><textarea name="chief_complaint" class="form-control" placeholder="Chief Complaint" required></textarea></div>

                <div class="row g-2 mt-3">
                    <div class="col"><label class="form-label text-muted small">BP</label><input name="blood_pressure" class="form-control" placeholder="BP" required></div>
                    <div class="col"><label class="form-label text-muted small">RR</label><input name="respiratory_rate" class="form-control" placeholder="RR" required></div>
                    <div class="col"><label class="form-label text-muted small">WT</label><input name="weight" class="form-control" placeholder="WT" required></div>
                    <div class="col"><label class="form-label text-muted small">HR</label><input name="heart_rate" class="form-control" placeholder="HR" required></div>
                    <div class="col"><label class="form-label text-muted small">TEMP</label><input name="temperature" class="form-control" placeholder="TEMP" required></div>
                </div>

            </div>
        </div>

        <button type="button" class="btn btn-primary btn-lg" onclick="showConfirmModal()">
            <i class="fa-solid fa-floppy-disk me-2"></i> Save and Queue Patient
        </button>
    </form>
</div>

<!-- OLD PATIENT PANEL -->
<div class="panel" id="panelOld">
    <div class="card section-card mb-4 shadow-sm">
        <div class="section-header">
            <i class="fa-solid fa-magnifying-glass me-2"></i> Search Existing Patient
        </div>
        <div class="card-body">
            <div class="row justify-content-center">
                <div class="col-md-7">
                    <label class="form-label fw-semibold text-secondary mb-1">Enter patient name</label>
                    <div class="search-wrap position-relative">
                        <input type="text" id="searchInput" class="form-control form-control-lg"
                               placeholder="Search by full name…" autocomplete="off"
                               oninput="searchPatient(this.value)">
                        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <div id="searchResults"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Patient Info Display -->
    <div class="card section-card shadow-sm" id="patientInfoCard">
        <div class="section-header d-flex align-items-center justify-content-between">
            <span><i class="fa-solid fa-id-card me-2"></i> Patient Record</span>
            <button type="button" class="btn btn-sm btn-light ms-auto"
                    onclick="clearPatient()" title="Clear">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="card-body p-0">
            <div class="pi-grid">
                <div class="pi-field">
                    <span class="pi-lbl">Name: </span><span class="pi-val" id="pi_name">—</span>
                </div>
                <div class="pi-field">
                    <span class="pi-lbl">Age: </span><span class="pi-val" id="pi_age">—</span>
                </div>
                <div class="pi-field">
                    <span class="pi-lbl">Sex: </span><span class="pi-val" id="pi_sex">—</span>
                </div>
                <div class="pi-field">
                    <span class="pi-lbl">Contact: </span><span class="pi-val" id="pi_contact">—</span>
                </div>
                <div class="pi-field">
                    <span class="pi-lbl">Civil Status: </span><span class="pi-val" id="pi_civil">—</span>
                </div>
                <div class="pi-field">
                    <span class="pi-lbl">Religion: </span><span class="pi-val" id="pi_religion">—</span>
                </div>
                <div class="pi-field">
                    <span class="pi-lbl">Occupation: </span><span class="pi-val" id="pi_occupation">—</span>
                </div>
                <div class="pi-field"></div>
                <div class="pi-field">
                    <span class="pi-lbl">Contact Person: </span><span class="pi-val" id="pi_cp">—</span>
                </div>
                <div class="pi-field">
                    <span class="pi-lbl">Contact Person Age: </span><span class="pi-val" id="pi_cp_age">—</span>
                </div>
                <div class="pi-field"></div>
                <div class="pi-field"></div>
                <div class="pi-field pi-full">
                    <span class="pi-lbl">Address: </span><span class="pi-val" id="pi_address">—</span>
                </div>
            </div>
        </div>

        <div class="px-4 pb-3">
            <div class="mb-3">
                <label class="form-label text-muted small fw-semibold">Chief Complaint</label>
                <textarea id="old_chief_complaint" class="form-control" placeholder="Chief Complaint" rows="2" required></textarea>
            </div>
            <div class="row g-2">
                <div class="col">
                    <label class="form-label text-muted small">BP</label>
                    <input id="old_blood_pressure" class="form-control" placeholder="BP" required>
                </div>
                <div class="col">
                    <label class="form-label text-muted small">RR</label>
                    <input id="old_respiratory_rate" class="form-control" placeholder="RR" required >
                </div>
                <div class="col">
                    <label class="form-label text-muted small">WT</label>
                    <input id="old_weight" class="form-control" placeholder="WT" required>
                </div>
                <div class="col">
                    <label class="form-label text-muted small">HR</label>
                    <input id="old_heart_rate" class="form-control" placeholder="HR" required>
                </div>
                <div class="col">
                    <label class="form-label text-muted small">TEMP</label>
                    <input id="old_temperature" class="form-control" placeholder="TEMP" required>
                </div>
            </div>
        </div>
        <div class="card-footer bg-transparent border-top-0 px-4 pb-3 pt-0" id="queueOldBtn" style="display:none;">
            <button type="button" class="btn btn-primary btn-lg" onclick="showConfirmModalOld()">
                <i class="fa-solid fa-arrow-right-to-bracket me-2"></i> Queue Patient
            </button>
        </div>
    </div>
</div>

</main>
<?php include "../Includes/footer.html"; ?>
</div>
</div>

<script>
    const SLOTS_FULL = <?= $slotsFull ? 'true' : 'false' ?>;
    const SLOTS_LEFT = <?= $slotsLeft ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function switchMode(mode) {
    document.getElementById('panelNew').classList.toggle('active', mode === 'new');
    document.getElementById('panelOld').classList.toggle('active', mode === 'old');
    document.getElementById('btnNew').classList.toggle('active', mode === 'new');
    document.getElementById('btnOld').classList.toggle('active', mode === 'old');
}

let searchTimer;
window._patientResults = [];

function searchPatient(val) {
    clearTimeout(searchTimer);
    const box = document.getElementById('searchResults');
    if (val.trim().length < 2) { box.style.display = 'none'; return; }
    searchTimer = setTimeout(() => {
        fetch(window.location.pathname + '?search_patient=' + encodeURIComponent(val.trim()))
            .then(r => r.json())
            .then(data => {
                window._patientResults = data;
                if (!data.length) {
                    box.innerHTML = '<div class="no-result"><i class="fa-solid fa-circle-xmark me-1 text-danger"></i>No patient found.</div>';
                } else {
                    box.innerHTML = data.map((p, i) => `
                        <div class="result-item" onclick="selectPatient(${i})">
                            <div class="name">${p.full_name}</div>
                            <div class="meta">${p.birthday ?? ''} &nbsp;|&nbsp; ${p.sex ?? ''} &nbsp;|&nbsp; ${p.contact_number ?? ''}</div>
                        </div>`).join('');
                }
                box.style.display = 'block';
            })
            .catch(() => {
                box.innerHTML = '<div class="no-result text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Search error. Try again.</div>';
                box.style.display = 'block';
            });
    }, 280);
}

function selectPatient(index) {
    const p = window._patientResults[index];
    if (!p) return;

    window._selectedPatientId = p.patient_id;

    document.getElementById('searchInput').value = p.full_name;
    document.getElementById('searchResults').style.display = 'none';
    document.getElementById('pi_name').textContent       = p.full_name          || '—';
    document.getElementById('pi_age').textContent        = p.age                || '—';
    document.getElementById('pi_sex').textContent        = p.sex                || '—';
    document.getElementById('pi_civil').textContent      = p.civil_status       || '—';
    document.getElementById('pi_contact').textContent    = p.contact_number     || '—';
    document.getElementById('pi_occupation').textContent = p.occupation         || '—';
    document.getElementById('pi_religion').textContent   = p.religion           || '—';
    document.getElementById('pi_cp').textContent         = p.contact_person     || '—';
    document.getElementById('pi_cp_age').textContent     = p.contact_person_age || '—';
    document.getElementById('pi_address').textContent    = p.address            || '—';
    document.getElementById('patientInfoCard').style.display = 'block';
    document.getElementById('queueOldBtn').style.display = 'block';
}

function clearPatient() {
    window._selectedPatientId = null;
    document.getElementById('searchInput').value = '';
    document.getElementById('searchResults').style.display = 'none';
    document.getElementById('patientInfoCard').style.display = 'none';
    document.getElementById('queueOldBtn').style.display = 'none';
}

document.addEventListener('click', e => {
    if (!e.target.closest('.search-wrap'))
        document.getElementById('searchResults').style.display = 'none';
});

function showConfirmModal() {
    if (SLOTS_FULL) {
        alert("Today's queue is full. No more patients can be added today.");
        return;
    }
    const form = document.querySelector('#panelNew form');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    document.getElementById('confirmModal').dataset.mode = 'new';
    document.getElementById('confirmModal').classList.add('show');
}

function showConfirmModalOld() {
    if (SLOTS_FULL) {
        alert("Today's queue is full. No more patients can be added today.");
        return;
    }
    document.getElementById('confirmModal').dataset.mode = 'old';
    document.getElementById('confirmModal').classList.add('show');
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('show');
}

function confirmQueue() {
    const mode = document.getElementById('confirmModal').dataset.mode || 'new';
    closeConfirmModal();

    if (mode === 'new') {
        const form = document.querySelector('#panelNew form');
        const formData = new FormData(form);
        formData.append('save_all', '1');

        fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    decrementSlots();
                    showQueueModal(data.queue_number);
                    form.reset();
                } else {
                    alert('Error saving patient: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(() => alert('Network error. Please try again.'));

    } else {
        const fd = new FormData();
        fd.append('queue_old_patient', '1');
        fd.append('patient_id', window._selectedPatientId);
        fd.append('chief_complaint',   document.getElementById('old_chief_complaint').value);
        fd.append('blood_pressure',    document.getElementById('old_blood_pressure').value);
        fd.append('respiratory_rate',  document.getElementById('old_respiratory_rate').value);
        fd.append('weight',            document.getElementById('old_weight').value);
        fd.append('heart_rate',        document.getElementById('old_heart_rate').value);
        fd.append('temperature',       document.getElementById('old_temperature').value);

        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    decrementSlots();
                    showQueueModal(data.queue_number);
                } else {
                    alert(data.error || 'Error queuing patient.');
                }
            })
            .catch(() => alert('Network error. Please try again.'));
    }
}

function decrementSlots() {
    const el = document.getElementById('slotsLeftDisplay');
    if (!el) return;
    let current = parseInt(el.textContent) - 1;
    el.textContent = Math.max(0, current);
}

function showQueueModal(queueNumber) {
    document.getElementById('queueNumberDisplay').textContent = queueNumber;
    document.getElementById('queueModal').classList.add('show');
}

function closeQueueModal() {
    document.getElementById('queueModal').classList.remove('show');
    ['old_chief_complaint','old_blood_pressure','old_respiratory_rate',
     'old_weight','old_heart_rate','old_temperature'].forEach(id => {
        document.getElementById(id).value = '';
    });
}

document.addEventListener('click', e => {
    if (e.target.id === 'confirmModal') closeConfirmModal();
});
</script>

</body>
</html>