<?php
session_start();
// remember the default session name so we can remove the default cookie after role session is created
$defaultSessionName = session_name();
require_once "../Config/database.php";

$database = new Database();
$conn = $database->connect();

/* ==============================================================  
   🧩 REGISTER (Plain Password)
   ============================================================== */
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']); // plain password (later consider hashing!)

    // Check if username already exists
    $stmt = $conn->prepare("SELECT user_name FROM users WHERE user_name = ?");
    $stmt->execute([$username]);
    if ($stmt->rowCount() > 0) {
        $_SESSION['register_error'] = 'Username is already taken.';
        header("Location: login.php");
        exit();
    }

    // Determine if this email belongs to a doctor or staff
    $role = null;
    $roleId = null;

    // Check Doctor
    $check = $conn->prepare("SELECT doc_id FROM doctors WHERE doc_email = ?");
    $check->execute([$username]);
    if ($row = $check->fetch(PDO::FETCH_ASSOC)) {
        $role = 'doctor';
        $roleId = $row['doc_id'];
    }

    // Check Staff
    if (!$role) {
        $check = $conn->prepare("SELECT staff_id FROM staff WHERE staff_email = ?");
        $check->execute([$username]);
        if ($row = $check->fetch(PDO::FETCH_ASSOC)) {
            $role = 'staff';
            $roleId = $row['staff_id'];
        }
    }

    // If account exists in staff or doctor
    if ($role === 'doctor') {
        $stmt = $conn->prepare("INSERT INTO users (user_name, user_password, doc_id, user_is_superadmin) VALUES (?, ?, ?, 0)");
        $stmt->execute([$username, $password, $roleId]);
    } elseif ($role === 'staff') {
        $stmt = $conn->prepare("INSERT INTO users (user_name, user_password, staff_id, user_is_superadmin) VALUES (?, ?, ?, 0)");
        $stmt->execute([$username, $password, $roleId]);
    } else {
        $_SESSION['register_error'] = 'Account not found in staff or doctor records.';
        header("Location: login.php");
        exit();
    }

    $_SESSION['register_success'] = ucfirst($role) . ' account successfully registered!';
    header("Location: login.php");
    exit();
}

/* ==============================================================  
   🔐 LOGIN (Plain Password)
   ============================================================== */
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE user_name = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Plain password check
    if ($user && $password === $user['user_password']) {

    if ((int)$user['user_is_active'] !== 1) {
        $_SESSION['login_error'] = "Your account has been disabled. Please contact the administrator.";
        header("Location: /index.php");
        exit;
    }
        // Detect role
        if ($user['user_is_superadmin'] == 1) {
            $role = 'superadmin';
        } elseif (!is_null($user['doc_id'])) {
            $role = 'doctor';
        } elseif (!is_null($user['staff_id'])) {
            $role = 'staff';
        } else {
            $role = 'unknown';
        }

        // Close the current (public) session and create a role-specific session
        session_write_close();
        $roleSessionName = ($role === 'doctor') ? 'obeso_doctor' : (($role === 'staff') ? 'obeso_staff' : session_name());
        session_name($roleSessionName);
        session_start();

        // Set session variables in the role-specific session
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_name'] = $user['user_name'];
        $_SESSION['role'] = $role;
        if ($role === 'doctor') $_SESSION['doc_id'] = $user['doc_id'];
        if ($role === 'staff') $_SESSION['staff_id'] = $user['staff_id'];

        // Update last login time
        $update = $conn->prepare("UPDATE users SET user_last_login = NOW() WHERE user_id = ?");
        $update->execute([$user['user_id']]);

        // Remove the default session cookie from the browser to avoid a shared default session
        if (!empty($defaultSessionName) && $defaultSessionName !== $roleSessionName) {
            setcookie($defaultSessionName, '', time() - 42000, '/');
        }

        // Redirect by role
        switch ($role) {
            case 'superadmin':
                header("Location: /ObesoClinicSys/public/dashboard.php");
                break;
            case 'doctor':
                header("Location: ./doctor_dashboard.php");
                break;
            case 'staff':
                header("Location: ./staff_dashboard.php");
                break;
            default:
                header("Location: ./access_denied.php");
        }
        exit();
    } else {
        $_SESSION['login_error'] = "Incorrect username or password.";
        header("Location: /index.php");
        exit();
    }
}
?>

