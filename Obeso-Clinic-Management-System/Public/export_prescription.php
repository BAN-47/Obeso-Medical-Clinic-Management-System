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
$checkup_id = $_GET['checkup_id'] ?? 1;
$checkup = $checkupObj->get($checkup_id);

if (!$checkup) {
    $checkup = [
        'patient_id'                => 1,
        'chief_complaint'           => '',
        'history_present_illness'   => '',
        'diagnosis'                 => '',
        'blood_pressure'            => '',
        'heart_rate'                => '',
        'respiratory_rate'          => '',
        'temperature'               => '',
        'weight'                    => '',
        'checkup_date'              => '',
    ];
}

// Get patient data
$patient = $patientObj->get($checkup['patient_id']);
if (!$patient) {
    $patient = ['full_name' => 'Unknown Patient', 'age' => 'N/A', 'sex' => ''];
}

// Get medications for this checkup
$medications = $medObj->getLatestByPatient($checkup_id);

$dompdf = new Dompdf();

// Sanitize all values
$patient_name = htmlspecialchars($patient['full_name']              ?? 'Unknown Patient', ENT_QUOTES);
$patient_age  = htmlspecialchars($patient['age']                    ?? 'N/A',             ENT_QUOTES);
$patient_sex  = htmlspecialchars($patient['sex']                    ?? '',                ENT_QUOTES);
$today        = !empty($checkup['checkup_date'])
                    ? date('F j, Y', strtotime($checkup['checkup_date']))
                    : date('F j, Y');

$cc        = htmlspecialchars($checkup['chief_complaint']          ?? '', ENT_QUOTES);
$hpi       = htmlspecialchars($checkup['history_present_illness']  ?? '', ENT_QUOTES);
$diagnosis = htmlspecialchars($checkup['diagnosis']                ?? '', ENT_QUOTES);
$bp        = htmlspecialchars($checkup['blood_pressure']           ?? '', ENT_QUOTES);
$hr        = htmlspecialchars($checkup['heart_rate']               ?? '', ENT_QUOTES);
$rr        = htmlspecialchars($checkup['respiratory_rate']         ?? '', ENT_QUOTES);
$temp      = htmlspecialchars($checkup['temperature']              ?? '', ENT_QUOTES);
$wt        = htmlspecialchars($checkup['weight']                   ?? '', ENT_QUOTES);

// Build medication rows — minimum 6 rows always shown
$med_rows = '';
$count = 0;
foreach ($medications as $med) {
    $generic  = htmlspecialchars($med['generic_name'] ?? '', ENT_QUOTES);
    $brand    = htmlspecialchars($med['brand_name']   ?? '', ENT_QUOTES);
    $dose     = htmlspecialchars($med['dose']         ?? '', ENT_QUOTES);
    $amount   = htmlspecialchars($med['amount']       ?? '', ENT_QUOTES);
    $freq     = htmlspecialchars($med['frequency']    ?? '', ENT_QUOTES);
    $duration = htmlspecialchars($med['duration']     ?? '', ENT_QUOTES);

    $bg = ($count % 2 === 0) ? '#dbeeff' : '#c8dff5';
    $med_rows .= "<tr style='background:{$bg};'>
        <td>{$generic}</td>
        <td>{$brand}</td>
        <td>{$dose}</td>
        <td>{$amount}</td>
        <td>{$freq}</td>
        <td>{$duration}</td>
    </tr>";
    $count++;
}

// Fill remaining empty rows up to 6
while ($count < 6) {
    $bg = ($count % 2 === 0) ? '#dbeeff' : '#c8dff5';
    $med_rows .= "<tr style='background:{$bg};'>
        <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
        <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
    </tr>";
    $count++;
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Prescription - {$patient_name}</title>
<style>

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Arial, sans-serif;
    background: #f5f5f5;
    padding: 20px;
}

.paper {
    width: 720px;
    margin: auto;
    background: #fff;
    padding: 32px 36px;
    border: 1px solid #ccc;
}

/* ---- TOP FIELD ROWS ---- */
.field-row {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    font-size: 13px;
}

.field-label {
    font-weight: bold;
    min-width: 70px;
    font-size: 13px;
}

.field-value {
    border-bottom: 1.5px solid #111;
    font-size: 13px;
    padding: 1px 6px;
    min-height: 20px;
}

.f-short  { width: 90px; }
.f-medium { width: 180px; }
.f-long   { width: 440px; }

/* ---- CC / HPI + VITALS ROW ---- */
.cc-hpi-row {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
    font-size: 13px;
}

.tag-yellow {
    background: #fff176;
    color: #4a3d00;
    padding: 3px 10px;
    font-weight: bold;
    font-size: 12px;
    margin-right: 6px;
    white-space: nowrap;
}

.tag-red {
    background: #e57373;
    color: #4a0000;
    padding: 3px 10px;
    font-weight: bold;
    font-size: 12px;
    margin-right: 6px;
    white-space: nowrap;
}

.box-value {
    border: 1.5px solid #111;
    min-height: 22px;
    width: 240px;
    padding: 2px 6px;
    font-size: 12px;
    vertical-align: middle;
}

.vitals-block {
    margin-left: 14px;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.vital-row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.vital-tag {
    background: #fff176;
    color: #4a3d00;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: bold;
    white-space: nowrap;
}

.vital-val {
    border: 1.5px solid #111;
    min-height: 20px;
    width: 64px;
    font-size: 12px;
    padding: 1px 4px;
}

/* ---- BLANK WRITING SPACE ---- */
.writing-space {
    height: 240px;
    border-top: 1px solid #ccc;
    border-bottom: 1px solid #ccc;
    margin: 16px 0 0 0;
}

/* ---- MEDICATIONS TABLE ---- */
.med-tag {
    display: inline-block;
    background: #e57373;
    color: #4a0000;
    padding: 4px 12px;
    font-weight: bold;
    font-size: 12px;
    margin-top: 10px;
    margin-bottom: 0;
}

.med-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed;
    margin-top: 0;
}

.med-table th {
    border: 1.5px solid #111;
    padding: 5px 4px;
    text-align: center;
    font-weight: bold;
    background: #fff;
    color: #111;
    font-size: 12px;
}

.med-table td {
    border: 1px solid #aaa;
    padding: 4px 6px;
    height: 26px;
    font-size: 12px;
}

/* ---- SIGNATURE ---- */
.signature {
    margin-top: 40px;
    text-align: right;
}

.signature-line {
    display: inline-block;
    width: 220px;
    border-top: 1.5px solid #111;
    padding-top: 5px;
    text-align: center;
    font-size: 12px;
}

@media print {
    body { background: #fff; padding: 0; }
    .paper { border: none; width: 100%; }
}

</style>
</head>
<body>
<div class="paper">

    <!-- DATE -->
    <div class="field-row">
        <span class="field-label">DATE</span>
        <span class="field-value f-medium">{$today}</span>
    </div>

    <!-- NAME -->
    <div class="field-row">
        <span class="field-label">NAME</span>
        <span class="field-value f-long">{$patient_name}</span>
    </div>

    <!-- AGE + DIAGNOSIS -->
    <div class="field-row">
        <span class="field-label">AGE</span>
        <span class="field-value f-short">{$patient_age}</span>
        <span class="field-label" style="margin-left:20px; min-width:70px;">Diagnosis</span>
        <span class="field-value f-medium">{$diagnosis}</span>
    </div>

    <!-- CC + BP / RR -->
    <div class="cc-hpi-row" style="margin-top:10px;">
        <span class="tag-yellow">CC</span>
        <span class="box-value">{$cc}</span>
        <div class="vitals-block">
            <div class="vital-row">
                <span class="vital-tag">BP</span>
                <span class="vital-val">{$bp}</span>
                <span class="vital-tag">RR</span>
                <span class="vital-val">{$rr}</span>
            </div>
        </div>
    </div>

    <!-- HPI + WT / HR / TEMP -->
    <div class="cc-hpi-row" style="margin-top:5px;">
        <span class="tag-red">HPI</span>
        <span class="box-value">{$hpi}</span>
        <div class="vitals-block">
            <div class="vital-row">
                <span class="vital-tag">WT</span>
                <span class="vital-val">{$wt}</span>
                <span class="vital-tag">HR</span>
                <span class="vital-val">{$hr}</span>
                <span class="vital-tag">TEMP</span>
                <span class="vital-val">{$temp}</span>
            </div>
        </div>
    </div>

    <!-- BLANK WRITING SPACE -->
    <div class="writing-space"></div>

    <!-- MEDICATIONS -->
    <div>
        <span class="med-tag">MEDICATIONS</span>
    </div>
    <table class="med-table">
        <colgroup>
            <col style="width:22%">
            <col style="width:18%">
            <col style="width:13%">
            <col style="width:13%">
            <col style="width:20%">
            <col style="width:14%">
        </colgroup>
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
            {$med_rows}
        </tbody>
    </table>

    <!-- SIGNATURE -->
    <div class="signature">
        <div class="signature-line">Doctor's Signature</div>
    </div>

</div>
</body>
</html>
HTML;

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($patient_name . "_Prescription.pdf", ["Attachment" => true]);
?>