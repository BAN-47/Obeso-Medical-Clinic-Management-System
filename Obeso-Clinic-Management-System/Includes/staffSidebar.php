<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
    <div class="sb-sidenav-menu">
        <div class="nav">
            <a class="nav-link <?= $currentPage == 'staff_dashboard.php' ? 'active' : '' ?>"
               href="staff_dashboard.php">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Dashboard
            </a>

            <a class="nav-link <?= $currentPage == 'staff_profile.php' ? 'active' : '' ?>"
               href="staff_profile.php">
                <div class="sb-nav-link-icon"><i class="fa-solid fa-user-doctor"></i></div>
                Profile
            </a>

            <a class="nav-link <?= $currentPage == 'staff_patient_data_management.php' ? 'active' : '' ?>"
               href="staff_patient_data_management.php">
                <div class="sb-nav-link-icon"><i class="fa-solid fa-people-group"></i></div>
                Patient Data
            </a>

            <a class="nav-link <?= $currentPage == 'staff_billing.php' ? 'active' : '' ?>"
               href="staff_billing.php">
                <div class="sb-nav-link-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                Billing
            </a>

            <a class="nav-link <?= $currentPage == 'staff_medical_data_insights.php' ? 'active' : '' ?>"
               href="staff_medical_data_insights.php">
                <div class="sb-nav-link-icon"><i class="fa-solid fa-hands-helping"></i></div>
                Insights
            </a>
        </div>
    </div>

    <div class="sb-sidenav-footer">
        <div class="small">Logged in as:</div>
        Staff
    </div>
</nav>
