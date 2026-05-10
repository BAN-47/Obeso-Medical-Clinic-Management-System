<?php

require_once "../config/db.php";
require_once "../class/medicineInventory.php";
require_once "../class/medications.php";

$database = new Database();
$db = $database->connect();
$inventory = new MedicineInventory($db);
$medications = new Medications($db);

$rows = $inventory->viewAll();
$lastInventoryID = null;

if (isset($_POST['add_inventory'])) {
    $generic_name  = trim($_POST['generic_name']);
    $brand_name    = trim($_POST['brand_name']);
    $quantity      = intval($_POST['quantity']);
    $expiry_date   = $_POST['expiry_date'];
    $reorder_level = intval($_POST['reorder_level']);

    $medStmt = $db->prepare("INSERT INTO medications (generic_name, brand_name) VALUES (?, ?)");
    $medStmt->execute([$generic_name, $brand_name]);
    $medication_id = $db->lastInsertId();

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
        echo "<script>alert('Error adding inventory.'); window.location='../public/admin_inventory.php';</script>";
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
                            <label class="form-label">Generic Name</label>
                            <input type="text" class="form-control" name="generic_name" placeholder="e.g. Amoxicillin" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Brand Name</label>
                            <input type="text" class="form-control" name="brand_name" placeholder="e.g. Amoxil" required>
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
                <p>Medicine inventory added successfully!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>