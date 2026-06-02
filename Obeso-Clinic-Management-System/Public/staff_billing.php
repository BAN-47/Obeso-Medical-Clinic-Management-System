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

/* ================= HANDLE BILLING SUBMIT ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $total = $_POST['consultation_fee'] + $_POST['medication_fee'];

    /* GET PATIENT ID FROM INPUT */
    $stmt = $db->prepare("SELECT patient_id FROM patients WHERE full_name = ? LIMIT 1");
    $stmt->execute([trim($_POST['patient_name'])]);
    $patient_id = $stmt->fetchColumn();

    if (!$patient_id) {
        die("Patient not found.");
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
        die("Duplicate billing record detected for today.");
    }

    /* FIND CHECKUP ID BY DATE */
    $checkup_id = null;
    if (!empty($_POST['checkup_date'])) {
        $stmt = $db->prepare("
            SELECT checkup_id 
            FROM checkups 
            WHERE patient_id = ? 
              AND doc_id = ? 
              AND checkup_date = ?
            LIMIT 1
        ");
        $stmt->execute([$patient_id, $_POST['doc_id'], $_POST['checkup_date']]);
        $checkup_id = $stmt->fetchColumn();
    }

    $stmt = $db->prepare("
        INSERT INTO billing
        (patient_id, doc_id, checkup_id, billed_at, consultation_fee, medication_fee, total_amount, payment_status, payment_method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $patient_id,
        $_POST['doc_id'],
        $checkup_id ?: null,
        !empty($_POST['billed_at']) ? $_POST['billed_at'] : null,
        $_POST['consultation_fee'],
        $_POST['medication_fee'],
        $total,
        $_POST['payment_status'],
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
    SELECT generic_name, brand_name
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
<div class="alert alert-success" style="margin-top: -40px;">Billing record successfully added.</div>
<?php endif; ?>

<!-- ================= QUEUE BUTTON ================= -->
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap" style="margin-top: -30px;">

    <button onclick="document.getElementById('queueModal').style.display='flex'"
        style="background-color:#1a6fd4; color:#fff; border:none; border-radius:6px; padding:10px 22px; font-size:14px; font-weight:500; cursor:pointer; letter-spacing:0.2px;">
    Click Patient
    </button>

    <div class="d-flex align-items-center gap-2 px-3 py-2 rounded border bg-white" id="queueBadge" style="display:none !important;">
        <span class="text-muted" style="font-size:13px;">Queue</span>
        <span class="badge px-2 py-1" style="background:#062e6b; font-size:13px;" id="selectedQueueNum"></span>
        <span class="fw-semibold" id="selectedQueueName"></span>
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

        <div style="overflow-y:auto; padding:8px;">
            <!-- Queue items — replace with PHP foreach output -->
            <div class="queue-item d-flex align-items-center gap-3 px-3 py-2 rounded" onclick="selectQueuePatient(1, 'Maria Santos')">
                <span class="badge px-2 py-1" style="background:#e8eef7; color:#062e6b; font-size:13px; min-width:36px;">#1</span>
                <span>Maria Santos</span>
            </div>
            <div class="queue-item d-flex align-items-center gap-3 px-3 py-2 rounded" onclick="selectQueuePatient(2, 'Juan dela Cruz')">
                <span class="badge px-2 py-1" style="background:#e8eef7; color:#062e6b; font-size:13px; min-width:36px;">#2</span>
                <span>Juan dela Cruz</span>
            </div>
            <div class="queue-item d-flex align-items-center gap-3 px-3 py-2 rounded" onclick="selectQueuePatient(3, 'Ana Reyes')">
                <span class="badge px-2 py-1" style="background:#e8eef7; color:#062e6b; font-size:13px; min-width:36px;">#3</span>
                <span>Ana Reyes</span>
            </div>
            <div class="queue-item d-flex align-items-center gap-3 px-3 py-2 rounded" onclick="selectQueuePatient(4, 'Pedro Manalo')">
                <span class="badge px-2 py-1" style="background:#e8eef7; color:#062e6b; font-size:13px; min-width:36px;">#4</span>
                <span>Pedro Manalo</span>
            </div>
            <div class="queue-item d-flex align-items-center gap-3 px-3 py-2 rounded" onclick="selectQueuePatient(5, 'Liza Bautista')">
                <span class="badge px-2 py-1" style="background:#e8eef7; color:#062e6b; font-size:13px; min-width:36px;">#5</span>
                <span>Liza Bautista</span>
            </div>
        </div>

    </div>
</div>

<!-- ================= BILLING FORM ================= -->
<div class="card shadow mb-4" style="margin-top: 20px;">
<div class="card-body">
<h5 class="text-primary mb-3"><i class="fa fa-file-invoice"></i> Billing Form</h5>
<form method="POST" class="row g-3">
<div class="col-md-4">
<label class="form-label">Patient</label>
<input type="text" name="patient_name" class="form-control" list="patients" required>
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
<option value="<?= $d['doc_id'] ?>"><?= htmlspecialchars($d['doc_fullname']) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-4">
<label class="form-label">Checkup Date (Optional)</label>
<input type="date" name="checkup_date" class="form-control">
</div>

<div class="col-md-3">
<label class="form-label">Consultation Fee</label>
<input type="number" step="0.01" name="consultation_fee" class="form-control" value="300.00" readonly required>
</div>

<div class="col-md-3">
<label class="form-label">Medication Fee</label>
<input type="number" step="0.01" name="medication_fee" value="0" class="form-control">
</div>

<div class="col-md-3">
<label class="form-label">Payment Status</label>
<select name="payment_status" class="form-select">
    <option value="">Select Status</option>
    <option>Unpaid</option>
    <option>Partial</option>
    <option>Paid</option>
</select>
</div>

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

      <datalist id="genericList">
        <?php foreach ($medications as $med): ?>
            <option value="<?= htmlspecialchars($med['generic_name']) ?>">
        <?php endforeach; ?>
        </datalist>

        <datalist id="brandList">
        <?php foreach ($medications as $med): ?>
            <option value="<?= htmlspecialchars($med['brand_name']) ?>">
        <?php endforeach; ?>
        </datalist>

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
<th>Status</th>
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
<td>
<span class="badge bg-<?= 
$b['payment_status'] === 'Paid' ? 'success' :
($b['payment_status'] === 'Partial' ? 'warning' : 'danger')
?>"><?= $b['payment_status'] ?></span>
</td>
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
function selectQueuePatient(num, name) {
    document.getElementById('selectedQueueNum').textContent = '#' + num;
    document.getElementById('selectedQueueName').textContent = name;
    document.getElementById('queueBadge').style.display = 'flex';
    document.getElementById('queueModal').style.display = 'none';
    document.querySelector('input[name="patient_name"]').value = name;
}

// Close modal when clicking the backdrop
document.getElementById('queueModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

let medRowId = 0;

function addMedRow() {
  medRowId++;
  const id = medRowId;
  const tr = document.createElement('tr');
  tr.id = 'med-row-' + id;
  tr.innerHTML = `
    <td>
        <select class="form-select form-select-sm generic-select" name="med_generic[]">
            <option value=""></option>
            <?php foreach($genericNames as $g): ?>
            <option value="<?= htmlspecialchars($g) ?>">
                <?= htmlspecialchars($g) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </td>

    <td>
        <select class="form-select form-select-sm brand-select" name="med_brand[]">
            <option value=""></option>
            <?php foreach($brandNames as $b): ?>
            <option value="<?= htmlspecialchars($b) ?>">
                <?= htmlspecialchars($b) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </td>
    <td><input type="number" class="form-control form-control-sm" name="med_qty[]" value="1" min="1"
              oninput="calcMedRow(${id}); syncMedFee();"></td>
    <td><input type="number" class="form-control form-control-sm" name="med_price[]" placeholder="0.00" step="0.01" min="0"
              oninput="calcMedRow(${id}); syncMedFee();"></td>
    <td class="text-end fw-semibold text-primary" id="med-sub-${id}">₱0.00</td>
    <td><button type="button" class="btn btn-sm btn-link text-danger p-0"
                onclick="removeMedRow(${id})"><i class="fa fa-times"></i></button></td>
  `;
  document.getElementById('medBody').appendChild(tr);

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
