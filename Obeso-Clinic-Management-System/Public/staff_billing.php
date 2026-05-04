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
        (patient_id, doc_id, checkup_id, consultation_fee, medication_fee, total_amount, payment_status, payment_method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $patient_id,
        $_POST['doc_id'],
        $checkup_id ?: null,
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Obeso Clinic | Billing</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
<link href="../Includes/sidebarStyle.css" rel="stylesheet">
<style>
.sb-sidenav .nav-link.active { background-color: #062e6bff !important; color: #fff !important; font-weight: 600; }
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
<div class="alert alert-success">Billing record successfully added.</div>
<?php endif; ?>

<!-- ================= BILLING FORM ================= -->
<div class="card shadow mb-4">
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
<input type="number" step="0.01" name="consultation_fee" class="form-control" required>
</div>

<div class="col-md-3">
<label class="form-label">Medication Fee</label>
<input type="number" step="0.01" name="medication_fee" value="0" class="form-control">
</div>

<div class="col-md-3">
<label class="form-label">Payment Status</label>
<select name="payment_status" class="form-select">
<option>Unpaid</option>
<option>Partial</option>
<option>Paid</option>
</select>
</div>

<div class="col-md-3">
<label class="form-label">Payment Method</label>
<input type="text" name="payment_method" class="form-control">
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
</body>
</html>
