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
$patient_name = htmlspecialchars($patient['full_name']             ?? 'Unknown Patient', ENT_QUOTES);
$patient_age  = htmlspecialchars($patient['age']                   ?? 'N/A',             ENT_QUOTES);
$today        = !empty($checkup['checkup_date'])
                    ? date('F j, Y', strtotime($checkup['checkup_date']))
                    : date('F j, Y');

$cc        = htmlspecialchars($checkup['chief_complaint']         ?? '', ENT_QUOTES);
$hpi       = htmlspecialchars($checkup['history_present_illness'] ?? '', ENT_QUOTES);
$diagnosis = htmlspecialchars($checkup['diagnosis']               ?? '', ENT_QUOTES);
$bp        = htmlspecialchars($checkup['blood_pressure']          ?? '', ENT_QUOTES);
$hr        = htmlspecialchars($checkup['heart_rate']              ?? '', ENT_QUOTES);
$rr        = htmlspecialchars($checkup['respiratory_rate']        ?? '', ENT_QUOTES);
$temp      = htmlspecialchars($checkup['temperature']             ?? '', ENT_QUOTES);
$wt        = htmlspecialchars($checkup['weight']                  ?? '', ENT_QUOTES);

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
    $med_rows .= "<tr>
        <td style='background:{$bg};'>{$generic}</td>
        <td style='background:{$bg};'>{$brand}</td>
        <td style='background:{$bg};'>{$dose}</td>
        <td style='background:{$bg};'>{$amount}</td>
        <td style='background:{$bg};'>{$freq}</td>
        <td style='background:{$bg};'>{$duration}</td>
    </tr>";
    $count++;
}
while ($count < 6) {
    $bg = ($count % 2 === 0) ? '#dbeeff' : '#c8dff5';
    $med_rows .= "<tr>
        <td style='background:{$bg};'>&nbsp;</td>
        <td style='background:{$bg};'>&nbsp;</td>
        <td style='background:{$bg};'>&nbsp;</td>
        <td style='background:{$bg};'>&nbsp;</td>
        <td style='background:{$bg};'>&nbsp;</td>
        <td style='background:{$bg};'>&nbsp;</td>
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

* { margin: 0; padding: 0; }

body {
    font-family: Arial, sans-serif;
    font-size: 13px;
    background: #fff;
    padding: 30px;
}

/* ── FIELD ROWS ── */
.field-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 8px;
}
.field-table td {
    padding: 2px 4px;
    vertical-align: bottom;
}
.lbl {
    font-weight: bold;
    font-size: 13px;
}
.underline {
    border-bottom: 1px solid #000;
    font-size: 13px;
    padding: 1px 4px;
    display: inline-block;
}

/* ── CC/HPI + VITALS ── */
.row-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 5px;
}
.row-table td {
    padding: 0 3px 0 0;
    vertical-align: middle;
}

.tag-yellow {
    background: #fff176;
    color: #4a3d00;
    font-weight: bold;
    font-size: 12px;
    padding: 3px 8px;
}
.tag-red {
    background: #e57373;
    color: #4a0000;
    font-weight: bold;
    font-size: 12px;
    padding: 3px 8px;
}
.box-val {
    border: 1px solid #111;
    font-size: 12px;
    padding: 2px 5px;
    height: 22px;
    display: block;
    width: 100%;
}
.vital-tag {
    background: #fff176;
    color: #4a3d00;
    font-weight: bold;
    font-size: 11px;
    padding: 2px 6px;
}
.vital-val {
    border: 1px solid #111;
    font-size: 12px;
    padding: 1px 4px;
    height: 20px;
    width: 58px;
    display: inline-block;
}

/* ── VITALS INNER TABLE ── */
.vitals-inner {
    border-collapse: collapse;
    width: 100%;
}
.vitals-inner td {
    padding: 1px 3px;
    vertical-align: middle;
    white-space: nowrap;
}

/* ── BLANK WRITING SPACE ── */
.writing-space {
    width: 100%;
    height: 220px;
    border-top: 1px solid #ccc;
    border-bottom: 1px solid #ccc;
    margin-top: 14px;
}

/* ── MEDICATIONS ── */
.med-tag {
    background: #e57373;
    color: #4a0000;
    font-weight: bold;
    font-size: 12px;
    padding: 4px 12px;
    display: inline-block;
    margin-top: 10px;
}
.med-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed;
}
.med-table th {
    border: 1.5px solid #111;
    padding: 5px 4px;
    text-align: center;
    font-weight: bold;
    background: #fff;
    font-size: 12px;
}
.med-table td {
    border: 1px solid #aaa;
    padding: 4px 5px;
    height: 26px;
    font-size: 12px;
}

/* ── SIGNATURE ── */
.sig-wrap {
    margin-top: 40px;
    text-align: right;
}
.sig-line {
    display: inline-block;
    width: 220px;
    border-top: 1.5px solid #111;
    padding-top: 4px;
    text-align: center;
    font-size: 12px;
}

</style>
</head>
<body>

<!-- DATE -->
<table class="field-table">
    <tr>
        <td style="width:50px;"><span class="lbl">DATE</span></td>
        <td><span class="underline" style="width:180px;">{$today}</span></td>
    </tr>
</table>

<!-- NAME -->
<table class="field-table">
    <tr>
        <td style="width:50px;"><span class="lbl">NAME</span></td>
        <td><span class="underline" style="width:430px;">{$patient_name}</span></td>
    </tr>
</table>

<!-- AGE + DIAGNOSIS -->
<table class="field-table">
    <tr>
        <td style="width:50px;"><span class="lbl">AGE</span></td>
        <td style="width:90px;"><span class="underline" style="width:75px;">{$patient_age}</span></td>
        <td style="width:75px;"><span class="lbl">Diagnosis</span></td>
        <td><span class="underline" style="width:250px;">{$diagnosis}</span></td>
    </tr>
</table>

<!-- CC row -->
<table class="row-table" style="margin-top:10px;">
    <tr>
        <td style="width:38px;"><span class="tag-yellow">CC</span></td>
        <td style="width:270px;"><span class="box-val">{$cc}</span></td>
        <td style="width:8px;"></td>
        <td>
            <table class="vitals-inner">
                <tr>
                    <td><span class="vital-tag">BP</span></td>
                    <td><span class="vital-val">{$bp}</span></td>
                    <td style="width:6px;"></td>
                    <td><span class="vital-tag">RR</span></td>
                    <td><span class="vital-val">{$rr}</span></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<!-- HPI row -->
<table class="row-table" style="margin-top:5px;">
    <tr>
        <td style="width:38px;"><span class="tag-red">HPI</span></td>
        <td style="width:270px;"><span class="box-val">{$hpi}</span></td>
        <td style="width:8px;"></td>
        <td>
            <table class="vitals-inner">
                <tr>
                    <td><span class="vital-tag">WT</span></td>
                    <td><span class="vital-val">{$wt}</span></td>
                    <td style="width:4px;"></td>
                    <td><span class="vital-tag">HR</span></td>
                    <td><span class="vital-val">{$hr}</span></td>
                    <td style="width:4px;"></td>
                    <td><span class="vital-tag">TEMP</span></td>
                    <td><span class="vital-val">{$temp}</span></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<!-- BLANK WRITING SPACE -->
<div class="writing-space"></div>

<!-- MEDICATIONS -->
<div><span class="med-tag">MEDICATIONS</span></div>
<table class="med-table">
    <colgroup>
        <col style="width:22%">
        <col style="width:18%">
        <col style="width:12%">
        <col style="width:13%">
        <col style="width:21%">
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
<div class="sig-wrap">
    <div class="sig-line">Doctor's Signature wwewewe</div>
</div>

</body>
</html>
HTML;

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($patient_name . "_Prescription.pdf", ["Attachment" => true]);
?>