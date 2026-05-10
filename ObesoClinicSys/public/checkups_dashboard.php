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
require_once "../class/checkups.php";

$db = (new Database())->connect();
$checkup = new Checkup($db);

/* ================= FILTER HANDLING ================= */
$doctor_id = $_GET['doctor_id'] ?? null;
$date = $_GET['checkup_date'] ?? null;

$rows = $checkup->filter($doctor_id, $date);

/* ================= DELETE ================= */
if (isset($_POST['delete_checkup'])) {
    if ($checkup->delete($_POST['checkup_id'])) {
        echo "<script>window.location.href='checkups_dashboard.php';</script>";
        exit;
    } else {
        echo "<script>alert('❌ Error deleting checkup');</script>";
    }
}
?>

<main>
  <div class="container-fluid px-4">
    <h1 class="mt-4">Checkup Management</h1>
    <ol class="breadcrumb mb-4">
      <li class="breadcrumb-item active">Checkups</li>
    </ol>

        <!-- CHECKUPS TABLE -->
 <div class="card mb-4">
  <div class="card-header bg-light">
    <i class="fas fa-table me-1"></i> Checkup List
  </div>

  <div class="card-body">
    <table id="datatablesSimple"
           class="table table-bordered table-hover align-middle">

      <thead class="table-primary">
        <tr>
          <th>ID</th>
          <th>Patient Name</th>
          <th>Doctor</th>
          <th>Checkup Date</th>
          <th>Diagnosis</th>
          <th>Actions</th>
        </tr>
      </thead>

      <tbody>
        <?php if (!empty($rows)): ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['checkup_id']) ?></td>
              <td><?= htmlspecialchars($row['patient_name']) ?></td>
              <td><?= htmlspecialchars($row['doc_fullname']) ?></td>
              <td><?= htmlspecialchars($row['checkup_date']) ?></td>
              <td><?= htmlspecialchars($row['diagnosis']) ?></td>

              <td>
                <!-- VIEW -->
                <button class="btn btn-sm btn-info"
                        data-bs-toggle="modal"
                        data-bs-target="#viewModal<?= $row['checkup_id'] ?>">
                    <i class="fas fa-eye"></i> View
                </button>

                <!-- DELETE -->
                <form method="POST"
                      class="d-inline"
                      onsubmit="return confirm('Delete this checkup record?')">
                    <input type="hidden"
                          name="checkup_id"
                          value="<?= $row['checkup_id'] ?>">
                    <button type="submit"
                            name="delete_checkup"
                            class="btn btn-sm btn-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </td>
            </tr>

            <!-- ================= VIEW MODAL ================= -->
            <div class="modal fade"
                 id="viewModal<?= $row['checkup_id'] ?>"
                 tabindex="-1">
              <div class="modal-dialog modal-lg">
                <div class="modal-content">

                  <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                      <i class="fas fa-notes-medical me-2"></i>Checkup Details
                    </h5>
                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>
                  </div>

                  <div class="modal-body">
                    <p><strong>Chief Complaint:</strong> <?= htmlspecialchars($row['chief_complaint']) ?></p>
                    <p><strong>History of Present Illness:</strong> <?= htmlspecialchars($row['history_present_illness']) ?></p>
                    <p><strong>Diagnosis:</strong> <?= htmlspecialchars($row['diagnosis']) ?></p>
                    <hr>
                    <p><strong>Blood Pressure:</strong> <?= htmlspecialchars($row['blood_pressure']) ?></p>
                    <p><strong>Heart Rate:</strong> <?= htmlspecialchars($row['heart_rate']) ?></p>
                    <p><strong>Respiratory Rate:</strong> <?= htmlspecialchars($row['respiratory_rate']) ?></p>
                    <p><strong>Temperature:</strong> <?= htmlspecialchars($row['temperature']) ?></p>
                    <p><strong>Weight:</strong> <?= htmlspecialchars($row['weight']) ?></p>
                  </div>

                </div>
              </div>
            </div>

          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="6"
                class="text-center text-danger">
              No checkups found.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>

    </table>
  </div>
</div>


    </div>
</main>