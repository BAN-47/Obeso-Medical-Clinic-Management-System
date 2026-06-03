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
    $patient = ['full_name' => 'Unknown Patient', 'age' => 'N/A', 'sex' => '', 'address' => ''];
}

$dompdf = new Dompdf();

$patient_name    = htmlspecialchars($patient['full_name'] ?? 'Unknown Patient', ENT_QUOTES);
$patient_age     = htmlspecialchars($patient['age']       ?? 'N/A',             ENT_QUOTES);
$patient_sex     = htmlspecialchars($patient['sex']       ?? '',                 ENT_QUOTES);
$patient_address = htmlspecialchars($patient['address']   ?? '',                 ENT_QUOTES);
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
        color: #111;
    }

    .page {
        position: relative;
        width: 100%;
        min-height: 1122px;
    }

    /* ══ HEADER: logo left, text left-aligned beside it ══ */
    .header {
        width: 100%;
        border-collapse: collapse;
        padding: 18px 30px 14px 20px;
        display: table;
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
        color: #1a1a1a;
        line-height: 1.1;
        letter-spacing: 0.3px;
    }
    .clinic-sub {
        font-size: 16px;
        font-weight: bold;
        color: #2c6880;
        margin-top: 4px;
    }
    .clinic-addr {
        font-size: 14px;
        color: #333;
        margin-top: 3px;
    }

    /* ══ DOUBLE RULE ══ */
    .rule-wrap { margin: 0 0; }
    .rule-top  { border-top: 5px solid #111; }
    .rule-bot  { border-top: 2px solid #111; margin-top: 5px; }

    /* ══ PATIENT FIELDS ══ */
    .fields {
        padding: 20px 30px 0 20px;
    }
    .fields table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10px;
    }
    .fields td {
        vertical-align: bottom;
        padding: 0;
        white-space: nowrap;
    }
    .flbl {
        font-weight: bold;
        font-size: 14px;
        padding-right: 6px;
    }
    .fval {
        border-bottom: 1.5px solid #111;
        font-size: 13px;
        padding: 0 4px 1px 3px;
        display: inline-block;
    }

    /* ══ Rx — cursive script style ══ */
    .rx-area {
        padding: 28px 0 0 18px;
    }
    .rx-symbol {
        font-family: 'Times New Roman', Times, serif;
        font-size: 72px;
        font-weight: bold;
        font-style: italic;
        color: #111;
        line-height: 1;
    }

    /* ══ SIGNATURE pinned bottom-right, no line ══ */
    .sig {
        position: absolute;
        bottom: 40px;
        right: 30px;
        text-align: right;
    }
    .sig-name  { font-weight: bold; font-size: 13px; }
    .sig-title { font-size: 12px; color: #333; margin-top: 2px; }
    .sig-lic   { font-size: 11px; color: #333; margin-top: 1px; }
</style>
</head>
<body>
<div class="page">

    <!-- ══ HEADER ══ -->
    <div class="header">
        <div class="header-logo">{$logo_html}</div>
        <div class="header-text">
            <div class="clinic-name">OBESO MEDICAL CLINIC</div>
            <div class="clinic-sub" style="color: black;">Family &amp; Wellness Center</div>
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
        <!-- Row 1: Name -->
        <table>
            <tr>
                <td style="width:70px;"><span class="flbl">Name</span></td>  <!-- fixed width -->
                <td><span class="fval" style="min-width:400px;">{$patient_name}</span></td>
                <td style="width:22px;"></td>
                <td><span class="flbl">Age</span></td>
                <td><span class="fval" style="min-width:60px;">{$patient_age}</span></td>
                <td style="padding-left:18px;"><span class="flbl">Sex</span></td>
                <td><span class="fval" style="min-width:65px;">{$patient_sex}</span></td>
            </tr>
        </table>

        <!-- Row 2: Address -->
        <table>
            <tr>
                <td style="width:70px;"><span class="flbl">Address</span></td>  <!-- same fixed width -->
                <td><span class="fval" style="min-width:400px;">{$patient_address}</span></td>
                <td style="width:22px;"></td>
                <td><span class="flbl" style="margin-left: -47px;">Date</span></td>
                <td colspan="3"><span class="fval" style="min-width:150px;">{$today}</span></td>
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