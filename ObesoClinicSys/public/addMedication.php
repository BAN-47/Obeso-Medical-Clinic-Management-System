<?php

require_once "../config/db.php";
require_once "../class/medications.php";

$database = new Database();
$db = $database->connect();

$medications = new Medications($db);

$rows = $medications->getAllMedications();

// -------------------------
// ADD MEDICATION
// -------------------------
if (isset($_POST['add_medication'])) {

    $generic_name = trim($_POST['generic_name']);
    $brand_name   = trim($_POST['brand_name']);

    if ($medications->addMedication($generic_name, $brand_name)) {

        $rows = $medications->getAllMedications();

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
            window.location='../public/admin_medications.php';
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
                        <i class="fas fa-plus-circle me-2"></i>Add Medication
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">Generic Name</label>
                        <input type="text"
                               class="form-control"
                               name="generic_name"
                               required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Brand Name</label>
                        <input type="text"
                               class="form-control"
                               name="brand_name">
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
                    <i class="fas fa-check-circle me-2"></i>Success
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <p>✅ Medication added successfully!</p>
            </div>

            <div class="modal-footer">
                <button type="button"
                        class="btn btn-primary"
                        data-bs-dismiss="modal">
                    OK
                </button>
            </div>

        </div>
    </div>
</div>