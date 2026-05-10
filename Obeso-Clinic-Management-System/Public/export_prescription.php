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

// Build medication rows — only actual medications, no forced empty padding rows
$med_rows = '';
$count = 0;

if (!empty($medications)) {
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
} else {
    for ($i = 0; $i < 6; $i++) {
        $bg = ($i % 2 === 0) ? '#dbeeff' : '#c8dff5';
        $med_rows .= "<tr>
            <td style='background:{$bg}; height:26px;'>&nbsp;</td>
            <td style='background:{$bg};'>&nbsp;</td>
            <td style='background:{$bg};'>&nbsp;</td>
            <td style='background:{$bg};'>&nbsp;</td>
            <td style='background:{$bg};'>&nbsp;</td>
            <td style='background:{$bg};'>&nbsp;</td>
        </tr>";
    }
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Prescription - {$patient_name}</title>
<link rel="stylesheet" href="export_prescription.css">
<style>
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
        <td style="width:270px;"><span class="cc-box">{$cc}</span></td>
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
        <td style="width:270px;"><span class="hpi-box">{$hpi}</span></td>
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
    <div class="sig-line">Doctor's Signature</div>
</div>

</body>
</html>
HTML;

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($patient_name . "_Prescription.pdf", ["Attachment" => true]);
?>