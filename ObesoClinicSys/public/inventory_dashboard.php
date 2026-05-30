
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
$rows = $inventory->viewAll();

/* ======================
   UPDATE INVENTORY
====================== */
if (isset($_POST['update_inventory'])) {
    $inventory_id = $_POST['inventory_id'];
    $quantity = intval($_POST['quantity']);
    $expiry_date = $_POST['expiry_date'];

    if ($inventory->updateInventory($inventory_id, $quantity, $expiry_date)) {
        $rows = $inventory->viewAll();
    } else {
        echo "<script>alert('❌ Error updating inventory');</script>";
    }
}
?>

<main>
    <div class="container-fluid px-4">
        <h1 class="mt-4">Medicine Inventory Management</h1>
        <ol class="breadcrumb mb-4">
            <li class="breadcrumb-item active">Inventory CRUD</li>
        </ol>

        <!-- ADD INVENTORY FORM -->
    <div class="d-flex gap-2 mb-3">
        <?php require_once "../public/addMedication.php"; ?>
        <?php require_once "../public/addInventory.php"; ?>
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
                            <th>Medicine</th>
                            <th>Quantity</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row):
                                // Determine medicine name
                                $med = $medications->getMedicationById($row['medication_id']);
                                $medicine_name = $row['generic_name'] . " (" . $row['brand_name'] . ")";

                                // Determine status
                                $today = date("Y-m-d");
                                if ($row['expiry_date'] < $today) {
                                    $status = "<span class='text-danger'>Expired</span>";
                                } elseif ($row['quantity'] <= $row['reorder_level']) {
                                    $status = "<span class='text-warning'>Low Stock</span>";
                                } else {
                                    $status = "<span class='text-success'>Normal</span>";
                                }
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['inventory_id']) ?></td>
                                    <td><?= htmlspecialchars($medicine_name) ?></td>
                                    <td><?= htmlspecialchars($row['quantity']) ?></td>
                                    <td><?= htmlspecialchars($row['expiry_date']) ?></td>
                                    <td><?= $status ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['inventory_id'] ?>">
                                            <i class="fas fa-edit"></i>Edit
                                        </button>
                                    </td>
                                </tr>

                                <!-- EDIT MODAL -->
                                <div class="modal fade" id="editModal<?= $row['inventory_id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <div class="modal-header bg-warning text-white">
                                                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Inventory</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="inventory_id" value="<?= $row['inventory_id'] ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label">Quantity</label>
                                                        <input type="number" class="form-control" name="quantity" value="<?= $row['quantity'] ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Expiry Date</label>
                                                        <input type="date" class="form-control" name="expiry_date" value="<?= $row['expiry_date'] ?>" required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="submit" name="update_inventory" class="btn btn-success">Save Changes</button>
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-danger">No medicines found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>