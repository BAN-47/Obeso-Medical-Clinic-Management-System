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
<title>Prescription Form</title>

<style>

body{
    font-family: Arial, sans-serif;
    background:#f4f4f4;
    padding:20px;
}

.prescription{
    width:900px;
    margin:auto;
    background:white;
    padding:25px;
    border:2px solid #000;
}

.top-section{
    display:flex;
    justify-content:space-between;
    margin-bottom:20px;
}

.left-info{
    width:45%;
}

.left-info label{
    font-size:13px;
    font-weight:bold;
}

.input-line{
    border:none;
    border-bottom:1px solid #000;
    width:100%;
    margin-bottom:10px;
    outline:none;
}

.vitals{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
    margin-top:15px;
}

.vital-box{
    display:flex;
    align-items:center;
}

.vital-label{
    width:50px;
    padding:5px;
    font-size:12px;
    font-weight:bold;
    text-align:center;
    color:#000;
}

.bp{ background:#ffe96b; }
.hr{ background:#ffe96b; }
.wt{ background:#ffe96b; }
.rr{ background:#ffe96b; }
.temp{ background:#ffe96b; }
.ht{ background:#ff7b7b; }

.vital-input{
    border:none;
    border-bottom:1px solid #000;
    flex:1;
    margin-left:5px;
    outline:none;
}

.diagnosis{
    margin-top:15px;
}

.diagnosis textarea{
    width:100%;
    height:60px;
    resize:none;
    border:1px solid #ccc;
    padding:10px;
}

.prescription-header{
    margin-top:25px;
    background:#ff6b6b;
    color:white;
    display:inline-block;
    padding:5px 12px;
    font-weight:bold;
}

.rx-table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
}

.rx-table th{
    background:#dff1ff;
    padding:10px;
    border:1px solid #ccc;
    font-size:13px;
}

.rx-table td{
    height:40px;
    border:1px solid #ccc;
}

.signature{
    margin-top:40px;
    text-align:right;
}

.signature-line{
    width:250px;
    border-top:1px solid #000;
    margin-left:auto;
    padding-top:5px;
    font-size:12px;
}

@media print{
    body{
        background:white;
        padding:0;
    }

    .prescription{
        border:none;
        width:100%;
    }
}

</style>
</head>

<body>

<div class="prescription">

    <div class="top-section">

        <div class="left-info">

            <label>DATE</label>
            <input type="date" class="input-line">

            <label>NAME</label>
            <input type="text" class="input-line">

            <label>AGE</label>
            <input type="text" class="input-line">

            <label>CC</label>
            <input type="text" class="input-line">

        </div>

        <div style="width:50%;">

            <div class="vitals">

                <div class="vital-box">
                    <div class="vital-label bp">BP</div>
                    <input type="text" class="vital-input">
                </div>

                <div class="vital-box">
                    <div class="vital-label rr">RR</div>
                    <input type="text" class="vital-input">
                </div>

                <div class="vital-box">
                    <div class="vital-label temp">TEMP</div>
                    <input type="text" class="vital-input">
                </div>

                <div class="vital-box">
                    <div class="vital-label hr">HR</div>
                    <input type="text" class="vital-input">
                </div>

                <div class="vital-box">
                    <div class="vital-label wt">WT</div>
                    <input type="text" class="vital-input">
                </div>

                <div class="vital-box">
                    <div class="vital-label ht">HT</div>
                    <input type="text" class="vital-input">
                </div>

            </div>

        </div>

    </div>

    <div class="diagnosis">
        <label><strong>Diagnosis</strong></label>
        <textarea></textarea>
    </div>

    <div class="prescription-header">
        Prescription
    </div>

    <table class="rx-table">
        <thead>
            <tr>
                <th>Generic Name</th>
                <th>Brand Name</th>
                <th>Dose</th>
                <th>Amount</th>
                <th>Frequency</th>
                <th>Duration</th>
            </tr>
        </thead>

        <tbody>

            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>

            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>

            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>

            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>

        </tbody>

    </table>

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