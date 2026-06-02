<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once "../Config/database.php";
$db = (new Database())->connect();

if (isset($_GET['fetch'])) {
    header('Content-Type: application/json');
    $today = date('Y-m-d');

    $stmt = $db->prepare("
        SELECT q.queue_number, p.full_name
        FROM queue q
        JOIN patients p ON p.patient_id = q.patient_id
        WHERE q.status = 'in-progress' AND DATE(q.created_at) = ?
        LIMIT 1
    ");
    $stmt->execute([$today]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt2 = $db->prepare("
        SELECT q.queue_number, p.full_name, q.priority
        FROM queue q
        JOIN patients p ON p.patient_id = q.patient_id
        WHERE q.status = 'waiting' AND DATE(q.created_at) = ?
        ORDER BY CASE WHEN q.priority = 'urgent' THEN 0 ELSE 1 END, q.created_at ASC
        LIMIT 12
    ");
    $stmt2->execute([$today]);
    $waiting = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'current' => $current ?: null,
        'waiting' => $waiting,
        'time'    => date('h:i A'),
        'date'    => date('l, F j, Y'),
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="../Includes/favicon_obeso.png">
<title>Queue Display — Obeso's Clinic</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="tv_display.css">
</head>
<body>

<!-- Top bar -->
<div class="top-bar">
    <div class="clinic-name">Obeso Medical Clinic</div>
    <div class="top-right">
        <div class="time" id="tvTime">--:-- --</div>
        <div class="date" id="tvDate">Loading…</div>
    </div>
</div>

<!-- Two panels -->
<div class="body">

    <!-- LEFT -->
    <div class="left-panel">
        <div class="panel-title">Up Next</div>
        <div class="queue-grid" id="queueGrid">
            <div class="empty-msg">No patients waiting</div>
        </div>
    </div>

    <!-- RIGHT -->
    <div class="right-panel" id="rightPanel">
        <div class="panel-title" style="width:100%;">Now Calling</div>
        <div class="now-calling-body">
            <div class="now-number empty" id="nowNumber">— — —</div>
            <div class="now-divider"      id="nowDivider"></div>
            <div class="now-name empty"   id="nowName">No patient called yet</div>
            <div class="pulse-row" id="pulseRow" style="display:none;">
                <div class="pulse-dot"></div>
                <div class="pulse-text">Please proceed to the clinic</div>
            </div>
        </div>
    </div>

</div>

<script>
let lastNumber = null;

function esc(str) {
    if (!str) return '—';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fetchData() {
    fetch('tv_display.php?fetch=1')
        .then(r => r.json())
        .then(d => {
            document.getElementById('tvTime').textContent = d.time;
            document.getElementById('tvDate').textContent = d.date;
            renderCalling(d.current);
            renderQueue(d.waiting);
        })
        .catch(e => console.error(e));
}

function renderCalling(current) {
    const numEl    = document.getElementById('nowNumber');
    const nameEl   = document.getElementById('nowName');
    const divider  = document.getElementById('nowDivider');
    const pulse    = document.getElementById('pulseRow');
    const panel    = document.getElementById('rightPanel');

    if (!current) {
        numEl.textContent  = '— — —';
        numEl.className    = 'now-number empty';
        nameEl.textContent = 'No patient called yet';
        nameEl.className   = 'now-name empty';
        divider.className  = 'now-divider';
        pulse.style.display = 'none';
        lastNumber = null;
        return;
    }

    if (current.queue_number !== lastNumber) {
        panel.classList.add('flash');
        setTimeout(() => panel.classList.remove('flash'), 600);
        lastNumber = current.queue_number;
    }

    numEl.textContent  = current.queue_number;
    numEl.className    = 'now-number';
    nameEl.textContent = current.full_name;
    nameEl.className   = 'now-name';
    divider.className  = 'now-divider active';
    pulse.style.display = 'flex';
}

function renderQueue(waiting) {
    const grid = document.getElementById('queueGrid');
    if (!waiting.length) {
        grid.innerHTML = '<div class="empty-msg">No patients waiting</div>';
        return;
    }
    grid.innerHTML = waiting.map(p => `
        <div class="q-card ${p.priority === 'urgent' ? 'urgent' : ''}">
            <div class="q-num">${esc(p.queue_number)}</div>
            ${p.priority === 'urgent' ? '<div class="q-badge">Urgent</div>' : ''}
        </div>`).join('');
}

fetchData();
setInterval(fetchData, 5000);
</script>
</body>
</html>