<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
    <div class="sb-sidenav-menu">
        <div class="nav">
            <a class="nav-link <?= $currentPage == 'doctor_dashboard.php' ? 'active' : '' ?>"
               href="doctor_dashboard.php">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Management
            </a>

            <a class="nav-link <?= $currentPage == 'doctor_profile.php' ? 'active' : '' ?>"
               href="doctor_profile.php">
                <div class="sb-nav-link-icon"><i class="fa-solid fa-user-doctor"></i></div>
                Profile
            </a>

            <a class="nav-link <?= $currentPage == 'doctor_medical_records_management.php' ? 'active' : '' ?>"
               href="doctor_medical_records_management.php">
                <div class="sb-nav-link-icon"><i class="fa-solid fa-hospital-user"></i></div>
                Medical Records
            </a>
            
            <a class="nav-link <?= $currentPage == 'doctor_medical_data_insights.php' ? 'active' : '' ?>"
               href="doctor_medical_data_insights.php">
                <div class="sb-nav-link-icon"><i class="fa-solid fa-hands-helping"></i></div>
                Insights
            </a>
        </div>
    </div>

    <div class="sb-sidenav-footer">
        <div class="small">Logged in as:</div>
        Doctor
    </div>
</nav>
