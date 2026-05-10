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

require_once "../config/db.php";
require_once "../class/billings.php";

$db = (new Database())->connect();
$billing = new Billing($db);

/* ================= UPDATE BILLING ================= */
if (isset($_POST['update_billing'])) {
    $bill_id = $_POST['bill_id'];
    $status = $_POST['payment_status'];
    $method = $_POST['payment_method'];

    $billing->update($bill_id, $status, $method);
        echo "<script>window.location.href='billings_dashboard.php';</script>";
    exit;
}

/* ================= DELETE BILLING ================= */
if (isset($_POST['delete_billing'])) {
    $billing->delete($_POST['bill_id']);
    echo "<script>window.location.href='billings_dashboard.php';</script>";
    exit;
}

/* ================= FETCH ALL BILLINGS ================= */
$rows = $billing->viewAll();

require_once "../includes/header.php";
require_once "../includes/sidebar.php";
?>

<main>
<div class="container-fluid px-4">
    <h1 class="mt-4">Billing Management</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item active">Billing Records</li>
    </ol>

    <div class="card mb-4">
      <div class="card-header bg-light">
        <i class="fas fa-table me-1"></i> Billing List
      </div>
      <div class="card-body">
        <table id="datatablesSimple" class="table table-bordered table-hover align-middle">
            <thead class="table-primary">
                <tr>
                    <th>ID</th>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Total Amount</th>
                    <th>Payment Status</th>
                    <th>Payment Method</th>
                    <th>Date Billed</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['bill_id']) ?></td>
                    <td><?= htmlspecialchars($row['patient_name']) ?></td>
                    <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                    <td><?= number_format($row['total_amount'], 2) ?></td>
                    <td><?= htmlspecialchars($row['payment_status']) ?></td>
                    <td><?= htmlspecialchars($row['payment_method']) ?></td>
                    <td><?= htmlspecialchars($row['billed_at']) ?></td>
                    <td>
                        <!-- EDIT -->
                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['bill_id'] ?>">
                            <i class="fas fa-edit"></i> Edit
                        </button>

                        <!-- DELETE -->
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this billing record?')">
                            <input type="hidden" name="bill_id" value="<?= $row['bill_id'] ?>">
                            <button name="delete_billing" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </td>
                </tr>

                <!-- EDIT MODAL -->
                <div class="modal fade" id="editModal<?= $row['bill_id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header bg-warning text-white">
                                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Billing</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="bill_id" value="<?= $row['bill_id'] ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Payment Status</label>
                                        <select class="form-select" name="payment_status">
                                            <option value="Unpaid" <?= $row['payment_status'] == 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                            <option value="Paid" <?= $row['payment_status'] == 'Paid' ? 'selected' : '' ?>>Paid</option>
                                            <option value="Partial" <?= $row['payment_status'] == 'Partial' ? 'selected' : '' ?>>Partial</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Payment Method</label>
                                        <input type="text" class="form-control" name="payment_method" value="<?= htmlspecialchars($row['payment_method']) ?>">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" name="update_billing" class="btn btn-success">Save Changes</button>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="text-center text-danger">No billing records found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
      </div>
    </div>
</div>
</main>
