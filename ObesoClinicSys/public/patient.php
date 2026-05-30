<?php
session_start();

/* 🔒 BLOCK ACCESS */
if (!isset($_SESSION['user_id'])) {
  header("Location: /login_page.php");
  exit;
}

/* 🔒 ANTI-BACK CACHE HEADERS */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
require_once "../config/db.php";
require_once "../class/patient.php";

$database = new Database();
$db = $database->connect();
$patient = new Patient($db);

/* ======================
   FETCH ALL PATIENTS
====================== */
$rows = $patient->viewAll();

/* ======================
   UPDATE PATIENT
====================== */
if (isset($_POST['update_patient'])) {

  $patient_id = $_POST['pat_id'];

  // ✅ Combine name fields → fullname
  $full_name = trim(
    $_POST['pat_first_name'] . ' ' .
      $_POST['pat_middle_init'] . ' ' .
      $_POST['pat_last_name']
  );

  $address = $_POST['pat_address'];
  $birthday = $_POST['pat_dob'];
  $sex = $_POST['pat_gender'];
  $contact_number = $_POST['pat_contact_num'];

  // ✅ Auto-calculate age
  $age = date_diff(
    date_create($birthday),
    date_create(date("Y-m-d"))
  )->y;

  // ✅ Keep existing values not in form
  $existing = $patient->getById($patient_id);

  $civil_status = $existing['civil_status'];
  $religion = $existing['religion'];
  $occupation = $existing['occupation'];
  $contact_person = $existing['contact_person'];
  $contact_person_age = $existing['contact_person_age'];

  if ($patient->update(
    $patient_id,
    $full_name,
    $address,
    $birthday,
    $age,
    $sex,
    $civil_status,
    $religion,
    $occupation,
    $contact_person,
    $contact_person_age,
    $contact_number
  )) {
    $rows = $patient->viewAll();
  } else {
    echo "<script>alert('❌ Error updating patient');</script>";
  }
}
?>

<main>
  <div class="container-fluid px-4">
    <h1 class="mt-4">Patient Management</h1>
    <ol class="breadcrumb mb-4">
      <li class="breadcrumb-item active">Patient CRUD</li>
    </ol>

    <?php require_once "../public/patient/patientAdd.php"; ?>

    <div class="card mb-4">
      <div class="card-header bg-light">
        <i class="fas fa-table me-1"></i> Patient List
      </div>
      <div class="card-body">
        <table id="datatablesSimple" class="table table-bordered table-hover align-middle">
          <thead class="table-primary">
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Address</th>
              <th>Date Of Birth</th>
              <th>Age</th>
              <th>Gender</th>
              <th>Civil Status</th>
              <th>Religion</th>
              <th>Occupation</th>
              <th>Contact Person</th>
              <th>Contact Person Age</th>
              <th>Contact Number</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($rows)): ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['patient_id']) ?></td>
                  <td><?= htmlspecialchars($row['full_name']) ?></td>
                  <td><?= htmlspecialchars($row['address']) ?></td>
                  <td><?= htmlspecialchars($row['birthday']) ?></td>
                  <td><?= htmlspecialchars($row['age']) ?></td>
                  <td><?= htmlspecialchars($row['sex']) ?></td>
                  <td><?= htmlspecialchars($row['civil_status']) ?></td>
                  <td><?= htmlspecialchars($row['religion']) ?></td>
                  <td><?= htmlspecialchars($row['occupation']) ?></td>
                  <td><?= htmlspecialchars($row['contact_person']) ?></td>
                  <td><?= htmlspecialchars($row['contact_person_age']) ?></td>
                  <td><?= htmlspecialchars($row['contact_number']) ?></td>
                  <td>
                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['patient_id'] ?>">
                      <i class="fas fa-edit"></i> Edit
                    </button>
                  </td>
                </tr>

                <!-- EDIT MODAL -->
                <div class="modal fade" id="editModal<?= $row['patient_id'] ?>" tabindex="-1">
                  <div class="modal-dialog modal-lg">
                    <div class="modal-content">

                      <form method="POST">

                        <div class="modal-header bg-warning text-white">
                          <h5 class="modal-title">Edit Patient</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">

                          <input type="hidden" name="pat_id" value="<?= $row['patient_id'] ?>">

                          <div class="row g-3">

                            <!-- FULL NAME -->
                            <div class="col-md-12">
                              <label>Full Name</label>
                              <input type="text"
                                class="form-control"
                                name="full_name"
                                value="<?= htmlspecialchars($row['full_name']) ?>">
                            </div>

                            <!-- BIRTHDAY -->
                            <div class="col-md-4">
                              <label>Birthday</label>
                              <input type="date"
                                class="form-control"
                                name="birthday"
                                value="<?= $row['birthday'] ?>">
                            </div>

                            <!-- AGE -->
                            <div class="col-md-4">
                              <label>Age</label>
                              <input type="number"
                                class="form-control"
                                name="age"
                                value="<?= $row['age'] ?>">
                            </div>

                            <!-- GENDER -->
                            <div class="col-md-4">
                              <label>Gender</label>
                              <select class="form-select" name="sex">
                                <option value="Male" <?= $row['sex'] == 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $row['sex'] == 'Female' ? 'selected' : '' ?>>Female</option>
                              </select>
                            </div>

                            <!-- CIVIL STATUS -->
                            <div class="col-md-4">
                              <label>Civil Status</label>
                              <select class="form-select" name="civil_status">
                                <option value="Single" <?= $row['civil_status'] == 'Single' ? 'selected' : '' ?>>Single</option>
                                <option value="Married" <?= $row['civil_status'] == 'Married' ? 'selected' : '' ?>>Married</option>
                                <option value="Widowed" <?= $row['civil_status'] == 'Widowed' ? 'selected' : '' ?>>Widowed</option>
                                <option value="Divorced" <?= $row['civil_status'] == 'Divorced' ? 'selected' : '' ?>>Divorced</option>
                              </select>
                            </div>

                            <!-- RELIGION -->
                            <div class="col-md-4">
                              <label>Religion</label>
                              <input type="text"
                                class="form-control"
                                name="religion"
                                value="<?= htmlspecialchars($row['religion']) ?>">
                            </div>

                            <!-- OCCUPATION -->
                            <div class="col-md-4">
                              <label>Occupation</label>
                              <input type="text"
                                class="form-control"
                                name="occupation"
                                value="<?= htmlspecialchars($row['occupation']) ?>">
                            </div>

                            <!-- CONTACT PERSON -->
                            <div class="col-md-6">
                              <label>Contact Person</label>
                              <input type="text"
                                class="form-control"
                                name="contact_person"
                                value="<?= htmlspecialchars($row['contact_person']) ?>">
                            </div>

                            <!-- CONTACT PERSON AGE -->
                            <div class="col-md-6">
                              <label>Contact Person Age</label>
                              <input type="number"
                                class="form-control"
                                name="contact_person_age"
                                value="<?= $row['contact_person_age'] ?>">
                            </div>

                            <!-- CONTACT NUMBER -->
                            <div class="col-md-6">
                              <label>Contact Number</label>
                              <input type="text"
                                class="form-control"
                                name="contact_number"
                                value="<?= $row['contact_number'] ?>">
                            </div>

                            <!-- ADDRESS -->
                            <div class="col-md-12">
                              <label>Address</label>
                              <textarea class="form-control"
                                name="address"><?= $row['address'] ?></textarea>
                            </div>

                          </div>

                        </div>

                        <div class="modal-footer">
                          <button type="submit" name="update_patient" class="btn btn-success">
                            Save Changes
                          </button>
                        </div>

                      </form>

                    </div>
                  </div>
                </div>

              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="13" class="text-center text-danger">No patients found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>