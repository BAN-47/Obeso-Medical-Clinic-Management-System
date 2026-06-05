<?php
require_once "../config/db.php";
require_once "../class/doctor.php";
require_once "../class/user.php";

$database = new Database();
$db = $database->connect();
$doctor = new Doctor($db);
$user = new User($db);

// VIEW ALL DOCTORS
$rows = $doctor->getAllDoctors();

// ADD DOCTOR
if (isset($_POST['add_doctor'])) {
    $fname    = trim($_POST['doc_fullname']);
    $contact  = trim($_POST['doc_contact_num']);
    $email    = trim($_POST['doc_email']);

    if ($doctor->addDoctor($fname, $contact, $email,)) {
        $lastdoctorID = $db->lastInsertId(); // Get last staff ID(PDOmethod)
        $rows = $doctor->getAllDoctors();
      echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                var accountModal = new bootstrap.Modal(document.getElementById('accountModal'));
                accountModal.show();
            });
        </script>";
    } else {
        echo "<script>alert('❌ Error adding doctor.'); window.location='../public/doctor_dashboard.php';</script>";
    }
}

// CREATE ACCOUNT
if (isset($_POST["create_account"])) {
    $username = $_POST["username"];
    $password = $_POST["password"];
    $doc_id   = intval($_POST["doc_id"]);

    $result = $user->create($username, $password, null, $doc_id);

    if ($result === "DUPLICATE_USERNAME") {
        echo "<script>alert('❌ Username already exists.');</script>";
    } elseif ($result === true) {
        echo "<script>alert('✅ Doctor account created.'); window.location='../public/doctor_dashboard.php';</script>";
    } else {
        echo "<script>alert('❌ Error creating account.'); window.location='../public/doctor_dashboard.php';</script>";
    }
}
?>

<!-- ADD DOCTOR BUTTON -->
<div class="mb-3">
  <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDoctorModal">
    <i class="fa-solid fa-user-md"></i> Add New Doctor
  </button>
</div>

<!-- ADD DOCTOR MODAL -->
<div class="modal fade" id="addDoctorModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="fas fa-user-md me-2"></i>Add Doctor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Full Name</label>
              <input type="text" class="form-control" name="doc_fullname" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Contact Number</label>
              <input type="text" class="form-control" name="doc_contact_num" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="doc_email" required>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="submit" name="add_doctor" class="btn btn-success"><i class="fas fa-forward me-1"></i>Next</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ACCOUNT CREATION MODAL -->
<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-user-cog me-2"></i>Create Staff Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- PASS doc ID -->
                    <input type="hidden" name="doc_id" value="<?= $lastdoctorID ?>">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="create_account" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Create Account
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>