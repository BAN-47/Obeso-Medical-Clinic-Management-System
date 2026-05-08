<?php

require_once '../vendor/autoload.php';
require_once '../Config/database.php';
require_once '../Class/patient_data.php';
require_once '../Class/checkups.php';
require_once '../Class/prescribed_medication.php';

use Dompdf\Dompdf;

// Database connection
$db = new Database();
$conn = $db->connect();
$patientObj = new Patient($conn);
$checkupObj = new Checkup($conn);
$medObj = new PrescribedMedication($conn);

// Get checkup ID from URL parameter
$checkup_id = $_GET['checkup_id'] ?? 1; // Default to 1 if not provided
$checkup = $checkupObj->get($checkup_id);

// If checkup not found, use default
if (!$checkup) {
    $checkup = [
        'patient_id' => 1,
        'chief_complaint' => '',
        'history_present_illness' => '',
        'diagnosis' => '',
        'blood_pressure' => '',
        'heart_rate' => '',
        'respiratory_rate' => '',
        'temperature' => '',
        'weight' => ''
    ];
}

// Get patient data
$patient = $patientObj->get($checkup['patient_id']);
if (!$patient) {
    $patient = ['full_name' => 'Unknown Patient', 'age' => 'N/A', 'sex' => ''];
}

// Get medications
$medications = $medObj->getLatestByPatient($checkup_id);

$dompdf = new Dompdf();

$patient_name = htmlspecialchars($patient['full_name'] ?? 'Unknown Patient', ENT_QUOTES);
$patient_age = htmlspecialchars($patient['age'] ?? 'N/A', ENT_QUOTES);
$patient_sex = htmlspecialchars($patient['sex'] ?? '', ENT_QUOTES);
$today = date('F j, Y');

$cc = htmlspecialchars($checkup['chief_complaint'] ?? '', ENT_QUOTES);
$hpi = htmlspecialchars($checkup['history_present_illness'] ?? '', ENT_QUOTES);
$diagnosis = htmlspecialchars($checkup['diagnosis'] ?? '', ENT_QUOTES);
$bp = htmlspecialchars($checkup['blood_pressure'] ?? '', ENT_QUOTES);
$hr = htmlspecialchars($checkup['heart_rate'] ?? '', ENT_QUOTES);
$rr = htmlspecialchars($checkup['respiratory_rate'] ?? '', ENT_QUOTES);
$temp = htmlspecialchars($checkup['temperature'] ?? '', ENT_QUOTES);
$wt = htmlspecialchars($checkup['weight'] ?? '', ENT_QUOTES);

// Build medication rows
$med_rows = '';
foreach ($medications as $med) {
    $generic = htmlspecialchars($med['generic_name'] ?? '', ENT_QUOTES);
    $brand = htmlspecialchars($med['brand_name'] ?? '', ENT_QUOTES);
    $dose = htmlspecialchars($med['dose'] ?? '', ENT_QUOTES);
    $amount = htmlspecialchars($med['amount'] ?? '', ENT_QUOTES);
    $freq = htmlspecialchars($med['frequency'] ?? '', ENT_QUOTES);
    $duration = htmlspecialchars($med['duration'] ?? '', ENT_QUOTES);
    $med_rows .= "<tr><td>$generic</td><td>$brand</td><td>$dose</td><td>$amount</td><td>$freq</td><td>$duration</td></tr>";
}
// Fill remaining rows with empty cells
while (count($medications) < 6) {
    $med_rows .= "<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>";
    $medications[] = null; // dummy
}

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body {
    font-family: Arial, sans-serif;
    font-size: 12px;
    margin: 0;
    padding: 0;
}
.page {
    padding: 24px;
}
.prescription {
    border: 1px solid #000;
    padding: 18px;
}
.top-section {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 16px;
}
.left-block,
.right-block {
    width: 100%;
}
.field-row {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}
.field-label {
    width: 60px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.field-value {
    flex: 1;
    border-bottom: 1px solid #000;
    padding-bottom: 4px;
    min-height: 18px;
}
.small-label {
    width: 48px;
    margin-left: 16px;
}
.diag-box {
    border: 1px solid #d32f2f;
    min-height: 56px;
    padding: 8px;
    margin-left: 4px;
    width: 100%;
    background-color: #ff9e9e;
}
.section-title {
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
    background-color: #d32f2f;
    color: #fff;
    padding: 4px 8px;
    display: inline-block;
    margin-bottom: 10px;
}
.vitals-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
    margin-top: 14px;
}
.vital-item {
    border: 1px solid #d7a600;
    padding: 6px;
    min-height: 54px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background-color: #fff3a6;
}
.vital-item .label {
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
}
.medicine-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
}
.medicine-table th,
.medicine-table td {
    border: 1px solid #000;
    padding: 8px 6px;
    vertical-align: top;
    font-size: 11px;
}
.medicine-table th {
    background: #e8e8e8;
    text-transform: uppercase;
}
</style>
</head>
<body>
<div class="page">
    <div class="prescription">
        <div class="top-section">
            <div class="left-block">
                <div class="field-row">
                    <span class="field-label">Date</span>
                    <span class="field-value">$today</span>
                </div>
                <div class="field-row">
                    <span class="field-label">Name</span>
                    <span class="field-value">$patient_name</span>
                </div>
                <div class="field-row">
                    <span class="field-label">Age</span>
                    <span class="field-value">$patient_age</span>
                    <span class="field-label small-label">Sex</span>
                    <span class="field-value" style="width: 70px;">$patient_sex</span>
                </div>
                <div class="field-row">
                    <span class="field-label">CC</span>
                    <span class="field-value">$cc</span>
                </div>
                <div class="field-row">
                    <span class="field-label">HPI</span>
                    <span class="field-value">$hpi</span>
                </div>
                <div class="field-row" style="align-items: flex-start;">
                    <span class="field-label">Diagnosis</span>
                    <span class="diag-box">$diagnosis</span>
                </div>
            </div>
            <div class="right-block">
                <div class="section-title">Medications</div>
                <table class="medicine-table">
                    <thead>
                        <tr>
                            <th style="width: 16%;">Generic Name</th>
                            <th style="width: 16%;">Brand Name</th>
                            <th style="width: 14%;">Dose</th>
                            <th style="width: 14%;">Amount</th>
                            <th style="width: 16%;">Frequency</th>
                            <th style="width: 16%;">Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        $med_rows
                    </tbody>
                </table>
            </div>
        </div>
        <div class="vitals-grid">
            <div class="vital-item"><span class="label">BP</span><span class="value">$bp</span></div>
            <div class="vital-item"><span class="label">HR</span><span class="value">$hr</span></div>
            <div class="vital-item"><span class="label">RR</span><span class="value">$rr</span></div>
            <div class="vital-item"><span class="label">Temp</span><span class="value">$temp</span></div>
            <div class="vital-item"><span class="label">WT</span><span class="value">$wt</span></div>
        </div>
    </div>
</div>
</body>
</html>
HTML;


$dompdf->loadHtml($html);

$dompdf->setPaper('A4', 'portrait');

$dompdf->render();

$dompdf->stream("prescription.pdf", [
    "Attachment" => true
]);
?>