<?php
require_once "../config/db.php";
require_once "../class/staff.php";
require_once "../class/doctor.php";
require_once "../class/user.php";

$database = new Database();
$db = $database->connect();
$staff = new Staff($db);
$doctor = new Doctor($db);
$user = new User($db);

$staffs = $staff->all();
$doctors = $doctor->getAllDoctors();

if (isset($_POST['add_user'])) {
    $staff_id = $_POST['staff_id'] ?: null;
    $doc_id   = $_POST['doc_id']   ?: null;

    $check = $user->checkRoleSelection($staff_id, $doc_id);

    if ($check === "BOTH_SELECTED") {
        echo "<script>alert('❌ User can only be Staff OR Doctor, not both.');</script>";
    } elseif ($check === "NONE_SELECTED") {
        echo "<script>alert('❌ User must be linked to Staff or Doctor.');</script>";
    } else {
        $result = $user->create(
            $_POST['username'],
            $_POST['password'],
            $staff_id,
            $doc_id
            // ← no extra false here
        );

        if ($result === "DUPLICATE_USERNAME") {
            echo "<script>alert('❌ Username already exists. Please choose another.');</script>";
        } elseif ($result === true) {
            echo "<script>alert('✅ User created successfully.');</script>";
        }
    }
}


?>

<button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addUser">
    <i class="fas fa-user-plus"></i> Create User
</button>

<div class="modal fade" id="addUser">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5>Create User Account</h5>
                </div>

                <div class="modal-body">
                    <input class="form-control mb-2" name="username" placeholder="Username" required>
                   <input class="form-control mb-1"
                           name="password"
                           id="password"
                           type="password"
                           placeholder="Password"
                           required
                           minlength="8"
                           pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#]).{8,}"
                           title="Password must be at least 8 characters and include uppercase, lowercase, number, and special character">

                    <div class="progress mb-1" style="height: 6px;">
                        <div id="passwordStrengthBar"
                             class="progress-bar"
                             role="progressbar"
                             style="width: 0%;"></div>
                    </div>

                    <small id="passwordStrengthText" class="text-muted d-block mb-2">
                        Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.
                    </small>



                    <select class="form-select mb-2" name="staff_id">
                        <option value="">Assign Staff (optional)</option>
                        <?php foreach ($staffs as $s): ?>
                            <option value="<?= $s['staff_id'] ?>">
                                <?= $s['staff_first_name'] . " " . $s['staff_last_name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select class="form-select" name="doc_id">
                        <option value="">Assign Doctor (optional)</option>
                        <?php foreach ($doctors as $d): ?>
                            <option value="<?= $d['doc_id'] ?>">
                                <?= $d['doc_fullname'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-footer">
                    <button name="add_user" class="btn btn-success">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
const passwordInput = document.getElementById('password');
const strengthBar = document.getElementById('passwordStrengthBar');
const strengthText = document.getElementById('passwordStrengthText');

passwordInput.addEventListener('input', () => {
    const value = passwordInput.value;
    let strength = 0;

    if (value.length >= 8) strength++;
    if (/[A-Z]/.test(value)) strength++;
    if (/[a-z]/.test(value)) strength++;
    if (/[0-9]/.test(value)) strength++;
    if (/[@$!%*?&#]/.test(value)) strength++;

    switch (strength) {
        case 0:
        case 1:
            strengthBar.style.width = '20%';
            strengthBar.className = 'progress-bar bg-danger';
            strengthText.textContent = 'Very weak password';
            break;
        case 2:
            strengthBar.style.width = '40%';
            strengthBar.className = 'progress-bar bg-warning';
            strengthText.textContent = 'Weak password';
            break;
        case 3:
            strengthBar.style.width = '60%';
            strengthBar.className = 'progress-bar bg-info';
            strengthText.textContent = 'Moderate password';
            break;
        case 4:
            strengthBar.style.width = '80%';
            strengthBar.className = 'progress-bar bg-primary';
            strengthText.textContent = 'Strong password';
            break;
        case 5:
            strengthBar.style.width = '100%';
            strengthBar.className = 'progress-bar bg-success';
            strengthText.textContent = 'Very strong password';
            break;
    }
});
</script>
