<?php
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

/* ================= AJAX: FETCH DOCTOR FROM QUEUE ================= */
if (isset($_GET['fetch_queue_doctor']) && isset($_GET['queue_id'])) {
    header('Content-Type: application/json');
    $qid = (int)$_GET['queue_id'];
    $stmt = $db->prepare("
        SELECT c.doc_id, c.checkup_date
        FROM queue q
        JOIN checkups c ON c.patient_id = q.patient_id
        WHERE q.queue_id = ?
          AND c.is_deleted = 0
          AND DATE(c.checkup_date) = CURDATE()
        ORDER BY c.checkup_id DESC
        LIMIT 1
    ");
    $stmt->execute([$qid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($row && $row['doc_id']
        ? ['success' => true, 'doc_id' => $row['doc_id'], 'checkup_date' => $row['checkup_date']]
        : ['success' => false]
    );
    exit();
}

/* ================= STAFF INFO ================= */
$stmt = $db->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$staff) die("Staff not found.");

/* ================= PAGINATION ================= */
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

/* ================= SEARCH ================= */
$search_date = isset($_GET['checkup_date']) && !empty($_GET['checkup_date']) ? $_GET['checkup_date'] : null;

/* ================= CHECK FOR QUEUED BILLING DATA ================= */
$queuedBillingData = null;
if (isset($_GET['queue_id'])) {
    $queueId = (int)$_GET['queue_id'];

    $qstmt = $db->prepare("
        SELECT q.queue_id, q.queue_number, p.full_name
        FROM queue q
        JOIN patients p ON p.patient_id = q.patient_id
        WHERE q.queue_id = ? AND q.status = 'done'
    ");
    $qstmt->execute([$queueId]);
    $queuedBillingData = $qstmt->fetch(PDO::FETCH_ASSOC);

    if ($queuedBillingData && isset($_GET['doc_id'])) {
        $dstmt = $db->prepare("SELECT doc_id, doc_fullname FROM doctors WHERE doc_id = ?");
        $dstmt->execute([(int)$_GET['doc_id']]);
        $doc = $dstmt->fetch(PDO::FETCH_ASSOC);
        if ($doc) {
            $queuedBillingData = array_merge($queuedBillingData, $doc);
        }
    }
}

/* ================= HANDLE BILLING SUBMIT ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $total = $_POST['consultation_fee'] + $_POST['medication_fee'];

    /* GET PATIENT ID FROM INPUT */
    $stmt = $db->prepare("SELECT patient_id FROM patients WHERE full_name = ? LIMIT 1");
    $stmt->execute([trim($_POST['patient_name'])]);
    $patient_id = $stmt->fetchColumn();

    if (!$patient_id) {
        header("Location: staff_billing.php?error=patient_not_found");
        exit();
    }

    /* DUPLICATION CHECK */
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM billing 
        WHERE patient_id = ? 
          AND doc_id = ? 
          AND consultation_fee = ? 
          AND medication_fee = ? 
          AND total_amount = ?
          AND billed_at >= CURDATE()
    ");
    $stmt->execute([
        $patient_id, $_POST['doc_id'], $_POST['consultation_fee'], $_POST['medication_fee'], $total]);
    if ($stmt->fetchColumn() > 0) {
        header("Location: staff_billing.php?error=duplicate");
        exit();
    }

    /* FIND CHECKUP ID BY DATE */
$checkup_id = null;
if (!empty($_POST['checkup_date'])) {
    $stmt = $db->prepare("
        SELECT checkup_id 
        FROM checkups 
        WHERE patient_id = ? 
        AND checkup_date = ?
        AND is_deleted = 0
        LIMIT 1 
    ");
    $stmt->execute([$patient_id, $_POST['checkup_date']]);
    $checkup_id = $stmt->fetchColumn() ?: null;
}

    $stmt = $db->prepare("
        INSERT INTO billing
        (patient_id, doc_id, checkup_id, billed_at, consultation_fee, medication_fee, total_amount, payment_method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $patient_id,
        $_POST['doc_id'],
        $checkup_id ?: null,
        !empty($_POST['checkup_date']) ? $_POST['checkup_date'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s'),
        $_POST['consultation_fee'],
        $_POST['medication_fee'],
        $total,
        $_POST['payment_method']
    ]);

    header("Location: staff_billing.php?success=1");
    exit();
}

/* ================= FETCH DATA ================= */
/* LATEST 5 PATIENTS */
$latestPatients = $db->query("SELECT full_name FROM patients ORDER BY patient_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

/* DOCTORS */
$doctors = $db->query("SELECT doc_id, doc_fullname FROM doctors")->fetchAll(PDO::FETCH_ASSOC);

$doneQueuePatients = $db->query("SELECT q.queue_id, q.queue_number, p.full_name
    FROM queue q
    JOIN patients p ON p.patient_id = q.patient_id
    WHERE q.status = 'done'
    ORDER BY q.done_at DESC
    LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

/* ALREADY BILLED QUEUE IDs TODAY */
$billedQueueIds = [];
$billedStmt = $db->query("
    SELECT DISTINCT q.queue_id 
    FROM billing b
    JOIN queue q ON q.patient_id = b.patient_id
    WHERE DATE(b.billed_at) = CURDATE()
    AND q.status = 'done'
");
$billedQueueIds = $billedStmt->fetchAll(PDO::FETCH_COLUMN);

/* TOTAL BILLS COUNT FOR PAGINATION */
$countSql = "SELECT COUNT(*) FROM billing b LEFT JOIN checkups c ON b.checkup_id = c.checkup_id";
if ($search_date) {
    $countSql .= " WHERE c.checkup_date = :search_date";
}
$stmt = $db->prepare($countSql);
if ($search_date) $stmt->bindValue(':search_date', $search_date);
$stmt->execute();
$totalBills = $stmt->fetchColumn();
$totalPages = ceil($totalBills / $limit);

/* BILLING RECORDS (PAGINATED, SEARCHABLE) */
$sql = "
    SELECT 
        b.*, 
        p.full_name, 
        d.doc_fullname,
        c.checkup_date
    FROM billing b
    JOIN patients p ON p.patient_id = b.patient_id
    JOIN doctors d ON d.doc_id = b.doc_id
    LEFT JOIN checkups c ON c.checkup_id = b.checkup_id
";
if ($search_date) $sql .= " WHERE c.checkup_date = :search_date";
$sql .= " ORDER BY b.billed_at DESC LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
if ($search_date) $stmt->bindValue(':search_date', $search_date);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);


$medications = $db->query("
    SELECT generic_name, brand_name, unit_price
    FROM medications
    ORDER BY generic_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$genericNames = $db->query("
    SELECT DISTINCT generic_name
    FROM medications
    ORDER BY generic_name
")->fetchAll(PDO::FETCH_COLUMN);

$brandNames = $db->query("
    SELECT DISTINCT brand_name
    FROM medications
    ORDER BY brand_name
")->fetchAll(PDO::FETCH_COLUMN);

$patientCheckups = $db->query("
    SELECT p.full_name, c.checkup_date
    FROM checkups c
    JOIN patients p ON p.patient_id = c.patient_id
    WHERE c.is_deleted = 0
    ORDER BY c.checkup_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="../Includes/favicon_obeso.png">
<title>Obeso Clinic | Billing</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="../Includes/sidebarStyle.css" rel="stylesheet">
<style>
.sb-sidenav .nav-link.active { background-color: #062e6bff !important; color: #fff !important; font-weight: 600; }
.queue-item { cursor: pointer; transition: background 0.12s; }
.queue-item:hover { background: #f0f4ff; }
.med-autocomplete-wrapper { position: relative; }
.med-dropdown {
  position: absolute;
  top: calc(100% + 2px);
  left: 0; right: 0;
  background: #fff;
  border: 1px solid #c8d6e8;
  border-radius: 6px;
  box-shadow: 0 4px 16px rgba(6,46,107,0.13);
  max-height: 200px;
  overflow-y: auto;
  z-index: 9999;
  display: none;
}
.med-dropdown.show { display: block; }
.med-dropdown-item {
  padding: 8px 12px;
  font-size: 13px;
  cursor: pointer;
  border-bottom: 1px solid #f0f4ff;
  color: #222;
  transition: background 0.1s;
}
.med-dropdown-item:last-child { border-bottom: none; }
.med-dropdown-item:hover, .med-dropdown-item.active {
  background: #e8eef7;
  color: #062e6b;
  font-weight: 500;
}
.med-dropdown-item .match-highlight { color: #1a6fd4; font-weight: 700; }
.billed-item:hover { background: transparent !important; }
</style>
</head>
<body class="sb-nav-fixed">

<?php include "../Includes/header.html"; ?>
<?php include "../Includes/navbar_staff.html"; ?>

<div id="layoutSidenav">
<div id="layoutSidenav_nav"><?php include "../Includes/staffSidebar.php"; ?></div>
<div id="layoutSidenav_content">
<main class="container-fluid px-4 py-4">

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" 
     style="margin-bottom: 10px; padding: 10px 16px; font-size: 14px;" role="alert">
    <i class="fa fa-circle-check me-1"></i>
    Billing record successfully added.
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2"
     style="margin-bottom: 10px; padding: 10px 16px; font-size: 14px;" role="alert">
    <i class="fa fa-circle-exclamation me-1"></i>
    <?php if ($_GET['error'] === 'patient_not_found'): ?>
        Patient not found. Please make sure the name matches exactly.
    <?php elseif ($_GET['error'] === 'duplicate'): ?>
        Duplicate billing record detected for today.
    <?php endif; ?>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ================= QUEUE BUTTON ================= -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">

    <button onclick="document.getElementById('queueModal').style.display='flex'"
        style="background-color:#1a6fd4; color:#fff; border:none; border-radius:6px; padding:10px 22px; font-size:14px; font-weight:500; cursor:pointer; letter-spacing:0.2px;">
    Click Patient
    </button>

    <div class="d-flex align-items-center gap-2 px-3 py-2 rounded border bg-white" id="queueBadge" style="display:<?= $queuedBillingData ? 'flex' : 'none' ?>;">
        <span class="text-muted" style="font-size:13px;">Queue</span>
        <span class="badge px-2 py-1" style="background:#062e6b; font-size:13px;" id="selectedQueueNum"><?= $queuedBillingData ? '#' . htmlspecialchars($queuedBillingData['queue_number']) : '' ?></span>
        <span class="fw-semibold" id="selectedQueueName"><?= $queuedBillingData ? htmlspecialchars($queuedBillingData['full_name']) : '' ?></span>
    </div>

</div>

<!-- ================= QUEUE MODAL ================= -->
<div id="queueModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:360px; max-height:480px; overflow:hidden; display:flex; flex-direction:column;">

        <div class="d-flex justify-content-between align-items-center px-4 py-3" style="border-bottom:1px solid #dee2e6;">
            <h6 class="mb-0"><i class="fa fa-list-ol me-2"></i>Today's Patient Queue</h6>
            <button onclick="document.getElementById('queueModal').style.display='none'"
                    style="background:none; border:none; font-size:20px; cursor:pointer; color:#6c757d; line-height:1;">&times;</button>
        </div>

        <div style="overflow-y:auto; padding:8px; display:flex; flex-direction:column;">
            <?php foreach ($doneQueuePatients as $q): 
                $isBilled = in_array($q['queue_id'], $billedQueueIds);
            ?>
            <div class="queue-item d-flex align-items-center gap-3 px-3 py-2 rounded
                <?= $isBilled ? 'billed-item' : '' ?>"
                <?= !$isBilled ? "onclick=\"selectQueuePatient({$q['queue_id']}, '{$q['queue_number']}', '" . htmlspecialchars($q['full_name'], ENT_QUOTES) . "')\"" : '' ?>
                style="<?= $isBilled ? 'opacity:0.45; cursor:not-allowed; order:1;' : 'order:0;' ?>">

                <span class="badge px-2 py-1" 
                    style="background:<?= $isBilled ? '#ccc' : '#e8eef7' ?>; color:<?= $isBilled ? '#888' : '#062e6b' ?>; font-size:13px; min-width:36px;">
                    #<?= htmlspecialchars($q['queue_number']) ?>
                </span>
                <span><?= htmlspecialchars($q['full_name']) ?></span>
                <?php if ($isBilled): ?>
                    <span class="ms-auto badge" style="background:#d4edda; color:#155724; font-size:11px;">Billed</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<!-- ================= BILLING FORM ================= -->
<div class="card shadow mb-4" style="margin-top: 20px;">
<div class="card-body">
<h5 class="text-primary mb-3"><i class="fa fa-file-invoice"></i> Billing Form</h5>

<?php if ($queuedBillingData): ?>
<div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2" 
     style="margin-bottom: 15px; padding: 10px 16px; font-size: 14px;" role="alert">
    <i class="fa fa-check-circle me-1"></i>
    Form auto-filled from completed checkup (Queue #<?= htmlspecialchars($queuedBillingData['queue_id']) ?>)
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" class="row g-3">
<div class="col-md-4">
<label class="form-label">Patient</label>
<input type="text" name="patient_name" id="patientInput" class="form-control" list="patients" required
       value="<?= $queuedBillingData ? htmlspecialchars($queuedBillingData['full_name']) : '' ?>">
<datalist id="patients">
<?php foreach ($latestPatients as $p): ?>
<option value="<?= htmlspecialchars($p['full_name']) ?>">
<?php endforeach; ?>
</datalist>
<small class="text-muted">Shows latest 5 patients</small>
</div>

<div class="col-md-4">
<label class="form-label">Doctor</label>
<select name="doc_id" class="form-select" required>
<option value="">Select Doctor</option>
<?php foreach ($doctors as $d): ?>
<option value="<?= $d['doc_id'] ?>" <?= $queuedBillingData && $d['doc_id'] === (int)$queuedBillingData['doc_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['doc_fullname']) ?></option>
<?php endforeach; ?>
</select>
</div>

<input type="hidden" name="selected_queue_id" id="selectedQueueId" value="<?= $queuedBillingData ? htmlspecialchars($queuedBillingData['queue_id']) : '' ?>">

<div class="col-md-4">
<label class="form-label">Checkup Date (Optional)</label>
<input type="date" name="checkup_date" class="form-control" value="<?= $queuedBillingData ? date('Y-m-d') : '' ?>">
</div>

<div class="col-md-3">
<label class="form-label">Consultation Fee</label>
<input type="number" step="0.01" name="consultation_fee" class="form-control" value="300.00" readonly required>
</div>

<input type="hidden" name="medication_fee" value="0">

<div class="col-md-3">
<label class="form-label">Payment Method</label>
<select name="payment_method" class="form-select">
    <option value="">Select Method</option>
    <option value="Cash">Cash</option>
    <option value="Bank Transfer">Bank Transfer</option>
    <option value="GCash">GCash</option>
</select>
</div>

<!-- ================= MEDICATION RECEIPT ================= -->
<div class="col-md-12">
  <div class="card border mb-2" style="background:#fafbff;">
    <div class="card-body py-3 px-3">
      <h6 class="text-primary mb-3"><i class="fa fa-pills me-2"></i>Medication Receipt</h6>

      <table class="table table-sm mb-2" id="medTable">
        <thead style="background:#f0f4ff;">
          <tr>
            <th style="color:#062e6b;">Generic Name</th>
            <th style="color:#062e6b;">Brand Name</th>
            <th style="color:#062e6b; width:80px;">Qty</th>
            <th style="color:#062e6b; width:130px;">Unit Price (₱)</th>
            <th style="color:#062e6b; width:120px; text-align:right;">Subtotal</th>
            <th style="width:40px;"></th>
          </tr>
        </thead>
        <tbody id="medBody"></tbody>
      </table>

      <button type="button" class="btn btn-sm" onclick="addMedRow()"
              style="background:#062e6b; color:#fff; border:none; border-radius:6px; font-size:13px;">
        <i class="fa fa-plus me-1"></i> Add Medication
      </button>

      <div class="mt-3 p-3 rounded" style="background:#f0f4ff; font-size:14px;">
        <div class="d-flex justify-content-between mb-1 text-muted">
          <span>Consultation Fee</span>
          <span id="rcptConsult">₱0.00</span>
        </div>
        <div class="d-flex justify-content-between mb-1 text-muted">
          <span>Medication Fee</span>
          <span id="rcptMedFee">₱0.00</span>
        </div>
        <div class="d-flex justify-content-between pt-2 mt-1 fw-bold"
             style="border-top:2px solid #062e6b; color:#062e6b; font-size:15px;">
          <span>Overall Total</span>
          <span id="rcptGrandTotal">₱0.00</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="col-md-12">
<button class="btn btn-primary w-100"><i class="fa fa-save"></i> Save Billing</button>
</div>
</form>
</div>
</div>

<!-- ================= SEARCH BY CHECKUP DATE ================= -->
<div class="card shadow mb-4">
<div class="card-body">
<form method="GET" class="row g-3">
<div class="col-md-4">
<label class="form-label">Search by Checkup Date</label>
<input type="date" name="checkup_date" class="form-control" value="<?= htmlspecialchars($search_date) ?>">
</div>
<div class="col-md-2 align-self-end">
<button class="btn btn-secondary w-100"><i class="fa fa-search"></i> Search</button>
</div>
</form>
</div>
</div>

<!-- ================= BILLING TABLE ================= -->
<div class="card shadow">
<div class="card-body">
<h5 class="text-primary mb-3"><i class="fa fa-list"></i> Billing Records</h5>
<table class="table table-bordered table-striped">
<thead>
<tr>
<th>Patient</th>
<th>Doctor</th>
<th>Checkup Date</th>
<th>Total</th>
<th>Method</th>
<th>Date Billed</th>
</tr>
</thead>
<tbody>
<?php foreach ($bills as $b): ?>
<tr>
<td><?= htmlspecialchars($b['full_name']) ?></td>
<td><?= htmlspecialchars($b['doc_fullname']) ?></td>
<td><?= $b['checkup_date'] ? date('M d, Y', strtotime($b['checkup_date'])) : '—' ?></td>
<td>₱<?= number_format($b['total_amount'],2) ?></td>
<td><?= htmlspecialchars($b['payment_method']) ?></td>
<td><?= date('M d, Y', strtotime($b['billed_at'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- ================= PAGINATION ================= -->
<nav>
<ul class="pagination justify-content-center">
<li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
<a class="page-link" href="?page=<?= $page - 1 ?><?= $search_date ? "&checkup_date=$search_date" : '' ?>">Previous</a>
</li>
<?php for ($i = 1; $i <= $totalPages; $i++): ?>
<li class="page-item <?= $i === $page ? 'active' : '' ?>">
<a class="page-link" href="?page=<?= $i ?><?= $search_date ? "&checkup_date=$search_date" : '' ?>"><?= $i ?></a>
</li>
<?php endfor; ?>
<li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
<a class="page-link" href="?page=<?= $page + 1 ?><?= $search_date ? "&checkup_date=$search_date" : '' ?>">Next</a>
</li>
</ul>
</nav>

</div>
</div>

</main>
<?php include "../Includes/footer.html"; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Medication data from PHP ──────────────────────────────────────────────
const allMedications = <?php echo json_encode($medications); ?>;

// ── Queue ─────────────────────────────────────────────────────────────────
function selectQueuePatient(queueId, queueNumber, name) {
    document.getElementById('selectedQueueNum').textContent = '#' + queueNumber;
    document.getElementById('selectedQueueName').textContent = name;
    document.getElementById('queueBadge').style.display = 'flex';
    document.getElementById('queueModal').style.display = 'none';
    document.querySelector('input[name="patient_name"]').value = name;
    document.getElementById('selectedQueueId').value = queueId;

    // Reset fields first
    document.querySelector('select[name="doc_id"]').value = '';
    document.querySelector('input[name="checkup_date"]').value = '';

    fetch('staff_billing.php?fetch_queue_doctor=1&queue_id=' + encodeURIComponent(queueId), { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
        if (d.doc_id) {
            document.querySelector('select[name="doc_id"]').value = d.doc_id;
        }
        document.querySelector('input[name="checkup_date"]').value = d.checkup_date
            ? d.checkup_date.slice(0, 10)
            : new Date().toISOString().slice(0, 10);
    });
}

document.getElementById('queueModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

// ── Autocomplete helper ───────────────────────────────────────────────────
function buildAutocomplete(input, getList, onSelect) {
    const wrapper = input.closest('.med-autocomplete-wrapper');
    const dropdown = wrapper.querySelector('.med-dropdown');
    let activeIdx = -1;

    function highlight(text, query) {
        const idx = text.toLowerCase().indexOf(query.toLowerCase());
        if (idx === -1) return text;
        return text.slice(0, idx)
            + '<span class="match-highlight">' + text.slice(idx, idx + query.length) + '</span>'
            + text.slice(idx + query.length);
    }

    function showDropdown(items, query) {
        if (!items.length) { dropdown.classList.remove('show'); return; }
        dropdown.innerHTML = items.map((item, i) =>
            `<div class="med-dropdown-item" data-idx="${i}" data-value="${item}">${highlight(item, query)}</div>`
        ).join('');
        dropdown.classList.add('show');
        activeIdx = -1;

        dropdown.querySelectorAll('.med-dropdown-item').forEach(el => {
            el.addEventListener('mousedown', function(e) {
                e.preventDefault();
                input.value = this.dataset.value;
                dropdown.classList.remove('show');
                onSelect && onSelect(this.dataset.value, input);
            });
        });
    }

    input.addEventListener('input', function() {
        const q = this.value.trim();
        if (!q) { dropdown.classList.remove('show'); return; }
        const matches = getList().filter(v => v.toLowerCase().startsWith(q.toLowerCase())).slice(0, 10);
        showDropdown(matches, q);
    });

    input.addEventListener('keydown', function(e) {
        const items = dropdown.querySelectorAll('.med-dropdown-item');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = Math.min(activeIdx + 1, items.length - 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = Math.max(activeIdx - 1, 0);
        } else if (e.key === 'Enter' && activeIdx >= 0) {
            e.preventDefault();
            input.value = items[activeIdx].dataset.value;
            dropdown.classList.remove('show');
            onSelect && onSelect(input.value, input);
            return;
        } else if (e.key === 'Escape') {
            dropdown.classList.remove('show'); return;
        }
        items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
        if (activeIdx >= 0) items[activeIdx].scrollIntoView({ block: 'nearest' });
    });

    input.addEventListener('blur', function() {
        setTimeout(() => dropdown.classList.remove('show'), 150);
    });

    // Do NOT open on focus — only open when user types
}

// ── Medication rows ───────────────────────────────────────────────────────
let medRowId = 0;

function addMedRow() {
    medRowId++;
    const id = medRowId;
    const tr = document.createElement('tr');
    tr.id = 'med-row-' + id;
    tr.innerHTML = `
        <td>
            <div class="med-autocomplete-wrapper">
                <input type="text" class="form-control form-control-sm" name="med_generic[]"
                       placeholder="Type generic name..." autocomplete="off">
                <div class="med-dropdown"></div>
            </div>
        </td>
        <td>
            <div class="med-autocomplete-wrapper">
                <input type="text" class="form-control form-control-sm" name="med_brand[]"
                       placeholder="Type brand name..." autocomplete="off">
                <div class="med-dropdown"></div>
            </div>
        </td>
        <td><input type="number" class="form-control form-control-sm" name="med_qty[]" value="1" min="1"
                  oninput="calcMedRow(${id}); syncMedFee();"></td>
        <td><input type="number" class="form-control form-control-sm" name="med_price[]" placeholder="0.00" step="0.01" min="0"
                  oninput="calcMedRow(${id}); syncMedFee();" readonly></td>
        <td class="text-end fw-semibold text-primary" id="med-sub-${id}">₱0.00</td>
        <td><button type="button" class="btn btn-sm btn-link text-danger p-0"
                    onclick="removeMedRow(${id})"><i class="fa fa-times"></i></button></td>
    `;
    document.getElementById('medBody').appendChild(tr);

    const genericInput = tr.querySelector('[name="med_generic[]"]');
    const brandInput   = tr.querySelector('[name="med_brand[]"]');

    // When a generic is picked, auto-fill the matching brand
    buildAutocomplete(genericInput,
        () => [...new Set(allMedications.map(m => m.generic_name))],
        (val, inp) => {
            const match = allMedications.find(m => m.generic_name === val);
            if (match) {
                brandInput.value = match.brand_name;
                const priceInput = tr.querySelector('[name="med_price[]"]');
                priceInput.value = match.unit_price ? parseFloat(match.unit_price).toFixed(2) : '';
                calcMedRow(id);
                syncMedFee();
            }
        }
    );

    buildAutocomplete(brandInput,
        () => {
            const typedGeneric = genericInput.value.trim();
            if (typedGeneric) {
                return [...new Set(
                    allMedications
                        .filter(m => m.generic_name.toLowerCase() === typedGeneric.toLowerCase())
                        .map(m => m.brand_name)
                )];
            }
            // If no generic typed yet, show all brands
            return [...new Set(allMedications.map(m => m.brand_name))];
        },
        (val, inp) => {
            const match = allMedications.find(m => m.brand_name === val);
            if (match) {
                genericInput.value = match.generic_name;
                const priceInput = tr.querySelector('[name="med_price[]"]');
                priceInput.value = match.unit_price ? parseFloat(match.unit_price).toFixed(2) : '';
                calcMedRow(id);
                syncMedFee();
            }
        }
    );

    genericInput.addEventListener('input', function() {
        const typedGeneric = this.value.trim();
        if (!typedGeneric) {
            brandInput.value = '';
            const brandDropdown = brandInput.closest('.med-autocomplete-wrapper').querySelector('.med-dropdown');
            brandDropdown.classList.remove('show');
            brandDropdown.innerHTML = '';
            return;
        }

        const matchingBrands = [...new Set(
            allMedications
                .filter(m => m.generic_name.toLowerCase().startsWith(typedGeneric.toLowerCase()))
                .map(m => m.brand_name)
        )];

        if (matchingBrands.length === 1) {
            brandInput.value = matchingBrands[0];
            const match = allMedications.find(m => m.brand_name === matchingBrands[0]);
            if (match) {
                const priceInput = tr.querySelector('[name="med_price[]"]');
                priceInput.value = match.unit_price ? parseFloat(match.unit_price).toFixed(2) : '';
                calcMedRow(id);
                syncMedFee();
            }
        } else {
            brandInput.value = '';
            const brandWrapper = brandInput.closest('.med-autocomplete-wrapper');
            const brandDropdown = brandWrapper.querySelector('.med-dropdown');

            if (!matchingBrands.length) {
                brandDropdown.classList.remove('show');
                return;
            }

            brandDropdown.innerHTML = matchingBrands.map((b, i) =>
                `<div class="med-dropdown-item" data-value="${b}">${b}</div>`
            ).join('');
            brandDropdown.classList.add('show');

            brandDropdown.querySelectorAll('.med-dropdown-item').forEach(el => {
                el.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    brandInput.value = this.dataset.value;
                    brandDropdown.classList.remove('show');
                    const match = allMedications.find(m => m.brand_name === this.dataset.value);
                    if (match) {
                        const priceInput = tr.querySelector('[name="med_price[]"]');
                        priceInput.value = match.unit_price ? parseFloat(match.unit_price).toFixed(2) : '';
                        calcMedRow(id);
                        syncMedFee();
                    }
                });
            });
        }
    });

    brandInput.addEventListener('input', function() {
        const typedBrand = this.value.trim();
        const typedGeneric = genericInput.value.trim();

        if (!typedBrand && typedGeneric) {
            const matchingBrands = [...new Set(
                allMedications
                    .filter(m => m.generic_name.toLowerCase().startsWith(typedGeneric.toLowerCase()))
                    .map(m => m.brand_name)
            )];

            const brandWrapper = brandInput.closest('.med-autocomplete-wrapper');
            const brandDropdown = brandWrapper.querySelector('.med-dropdown');

            if (!matchingBrands.length) {
                brandDropdown.classList.remove('show');
                return;
            }

            brandDropdown.innerHTML = matchingBrands.map(b =>
                `<div class="med-dropdown-item" data-value="${b}">${b}</div>`
            ).join('');
            brandDropdown.classList.add('show');

            brandDropdown.querySelectorAll('.med-dropdown-item').forEach(el => {
                el.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    brandInput.value = this.dataset.value;
                    brandDropdown.classList.remove('show');
                    const match = allMedications.find(m => m.brand_name === this.dataset.value);
                    if (match) {
                        const priceInput = tr.querySelector('[name="med_price[]"]');
                        priceInput.value = match.unit_price ? parseFloat(match.unit_price).toFixed(2) : '';
                        calcMedRow(id);
                        syncMedFee();
                    }
                });
            });
        }
    });
}

function calcMedRow(id) {
    const tr = document.getElementById('med-row-' + id);
    const qty   = parseFloat(tr.querySelector('[name="med_qty[]"]').value)   || 0;
    const price = parseFloat(tr.querySelector('[name="med_price[]"]').value) || 0;
    document.getElementById('med-sub-' + id).textContent = '₱' + (qty * price).toFixed(2);
}

function removeMedRow(id) {
    const tr = document.getElementById('med-row-' + id);
    if (tr) tr.remove();
    syncMedFee();
}

const patientCheckups = <?php echo json_encode($patientCheckups); ?>;

document.getElementById('patientInput').addEventListener('change', function() {
    const name = this.value.trim();
    const match = patientCheckups.find(r => r.full_name === name);
    if (match) {
        document.querySelector('input[name="checkup_date"]').value = match.checkup_date;
    }
});

function syncMedFee() {
    let medTotal = 0;
    document.querySelectorAll('#medBody tr').forEach(tr => {
        const qty   = parseFloat(tr.querySelector('[name="med_qty[]"]')?.value)   || 0;
        const price = parseFloat(tr.querySelector('[name="med_price[]"]')?.value) || 0;
        medTotal += qty * price;
    });
    document.querySelector('input[name="medication_fee"]').value = medTotal.toFixed(2);
    const consultFee = parseFloat(document.querySelector('input[name="consultation_fee"]')?.value) || 300;
    document.getElementById('rcptConsult').textContent    = '₱' + consultFee.toFixed(2);
    document.getElementById('rcptMedFee').textContent     = '₱' + medTotal.toFixed(2);
    document.getElementById('rcptGrandTotal').textContent = '₱' + (consultFee + medTotal).toFixed(2);
}

document.querySelector('input[name="consultation_fee"]')?.addEventListener('input', syncMedFee);

addMedRow();
syncMedFee();

document.querySelector('form[method="POST"]').addEventListener('submit', function() {
    document.getElementById('selectedQueueNum').textContent = '';
    document.getElementById('selectedQueueName').textContent = '';
    document.getElementById('selectedQueueNum').style.display = 'none';
});
</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</body>
</html>
