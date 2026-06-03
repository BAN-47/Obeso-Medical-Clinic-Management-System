<?php
session_start();

require_once __DIR__ . "/Obeso-Clinic-Management-System/Config/database.php";

$database = new Database();
$conn = $database->connect();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
    
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Obeso's Clinic Management System</title>
<link rel="icon" type="image/png" href="/Obeso-Clinic-Management-System/Includes/favicon_obeso.png" />
<script src="https://cdn.tailwindcss.com"></script>

<style>
  body { 
    background: #075179ff;
    background-repeat: no-repeat;
    background-position: left center;
    background-size: 300px;
  }
</style>
</head>

<?php if (isset($_SESSION['login_error'])): ?>
    <script>
        alert("<?= addslashes($_SESSION['login_error']); ?>");
    </script>
    <?php unset($_SESSION['login_error']); ?>
<?php endif; ?>

<body class="min-h-screen flex items-center justify-center">

  <!-- Login Card -->
  <div class="bg-white shadow-2xl rounded-2xl p-12 max-w-lg w-full mx-6">

    <!-- Logo / Title -->
    <div class="flex justify-center mb-8">
      <div class="flex items-center justify-center w-35 h-20 overflow-hidden bg-white shadow-md" style="width: 220px; height: 120px;">
        <img src="/Obeso-Clinic-Management-System/Includes/Obeso_Med_Clinic_logo.png" alt="Obeso Clinic Logo" class="w-full h-full object-cover rounded-3xl">
      </div>
    </div>

    <!-- Heading -->
    <h2 class="text-center text-3xl font-semibold text-gray-900 mb-8">
      Login
    </h2>

    <!-- Login Form -->
    <form method="POST" action="/Obeso-Clinic-Management-System/Public/login_register.php" autocomplete="off" class="space-y-6">

      <!-- Username -->
      <input
        type="text"
        name="username"
        placeholder="Email or Username"
        required
        class="w-full p-4 text-lg border border-gray-300 rounded-lg bg-gray-100 text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500"
      />

      <!-- Password -->
      <div class="relative">
        <input
          type="password"
          name="password"
          id="login-password"
          placeholder="Password"
          required
          class="w-full p-4 text-lg border border-gray-300 rounded-lg bg-gray-100 pr-12 text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500"
        />
        <button
          type="button"
          onclick="togglePassword()"
          class="absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 text-xl"
          aria-label="Toggle Password Visibility"
        >
          👁️
        </button>
      </div>

      <!-- Login Button -->
      <button
        type="submit"
        name="login"
        class="w-full bg-sky-600 hover:bg-sky-700 transition text-white p-4 text-lg rounded-lg font-semibold"
      >
        Login
      </button>

    </form>

    <!-- Support (BIGGER) -->
    <p class="mt-8 text-center text-base text-gray-700">
      Having issues?
      <a href="#" class="text-sky-600 hover:underline font-semibold">
        Contact IT Support
      </a>
    </p>

    <!-- Footer -->
    <footer class="mt-10 text-center text-xs text-gray-500">
      © 2026 Obeso's Clinic | All rights reserved | Privacy Policy<br />
      Poog, Toledo City
    </footer>

  </div>

<script>
function togglePassword() {
  const input = document.getElementById('login-password');
  input.type = input.type === 'password' ? 'text' : 'password';
}
</script>

</body>
</html>