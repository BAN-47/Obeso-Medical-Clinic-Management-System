
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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
require_once "../class/medicineInventory.php";
require_once "../class/medications.php";

$database = new Database();
$db = $database->connect();

$inventory = new MedicineInventory($db);
$medications = new Medications($db);

/* ======================
   FETCH ALL INVENTORY
====================== */
$rows = $medications->getAllMedications();

/* ======================
   UPDATE INVENTORY
====================== */
if (isset($_POST['update_medication'])) {
    $result = $medications->updateMedication(
        intval($_POST['medication_id']),
        trim($_POST['generic_name']),
        trim($_POST['brand_name']),
        trim($_POST['category']),
        trim($_POST['preparation']),
        trim($_POST['volume_bottle']),
        floatval($_POST['unit_price'])
    );
    if (!$result) {
        echo "<script>alert('❌ Error updating medication.');</script>";
    }
}


$rows = $medications->getAllMedications();
?>

<main>
    <div class="container-fluid px-4">
        <h1 class="mt-4">Medications Management</h1>
        <ol class="breadcrumb mb-4">
            <li class="breadcrumb-item active">Medications CRUD</li>
        </ol>

        <!-- ADD INVENTORY FORM -->
    <div class="d-flex gap-2 mb-3">
        <?php require_once "../public/addMedication.php"; ?>
    </div>

        <div class="card mb-4">
            <div class="card-header bg-light">
                <i class="fas fa-table me-1"></i> Inventory List
            </div>
            <div class="card-body">
                <table id="datatablesSimple" class="table table-bordered table-hover align-middle">
                    <thead class="table-primary">
 <tr>
                        <th>ID</th>
                        <th>Generic Name</th>
                        <th>Brand Name</th>
                        <th>Category</th>
                        <th>Preparation</th>
                        <th>Volume / Bottle</th>
                        <th>Unit Price</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                                   <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['medication_id']) ?></td>
                                <td><?= htmlspecialchars($row['generic_name']) ?></td>
                                <td><?= htmlspecialchars($row['brand_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['category'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['preparation'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['volume/bottle'] ?? '—') ?></td>
                                <td>₱<?= number_format($row['unit_price'], 2) ?></td>
                                <td class="d-flex gap-1">
                                    <button class="btn btn-sm btn-warning"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editModal<?= $row['medication_id'] ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete this medication?')">
                                        <input type="hidden" name="medication_id" value="<?= $row['medication_id'] ?>">

                                    </form>
                                </td>
                            </tr>

                            <!-- EDIT MODAL -->
                            <div class="modal fade" id="editModal<?= $row['medication_id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header bg-warning text-white">
                                                <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit Medication</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="medication_id" value="<?= $row['medication_id'] ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Generic Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="generic_name"
                                                        value="<?= htmlspecialchars($row['generic_name']) ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Brand Name</label>
                                                    <input type="text" class="form-control" name="brand_name"
                                                        value="<?= htmlspecialchars($row['brand_name'] ?? '') ?>">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Category</label>
                                                    <input type="text" class="form-control" name="category"
                                                        value="<?= htmlspecialchars($row['category'] ?? '') ?>">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Preparation</label>
                                                    <input type="text" class="form-control" name="preparation"
                                                        value="<?= htmlspecialchars($row['preparation'] ?? '') ?>">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Volume / Bottle</label>
                                                    <input type="text" class="form-control" name="volume_bottle"
                                                        value="<?= htmlspecialchars($row['volume/bottle'] ?? '') ?>">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Unit Price</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₱</span>
                                                        <input type="number" step="0.01" min="0" class="form-control"
                                                            name="unit_price"
                                                            value="<?= htmlspecialchars($row['unit_price']) ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" name="update_medication" class="btn btn-success">
                                                    <i class="fas fa-save me-1"></i> Save Changes
                                                </button>
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-danger">No medications found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>