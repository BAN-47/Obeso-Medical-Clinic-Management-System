<?php
require_once '../vendor/autoload.php';
require_once '../Config/database.php';
require_once '../Class/patient_data.php';
require_once '../Class/checkups.php';
require_once '../Class/prescribed_medication.php';

use Dompdf\Dompdf;

$db = new Database();
$conn = $db->connect();
$patientObj = new Patient($conn);
$checkupObj = new Checkup($conn);
$medObj = new PrescribedMedication($conn);

$checkup_id = $_GET['checkup_id'] ?? 1;
$checkup = $checkupObj->get($checkup_id);

if (!$checkup) {
    $checkup = ['patient_id' => 1, 'checkup_date' => ''];
}

$patient = $patientObj->get($checkup['patient_id']);
if (!$patient) {
    $patient = ['full_name' => '', 'age' => '', 'sex' => '', 'address' => ''];
}

$dompdf = new Dompdf();

$patient_name    = htmlspecialchars($patient['full_name'] ?? '', ENT_QUOTES);
$patient_age     = htmlspecialchars($patient['age']       ?? '', ENT_QUOTES);
$patient_sex     = htmlspecialchars($patient['sex']       ?? '', ENT_QUOTES);
$patient_address = htmlspecialchars($patient['address']   ?? '', ENT_QUOTES);
$today           = !empty($checkup['checkup_date'])
                       ? date('F j, Y', strtotime($checkup['checkup_date']))
                       : date('F j, Y');

$soap_notes = htmlspecialchars($checkup['history_present_illness'] ?? '', ENT_QUOTES);
$diagnosis  = htmlspecialchars($checkup['diagnosis']  ?? '', ENT_QUOTES);

// Parse SOAP lines — split by newline and wrap each label in bold
$soap_html = '';
if (!empty($soap_notes)) {
    $lines = explode("\n", $soap_notes);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            $soap_html .= '<div style="height:6px;"></div>';
            continue;
        }
        // Bold the label prefix (HPI:, O:, A:, P:, S:, etc.)
        $line = preg_replace('/^([A-Z]+:)/', '<strong>$1</strong>', htmlspecialchars($line, ENT_QUOTES));
        $soap_html .= "<div style='margin-bottom:4px;'>{$line}</div>";
    }
}

$logo_html = '';

$logo_path = realpath(__DIR__ . '/../Includes/favicon_obeso.png');

if ($logo_path && file_exists($logo_path)) {
    $logo_data = base64_encode(file_get_contents($logo_path));
    $logo_src  = 'data:image/png;base64,' . $logo_data;
    $logo_html = "<img src=\"{$logo_src}\" style=\"width:105px;height:105px;display:block;\">";
}

if ($logo_html === '') {
    $logo_html = "<div style=\"width:105px;height:105px;background:#1d5f7a;border-radius:4px;\"></div>";
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Prescription - {$patient_name}</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }

    body {
        font-family: Arial, sans-serif;
        font-size: 13px;
        background: #fff;
        color: #000;
    }

    .page {
        position: relative;
        width: 100%;
        min-height: 1122px;
    }

    /* ══ HEADER ══ */
    .header {
        display: table;
        width: 100%;
        padding: 18px 30px 14px 20px;
    }
    .header-logo {
        display: table-cell;
        width: 120px;
        vertical-align: middle;
        padding-right: 16px;
    }
    .header-text {
        display: table-cell;
        vertical-align: middle;
        text-align: left;
    }
    .clinic-name {
        font-size: 28px;
        font-weight: bold;
        color: #000;
        line-height: 1.1;
        letter-spacing: 0.3px;
    }
    .clinic-sub {
        font-size: 16px;
        font-weight: bold;
        color: #000;
        margin-top: 4px;
    }
    .clinic-addr {
        font-size: 14px;
        color: #000;
        margin-top: 3px;
    }

    /* ══ DOUBLE RULE ══ */
    .rule-wrap { margin: 0; }
    .rule-top  { border-top: 5px solid #000; }
    .rule-bot  { border-top: 2px solid #000; margin-top: 5px; }

    /* ══ PATIENT FIELDS ══ */
    .fields {
        padding: 22px 30px 0 20px;
    }
    .field-row {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
    }
    .field-row td {
        vertical-align: bottom;
        padding: 0;
        white-space: nowrap;
    }
    .flbl {
        font-weight: bold;
        font-size: 20px;
        color: #000;
        padding-right: 4px;
    }
    /* Underline stretches under both the label+value area */
    .fline {
        border-bottom: 1.5px solid #000;
        display: inline-block;
        font-size: 20px;
        color: #000;
        padding: 0 4px 1px 3px;
        vertical-align: bottom;
    }

    /* ══ Rx ══ */
    .rx-area {
        padding: 28px 0 0 18px;
    }
    .rx-symbol {
        font-family: 'Times New Roman', Times, serif;
        font-size: 72px;
        font-weight: bold;
        font-style: italic;
        color: #000;
        line-height: 1;
    }

    /* ══ SIGNATURE pinned bottom-right ══ */
    .sig {
        position: absolute;
        bottom: 40px;
        right: 30px;
        text-align: right;
        color: #000;
    }
    .sig-name  { font-weight: bold; font-size: 16px; color: #000; }
    .sig-title { font-size: 15px; color: #000; margin-top: 2px; }
    .sig-lic   { font-size: 14px; color: #000; margin-top: 1px; }

    /* ══ SOAP NOTES ══ */
    .soap-area {
        padding: 10px 30px 0 20px;
        font-size: 18px;
        color: #000;
        line-height: 1.8;
        margin-top: 15px;
    }

    /* ══ DIAGNOSIS ══ */
    .diag-area {
        padding: 12px 30px 0 20px;
        font-size: 25px;
        color: #000;
    }
</style>
</head>
<body>
<div class="page">

    <!-- ══ HEADER ══ -->
    <div class="header">
        <div class="header-logo">{$logo_html}</div>
        <div class="header-text">
            <div class="clinic-name">OBESO MEDICAL CLINIC</div>
            <div class="clinic-sub">Family &amp; Wellness Center</div>
            <div class="clinic-addr">Poog, Toledo City</div>
        </div>
    </div>

    <!-- ══ DOUBLE RULE ══ -->
    <div class="rule-wrap">
        <div class="rule-top"></div>
        <div class="rule-bot"></div>
    </div>

    <!-- ══ PATIENT FIELDS ══ -->
    <div class="fields">
 
        <!-- Row 1: Name ___________ Age _____ Sex _____ -->
        <table class="field-row">
            <tr>
                <td><span class="flbl">Name</span></td>
                <td style="width:42%;"><span class="fline" style="min-width:400px;">{$patient_name}</span></td>
                <td style="width:28px;"></td>
                <td><span class="flbl">Age</span></td>
                <td><span class="fline" style="min-width:55px;">{$patient_age}</span></td>
                <td style="width:20px;"></td>
                <td><span class="flbl">Sex</span></td>
                <td><span class="fline" style="min-width:65px;">{$patient_sex}</span></td>
            </tr>
        </table>
 
        <!-- Row 2: Address ___________ Date _____ -->
        <table class="field-row">
            <tr>
                <td><span class="flbl" style="margin-right: -45px;">Address</span></td>
                <td style="width:40%;"><span class="fline" style="min-width:380px;">{$patient_address}</span></td>
                <td style="width:28px;"></td>
                <td><span class="flbl" style=" margin-left: -11px;">Date</span></td>
                <td colspan="3"><span class="fline" style="min-width:145px; margin-left: -45px;">{$today}</span></td>
            </tr>
        </table>

    </div>

    <!-- ══ Rx SYMBOL ══ -->
    <div class="rx-area">
        <span class="rx-symbol">Rx</span>
    </div>

    <!-- ══ DIAGNOSIS ══ -->
    <div class="diag-area" style="margin-top: 20px;">
        <span class="flbl">Diagnosis:</span>
        <span class="fline" style="min-width:300px; text-size: 25px;">{$diagnosis}</span>
    </div>

    <!-- ══ SOAP NOTES ══ -->
    <div class="soap-area">
        {$soap_html}
    </div>

    <!-- ══ SIGNATURE ══ -->
    <div class="sig">
        <div class="sig-name">Charmaine O. Alcontin, MD</div>
        <div class="sig-title">Family &amp; Community Medicine</div>
        <div class="sig-lic">LIC &nbsp;# &nbsp;0154571</div>
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