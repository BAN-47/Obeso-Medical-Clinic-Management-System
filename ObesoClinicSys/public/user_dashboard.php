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
require_once "../class/user.php";

$database = new Database();
$db = $database->connect();
$user = new User($db);

// /* 🔐 SUPER ADMIN CHECK */
// if ($_SESSION['user_is_superadmin'] != 1) {
//     echo "<script>alert('Access denied'); window.location='';</script>";
//     exit;
// }

/* RESET PASSWORD */
if (isset($_POST['reset_password'])) {
    $user->resetPassword($_POST['user_id'], $_POST['new_password']);
}

/* ENABLE / DISABLE */
if (isset($_GET['toggle'])) {
    $user->setStatus($_GET['toggle'], $_GET['status']);
}

$users = $user->all();
?>

<main>
    <div class="container-fluid px-4">
        <h1 class="mt-4">User Management</h1>

        <?php require_once "user_add.php"; ?>

        <div class="card mt-3">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-users me-1"></i> System Users
            </div>

            <div class="card-body">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-primary">
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= $u['user_id'] ?></td>
                                <td><?= htmlspecialchars($u['user_name']) ?></td>
                                <td><?= $user->role($u) ?></td>
                                <td>
                                    <?= $u['staff_first_name'] ?? $u['doc_fullname'] ?? '—' ?>
                                </td>
                                <td>
                                    <?= $u['user_is_active'] ?
                                        '<span class="badge bg-success">Active</span>' :
                                        '<span class="badge bg-danger">Disabled</span>' ?>
                                </td>

                                <td>
                                    <!-- RESET PASSWORD -->
                                    <button class="btn btn-sm btn-warning"
                                        data-bs-toggle="modal"
                                        data-bs-target="#reset<?= $u['user_id'] ?>">
                                        <i class="fas fa-key"></i> Reset
                                    </button>

                                    <!-- ENABLE / DISABLE -->
                                    <a href="?toggle=<?= $u['user_id'] ?>&status=<?= $u['user_is_active'] ? 0 : 1 ?>"
                                        class="btn btn-sm btn-secondary"
                                        onclick="return confirm('Are you sure?')">
                                        <i class="fas fa-power-off"></i> <?= $u['user_is_active'] ? 'Disable' : 'Enable' ?>
                                    </a>
                                </td>
                            </tr>

                            <!-- RESET MODAL -->
                            <div class="modal fade" id="reset<?= $u['user_id'] ?>">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header bg-warning">
                                                <h5>Reset Password</h5>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                                <input type="password" name="new_password" class="form-control" required>
                                            </div>
                                            <div class="modal-footer">
                                                <button name="reset_password" class="btn btn-success">Save</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php require_once "../includes/footer.php"; ?>