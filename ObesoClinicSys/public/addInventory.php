<?php

require_once "../config/db.php";
require_once "../class/medicineInventory.php";
require_once "../class/medications.php"; // To fetch all available medications

$database = new Database();
$db = $database->connect();
$inventory = new MedicineInventory($db);
$medications = new Medications($db);

$rows = $inventory->viewAll(); // fetch all inventory items
$lastInventoryID = null;

// -------------------------
// ADD INVENTORY
// -------------------------
if (isset($_POST['add_inventory'])) {
    $medication_id = $_POST['medication_id'];
    $quantity      = intval($_POST['quantity']);
    $expiry_date   = $_POST['expiry_date'];
    $reorder_level = intval($_POST['reorder_level']);

    if ($inventory->addMedicine($medication_id, $quantity, $expiry_date, $reorder_level)) {
        $lastInventoryID = $db->lastInsertId();
        $rows = $inventory->viewAll();

        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                var successModal = new bootstrap.Modal(document.getElementById('successModal'));
                successModal.show();
            });
        </script>";
    } else {
        echo "<script>alert('❌ Error adding inventory.'); window.location='../public/admin_inventory.php';</script>";
    }
}
?>

<!-- BUTTON TO OPEN ADD INVENTORY MODAL -->
<div class="mb-3">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fa-solid fa-pills"></i> Add New Inventory
    </button>
</div>

<!-- ADD INVENTORY MODAL -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Medicine Inventory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Medicine</label>
                            <select class="form-select" name="medication_id" required>
                                <option value="">-- Select Medicine --</option>
                                <?php
                                $allMeds = $medications->getAllMedications();
                                foreach ($allMeds as $med) {
                                    echo "<option value='{$med['medication_id']}'>{$med['generic_name']} ({$med['brand_name']})</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" class="form-control" name="quantity" min="0" required>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" class="form-control" name="reorder_level" min="1" value="10">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" name="expiry_date" required>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" name="add_inventory" class="btn btn-success">
                        <i class="fas fa-forward me-1"></i>Add Inventory
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- SUCCESS MODAL -->
<div class="modal fade" id="successModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Success</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>✅ Medicine inventory added successfully!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>
