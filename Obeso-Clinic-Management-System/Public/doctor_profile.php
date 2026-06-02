<?php
session_name('obeso_doctor');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* ================= ACCESS CONTROL ================= */
if (!isset($_SESSION['role']) || !isset($_SESSION['doc_id'])) {
    header("Location: access_denied.php");
    exit();
}

/* ================= DATABASE ================= */
require_once "../Config/database.php";
$db = (new Database())->connect();

/* ================= HANDLE UPDATE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $stmt = $db->prepare("
        UPDATE doctors SET
            doc_fullname = ?,
            doc_email = ?,
            doc_contact_num = ?,
            doc_updated_at = NOW()
        WHERE doc_id = ?
    ");

    $stmt->execute([
        trim($_POST['doctor_fullname']),
        trim($_POST['doctor_email']),
        trim($_POST['doctor_contact_num']),
        $_SESSION['doc_id']
    ]);

    $success = "Profile updated successfully.";
}

/* ================= DOCTOR INFO ================= */
$stmt = $db->prepare("SELECT * FROM doctors WHERE doc_id = ?");
$stmt->execute([$_SESSION['doc_id']]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doctor) die("Doctor not found.");

/* ================= HEALTH TIPS ================= */
$healthTips = [
    "Drink at least 8 glasses of water daily to stay hydrated.",
    "Take short walks during breaks to improve circulation.",
    "Wash your hands frequently to prevent infections.",
    "Maintain proper posture to avoid back and neck pain.",
    "Eat fruits and vegetables rich in vitamins every day.",
    "Get at least 7–8 hours of quality sleep.",
    "Practice deep breathing to reduce stress levels.",
    "Limit sugary drinks and choose water instead.",
    "Stretch your body lightly before starting work.",
    "Avoid skipping meals to maintain energy levels.",
    "Reduce salt intake to help manage blood pressure.",
    "Take screen breaks to protect your eyes.",
    "Regular exercise improves both mental and physical health.",
    "Practice good hygiene at all times.",
    "Balance work and rest to avoid burnout.",
    "Manage stress through relaxation or meditation.",
    "Choose whole grains over refined carbohydrates.",
    "Avoid excessive caffeine intake.",
    "Keep a healthy work-life balance.",
    "Schedule regular health check-ups."
];

/* ================= MOTIVATIONAL QUOTES ================= */
$motivationQuotes = [
    "Every day is a chance to become better than yesterday.",
    "Your work makes a difference — never underestimate it.",
    "Small progress is still progress.",
    "Consistency beats motivation.",
    "Take care of yourself so you can take care of others.",
    "Great things are built one step at a time.",
    "Your dedication helps save lives.",
    "Do what you can, with what you have, today.",
    "Success is the sum of small efforts repeated daily.",
    "A healthy caregiver creates healthy patients.",
    "You are stronger than you think.",
    "Make today count.",
    "Your effort today shapes tomorrow.",
    "Care begins with compassion — including for yourself.",
    "Believe in the impact of your work."
];

/* ================= DAILY ROTATION ================= */
$dayIndex   = date('z');
$dailyTip   = $healthTips[$dayIndex % count($healthTips)];
$dailyQuote = $motivationQuotes[$dayIndex % count($motivationQuotes)];
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
body { background: #f1f3f6; }

.profile-card {
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(0,0,0,.1);
}

.profile-header {
    background: linear-gradient(135deg, #062e6b, #0a58ca);
    color: #fff;
    padding: 18px;
    text-align: center;
    font-weight: 600;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px dashed #ddd;
}

.stat-box {
    border-radius: 12px;
    padding: 20px;
    color: #fff;
    text-align: center;
}

.bg-blue { background: #0d6efd; }
.bg-green { background: #198754; }
.bg-orange { background: #fd7e14; }

.tip-card {
    background: linear-gradient(135deg, #e8f5e9, #ffffff);
    border-left: 6px solid #198754;
}

.quote-card {
    background: linear-gradient(135deg, #fff3cd, #ffffff);
    border-left: 6px solid #ffc107;
}

.sb-sidenav .nav-link.active {
    background-color: #062e6bff !important;
    color: #fff !important;
    font-weight: 600;
}
</style>
</head>

<body class="sb-nav-fixed">

<?php include "../Includes/header.html"; ?>
<?php include "../Includes/navbar_doctor.html"; ?>

<div id="layoutSidenav">
<div id="layoutSidenav_nav">
<?php include "../Includes/doctorSidebar.php"; ?>
</div>

<div id="layoutSidenav_content">
<main class="container-fluid px-4 py-4">

<?php if (!empty($success)): ?>
<div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<div class="row g-4">

<!-- LEFT PROFILE -->
<div class="col-lg-4">
<div class="card profile-card">
<div class="profile-header">
<i class="fa fa-user me-2"></i> My Profile
</div>

<div class="card-body text-center">
<h5 class="fw-bold mb-1">
<?= htmlspecialchars($doctor['doc_fullname']) ?>
</h5>
<small class="text-muted"><?= htmlspecialchars($_SESSION['role']) ?></small>

<hr>

<div class="text-start">
<div class="info-row"><span>Email</span><span><?= htmlspecialchars($doctor['doc_email']) ?></span></div>
<div class="info-row"><span>Contact</span><span><?= htmlspecialchars($doctor['doc_contact_num']) ?></span></div>
<div class="info-row"><span>Created</span><span><?= htmlspecialchars($doctor['doc_created_at']) ?></span></div>
<div class="info-row"><span>Updated</span><span><?= htmlspecialchars($doctor['doc_updated_at']) ?></span></div>
</div>

<button class="btn btn-primary w-100 mt-3" data-bs-toggle="modal" data-bs-target="#editProfileModal">
<i class="fa fa-edit"></i> Edit Profile
</button>
</div>
</div>
</div>

<!-- RIGHT CONTENT -->
<div class="col-lg-8">

<div class="row g-4 mb-4">
<div class="col-md-4">
<div class="stat-box bg-blue">
<i class="fa fa-user"></i>
<h6>Account</h6>
<strong><?= $_SESSION['role'] ?></strong>
</div>
</div>

<div class="col-md-4">
<div class="stat-box bg-green">
<i class="fa fa-clock"></i>
<h6>Today</h6>
<strong><?= date('M d, Y') ?></strong>
</div>
</div>

<div class="col-md-4">
<div class="stat-box bg-orange">
<i class="fa fa-heartbeat"></i>
<h6>Tip #</h6>
<strong><?= ($dayIndex % count($healthTips)) + 1 ?></strong>
</div>
</div>
</div>

<div class="card tip-card mb-3">
<div class="card-body">
<h5><i class="fa fa-heartbeat text-success me-1"></i> Daily Health Tip</h5>
<p class="fs-5 mb-0"><?= htmlspecialchars($dailyTip) ?></p>
</div>
</div>

<div class="card quote-card">
<div class="card-body">
<h5><i class="fa fa-quote-left text-warning me-1"></i> Motivation for Today</h5>
<p class="fs-5 fst-italic mb-0"><?= htmlspecialchars($dailyQuote) ?></p>
</div>
</div>

</div>
</div>

</main>

<?php include "../Includes/footer.html"; ?>
</div>
</div>

<!-- ================= EDIT PROFILE MODAL ================= -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">

<form method="POST">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title">
<i class="fa fa-user-edit me-2"></i>Edit Profile
</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<div class="mb-3">
<label class="form-label">Full Name</label>
<input type="text" name="doctor_fullname" class="form-control" required
value="<?= htmlspecialchars($doctor['doc_fullname']) ?>">
</div>

<div class="mb-3">
<label class="form-label">Email</label>
<input type="email" name="doctor_email" class="form-control" required
value="<?= htmlspecialchars($doctor['doc_email']) ?>">
</div>

<div class="mb-3">
<label class="form-label">Contact Number</label>
<input type="text" name="doctor_contact_num" class="form-control" required
value="<?= htmlspecialchars($doctor['doc_contact_num']) ?>">
</div>

</div>

<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
<i class="fa fa-times"></i> Cancel
</button>
<button type="submit" name="update_profile" class="btn btn-primary">
<i class="fa fa-save"></i> Save Changes
</button>
</div>

</form>

</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>