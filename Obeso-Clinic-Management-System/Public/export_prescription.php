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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clinic Prescription Form</title>

<style>

body{
    font-family:Arial, sans-serif;
    background:#f5f5f5;
    padding:20px;
}

.paper{
    width:950px;
    margin:auto;
    background:#fff;
    padding:30px;
    border:1px solid #ccc;
}

/* ---------- TOP LINES ---------- */

.line-row{
    margin-bottom:12px;
    font-size:15px;
}

.line-row label{
    display:inline-block;
    width:70px;
    font-weight:bold;
}

.line-input{
    border:none;
    border-bottom:1px solid #000;
    outline:none;
    font-size:14px;
    padding:2px 5px;
}

.short{
    width:120px;
}

.medium{
    width:220px;
}

.long{
    width:500px;
}

/* ---------- COLORED LABELS ---------- */

.tag{
    display:inline-block;
    padding:4px 12px;
    font-weight:bold;
    font-size:13px;
    margin-right:5px;
}

.yellow{
    background:#fff176;
}

.red{
    background:#ff8a80;
}

/* ---------- INLINE BOX ---------- */

.inline-box{
    display:inline-block;
    border:1px solid #000;
    height:24px;
    vertical-align:middle;
    margin-right:10px;
}

.box-sm{
    width:70px;
}

.box-md{
    width:120px;
}

.box-lg{
    width:350px;
}

/* ---------- VITALS ---------- */

.vitals{
    margin-top:15px;
    margin-left:110px;
}

.vital-group{
    margin-bottom:10px;
}

.vital-label{
    display:inline-block;
    background:#fff176;
    padding:4px 14px;
    font-weight:bold;
    font-size:13px;
}

.vital-input{
    display:inline-block;
    border:1px solid #000;
    height:24px;
    width:80px;
    vertical-align:middle;
    margin-right:20px;
}

/* ---------- RX TABLE ---------- */

.rx-title{
    margin-top:30px;
    display:inline-block;
    background:#ff8a80;
    padding:6px 15px;
    font-weight:bold;
}

.rx-area{
    margin-top:15px;
    border-top:1px solid #000;
    min-height:250px;
    padding-top:15px;
}

.rx-line{
    border-bottom:1px solid #ccc;
    height:35px;
}

/* ---------- SIGNATURE ---------- */

.signature{
    margin-top:50px;
    text-align:right;
}

.signature-line{
    display:inline-block;
    width:250px;
    border-top:1px solid #000;
    padding-top:5px;
    text-align:center;
    font-size:13px;
}

@media print{
    body{
        background:#fff;
        padding:0;
    }

    .paper{
        border:none;
        width:100%;
    }
}

</style>
</head>

<body>

<div class="paper">

    <!-- DATE -->
    <div class="line-row">
        <label>DATE</label>
        <input type="text" class="line-input medium">
    </div>

    <!-- NAME -->
    <div class="line-row">
        <label>NAME</label>
        <input type="text" class="line-input long">
    </div>

    <!-- AGE + DIAGNOSIS -->
    <div class="line-row">
        <label>AGE</label>
        <input type="text" class="line-input short">

        <span style="margin-left:40px; font-weight:bold;">Diagnosis</span>
        <input type="text" class="line-input medium">
    </div>

    <!-- CC -->
    <div class="line-row">
        <span class="tag yellow">CC</span>
        <span class="inline-box box-lg"></span>
    </div>

    <!-- HPI -->
    <div class="line-row">
        <span class="tag red">HPI</span>
        <span class="inline-box box-lg"></span>
    </div>

    <!-- VITALS -->
    <div class="vitals">

        <div class="vital-group">
            <span class="vital-label">BP</span>
            <span class="vital-input"></span>

            <span class="vital-label">RR</span>
            <span class="vital-input"></span>
        </div>

        <div class="vital-group">
            <span class="vital-label">WT</span>
            <span class="vital-input"></span>

            <span class="vital-label">HR</span>
            <span class="vital-input"></span>

            <span class="vital-label">TEMP</span>
            <span class="vital-input"></span>
        </div>

    </div>

    <!-- RX -->
    <div class="rx-title">
        Prescription
    </div>

    <div class="rx-area">

        <div class="rx-line"></div>
        <div class="rx-line"></div>
        <div class="rx-line"></div>
        <div class="rx-line"></div>
        <div class="rx-line"></div>
        <div class="rx-line"></div>

    </div>

    <!-- SIGNATURE -->
    <div class="signature">
        <div class="signature-line">
            Doctor's Signature
        </div>
    </div>

</div>

</body>
</html>
HTML;


$dompdf->loadHtml($html);

$dompdf->setPaper('A4', 'portrait');

$dompdf->render();

$dompdf->stream($patient_name . ".pdf", [
    "Attachment" => true
]);
?>