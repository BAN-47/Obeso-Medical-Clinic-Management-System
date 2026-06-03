<?php

require_once '../vendor/autoload.php';
require_once '../Config/database.php';
require_once '../Class/patient_data.php';
require_once '../Class/checkups.php';

use Dompdf\Dompdf;

$db = new Database();
$conn = $db->connect();
$patientObj = new Patient($conn);
$checkupObj = new Checkup($conn);

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

// Logo — embedded as base64 (requires GD enabled in php.ini)
$logo_html = '';
if (extension_loaded('gd')) {
    $logo_path = __DIR__ . '/../Includes/favicon_obeso.png';
    if (file_exists($logo_path)) {
        $logo_data = base64_encode(file_get_contents($logo_path));
        $logo_src  = 'data:image/png;base64,' . $logo_data;
        $logo_html = "<img src=\"{$logo_src}\" style=\"width:105px;height:105px;display:block;\">";
    }
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
        font-size: 14px;
        color: #000;
        padding-right: 4px;
    }
    /* Underline stretches under both the label+value area */
    .fline {
        border-bottom: 1.5px solid #000;
        display: inline-block;
        font-size: 13px;
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
    .sig-name  { font-weight: bold; font-size: 13px; color: #000; }
    .sig-title { font-size: 12px; color: #000; margin-top: 2px; }
    .sig-lic   { font-size: 11px; color: #000; margin-top: 1px; }
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
                <td style="width:42%;"><span class="fline" style="min-width:210px;">{$patient_name}</span></td>
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
                <td><span class="flbl">Address</span></td>
                <td style="width:42%;"><span class="fline" style="min-width:190px;">{$patient_address}</span></td>
                <td style="width:28px;"></td>
                <td><span class="flbl">Date</span></td>
                <td colspan="3"><span class="fline" style="min-width:145px;">{$today}</span></td>
            </tr>
        </table>

    </div>

    <!-- ══ Rx SYMBOL ══ -->
    <div class="rx-area">
        <span class="rx-symbol">Rx</span>
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