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
require_once "../class/staff.php";

$database = new Database();
$db = $database->connect();
$staff = new Staff($db);

$rows = $staff->all();

// UPDATE STAFF
if (isset($_POST['update_staff'])) {
    $STAFF_ID = $_POST['staff_id'];
    $STAFF_FNAME = $_POST['staff_first_name'];
    $STAFF_MID_INIT = $_POST['staff_middle_init'];
    $STAFF_LNAME = $_POST['staff_last_name'];
    $STAFF_CONTACT = $_POST['staff_contact_num'];
    $STAFF_EMAIL = $_POST['staff_email'];

    if ($staff->update($STAFF_ID, $STAFF_FNAME, $STAFF_LNAME, $STAFF_MID_INIT, $STAFF_CONTACT, $STAFF_EMAIL)) {
        $rows = $staff->all();
    } else {
        echo "<script>alert('❌ Error updating staff.'); window.location='../public/staff.php';</script>";
    }
}

// DELETE STAFF
if (isset($_GET['delete'])) {
    $STAFF_ID = $_GET['delete'];
    if ($staff->delete($STAFF_ID)) {
        $rows = $staff->all();
    } else {
        echo "<script>alert('❌ Error deleting staff.'); window.location='../public/staff.php';</script>";
    }
}


?>

<main>
  <div class="container-fluid px-4">
    <h1 class="mt-4">Staff Management</h1>
    <ol class="breadcrumb mb-4">
      <li class="breadcrumb-item active">Staff Dashboard</li>
    </ol>

    <?php require_once "../public/staff/staffAdd.php"?>

    <!-- STAFF TABLE -->
    <div class="card mb-4">
      <div class="card-header bg-light">
        <i class="fas fa-table me-1"></i> Staff List
      </div>
      <div class="card-body">
        <table id="datatablesSimple" class="table table-bordered table-hover align-middle">
          <thead class="table-primary">
            <tr>
              <th>ID</th>
              <th>First Name</th>
              <th>Last Name</th>
              <th>M.I.</th>
              <th>Contact</th>
              <th>Email</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($rows)): ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['staff_id']) ?></td>
                  <td><?= htmlspecialchars($row['staff_first_name']) ?></td>
                  <td><?= htmlspecialchars($row['staff_last_name']) ?></td>
                  <td><?= htmlspecialchars($row['staff_middle_init']) ?></td>
                  <td><?= htmlspecialchars($row['staff_contact_num']) ?></td>
                  <td><?= htmlspecialchars($row['staff_email']) ?></td>
                  <td>
                      <!-- EDIT BUTTON -->
                      <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['staff_id'] ?>">
                          <i class="fas fa-edit"></i> Edit
                      </button>

                      <!-- DELETE BUTTON -->
                      <a href="?delete=<?= $row['staff_id'] ?>" class="btn btn-sm btn-danger"
                          onclick="return confirm('Are you sure you want to delete this staff?');">
                          <i class="fas fa-trash-alt"></i> Delete
                      </a>
                  </td>
                </tr>

                <!-- EDIT MODAL -->
                <div class="modal fade" id="editModal<?= $row['staff_id'] ?>" tabindex="-1">
                  <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                      <form method="POST">
                        <div class="modal-header bg-warning text-white">
                          <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Staff</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="staff_id" value="<?= $row['staff_id'] ?>">
                          <div class="row g-3">
                            <div class="col-md-4">
                              <label class="form-label">First Name</label>
                              <input type="text" class="form-control" name="staff_first_name" value="<?= $row['staff_first_name'] ?>" required>
                            </div>
                            <div class="col-md-4">
                              <label class="form-label">Middle Initial</label>
                              <input type="text" class="form-control" name="staff_middle_init" value="<?= $row['staff_middle_init'] ?>">
                            </div>
                            <div class="col-md-4">
                              <label class="form-label">Last Name</label>
                              <input type="text" class="form-control" name="staff_last_name" value="<?= $row['staff_last_name'] ?>" required>
                            </div>
                            <div class="col-md-6">
                              <label class="form-label">Contact Number</label>
                              <input type="text" class="form-control" name="staff_contact_num" value="<?= $row['staff_contact_num'] ?>" required>
                            </div>
                            <div class="col-md-6">
                              <label class="form-label">Email</label>
                              <input type="email" class="form-control" name="staff_email" value="<?= $row['staff_email'] ?>" required>
                            </div>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="submit" name="update_staff" class="btn btn-success">Save Changes</button>
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" class="text-center text-danger">No staff found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<?php require_once "../includes/footer.php"; ?>
