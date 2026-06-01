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
require_once "../class/followup.php";

$db = (new Database())->connect();
$followup = new Followup($db);

/* ================= FETCH FOLLOWUPS ================= */
$rows = $followup->viewAll();

/* ================= UPDATE FOLLOWUP ================= */
if (isset($_POST['update_followup'])) {
    $followup->update(
        $_POST['followup_id'],
        $_POST['followup_date'],
        $_POST['notes'],
        $_POST['status']
    );
    $rows = $followup->viewAll();
}

/* ================= DELETE FOLLOWUP ================= */
if (isset($_POST['delete_followup'])) {
    $followup->delete($_POST['followup_id']);
    $rows = $followup->viewAll();
}
?>

<main>
    <div class="container-fluid px-4">

        <h1 class="mt-4">Follow-Ups Management</h1>
        <ol class="breadcrumb mb-4">
            <li class="breadcrumb-item active">Follow-Ups</li>
        </ol>

        <div class="card mb-4">
            <div class="card-header bg-light">
                <i class="fas fa-table me-1"></i> Follow-Ups List
            </div>

            <div class="card-body">
                <table id="datatablesSimple"
                    class="table table-bordered table-hover align-middle">

                    <thead class="table-primary">
                        <tr>
                            <th>ID</th>
                            <th>Patient</th>
                            <th>Doctor</th>
                            <th>Checkup Date</th>
                            <th>Follow-Up Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($rows)): foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= $row['followup_id'] ?></td>
                                    <td><?= htmlspecialchars($row['patient_name']) ?></td>
                                    <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                                    <td><?= $row['checkup_date'] ?? '-' ?></td>
                                    <td><?= $row['followup_date'] ?></td>
                                    <td>
                                        <span class="badge 
      <?= $row['status'] == 'Completed' ? 'bg-success' : ($row['status'] == 'Missed' ? 'bg-danger' : 'bg-warning') ?>">
                                            <?= $row['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-warning"
                                            data-bs-toggle="modal"
                                            data-bs-target="#edit<?= $row['followup_id'] ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>

                                        <form method="POST" class="d-inline"
                                            onsubmit="return confirm('Delete this follow-up?')">
                                            <input type="hidden" name="followup_id"
                                                value="<?= $row['followup_id'] ?>">
                                            <button name="delete_followup"
                                                class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>

                                <!-- ================= EDIT MODAL ================= -->
                                <div class="modal fade" id="edit<?= $row['followup_id'] ?>">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">

                                                <div class="modal-header bg-warning text-white">
                                                    <h5 class="modal-title">Edit Follow-Up</h5>
                                                    <button class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>

                                                <div class="modal-body">
                                                    <input type="hidden" name="followup_id"
                                                        value="<?= $row['followup_id'] ?>">

                                                    <div class="mb-3">
                                                        <label class="form-label">Follow-Up Date</label>
                                                        <input type="date"
                                                            name="followup_date"
                                                            class="form-control"
                                                            value="<?= $row['followup_date'] ?>" required>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Status</label>
                                                        <select name="status" class="form-select">
                                                            <option value="Pending" <?= $row['status'] == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                            <option value="Completed" <?= $row['status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                                            <option value="Missed" <?= $row['status'] == 'Missed' ? 'selected' : '' ?>>Missed</option>
                                                        </select>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Notes</label>
                                                        <textarea name="notes"
                                                            class="form-control"><?= $row['notes'] ?></textarea>
                                                    </div>
                                                </div>

                                                <div class="modal-footer">
                                                    <button name="update_followup"
                                                        class="btn btn-success">
                                                        Save Changes
                                                    </button>
                                                    <button type="button"
                                                        class="btn btn-secondary"
                                                        data-bs-dismiss="modal">
                                                        Cancel
                                                    </button>
                                                </div>

                                            </form>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td colspan="7"
                                    class="text-center text-danger">
                                    No follow-ups found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                </table>
            </div>
        </div>
    </div>
</main>