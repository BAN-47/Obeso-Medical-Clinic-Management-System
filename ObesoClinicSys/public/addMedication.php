<?php

require_once "../config/db.php";
require_once "../class/medications.php";


$database = new Database();
$db = $database->connect();

$medications = new Medications($db);

// -------------------------
// ADD MEDICATION
// -------------------------
if (isset($_POST['add_medication'])) {

    $generic_name  = trim($_POST['generic_name']);
    $brand_name    = trim($_POST['brand_name']);
    $category      = trim($_POST['category']);
    $preparation   = trim($_POST['preparation']);
    $volume_bottle = trim($_POST['volume_bottle']);
    $unit_price    = floatval($_POST['unit_price']);

    if ($medications->addMedication($generic_name, $brand_name, $category, $preparation, $volume_bottle, $unit_price)) {

        echo "
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var successModal = new bootstrap.Modal(document.getElementById('successModal'));
                successModal.show();
            });
        </script>
        ";

    } else {
        echo "
        <script>
            alert('❌ Error adding medication.');
            window.location='../public/inventory_dashboard.php';
        </script>
        ";
    }
}
?>

<!-- BUTTON TO OPEN ADD MEDICATION MODAL -->
<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#medicine">
    <i class="fa-solid fa-capsules"></i> Add Medication
</button>

<!-- ADD MEDICATION MODAL -->
<div class="modal fade" id="medicine" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <form method="POST">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i> Add Medication
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">Generic Name <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control"
                               name="generic_name"
                               placeholder="e.g. Amoxicillin"
                               required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Brand Name</label>
                        <input type="text"
                               class="form-control"
                               name="brand_name"
                               placeholder="e.g. Amoxil">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input type="text"
                               class="form-control"
                               name="category"
                               placeholder="e.g. Antibiotic">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Preparation</label>
                        <input type="text"
                               class="form-control"
                               name="preparation"
                               placeholder="e.g. Capsule, Syrup, Tablet">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Volume / Bottle</label>
                        <input type="text"
                               class="form-control"
                               name="volume_bottle"
                               placeholder="e.g. 60ml, 100mg/5ml">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Unit Price</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   class="form-control"
                                   name="unit_price"
                                   value="0.00">
                        </div>
                    </div>

                </div>

                <div class="modal-footer">

                    <button type="submit"
                            name="add_medication"
                            class="btn btn-success">
                        <i class="fas fa-save me-1"></i> Save Medication
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

<!-- SUCCESS MODAL -->
<div class="modal fade" id="successModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2"></i> Success
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body text-center py-3">
                <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                <p class="mb-0">Medication added successfully!</p>
            </div>

            <div class="modal-footer">
                <button type="button"
                        class="btn btn-success"
                        onclick="window.location='../public/admin_medications.php'">
                    OK
                </button>
            </div>

        </div>
    </div>
</div>