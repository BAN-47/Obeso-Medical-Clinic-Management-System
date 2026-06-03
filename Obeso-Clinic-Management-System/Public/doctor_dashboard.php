<?php
session_name('obeso_doctor');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");


/* ================= ACCESS CONTROL ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'doctor') {
    header("Location: access_denied.php");
    exit();
}

/* ================= DATABASE ================= */
require_once "../Config/database.php";
$db = (new Database())->connect();

/* ================= DOCTOR INFO ================= */
$stmt = $db->prepare("SELECT * FROM doctors WHERE doc_id = ?");
$stmt->execute([$_SESSION['doc_id']]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die("Doctor not found.");
}

/* ================= AJAX: CALL NEXT / CALL SPECIFIC ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    session_write_close(); // IMPORTANT: prevents session lock issues

    try {
        $action = $_POST['action'];

        if ($action === 'call_next') {
            $db->prepare("UPDATE queue SET status = 'waiting' WHERE status = 'in-progress' AND DATE(created_at) = CURDATE()")
                ->execute();

            $stmt = $db->prepare("
                SELECT q.queue_id, q.queue_number, q.patient_id, p.full_name, p.age, p.sex
                FROM queue q
                JOIN patients p ON p.patient_id = q.patient_id
                WHERE q.status = 'waiting' AND DATE(q.created_at) = CURDATE()
                ORDER BY 
                    CASE WHEN q.priority = 'urgent' THEN 0 ELSE 1 END,
                    q.created_at ASC
                LIMIT 1
            ");
            $stmt->execute();
            $next = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$next) {
                echo json_encode(['success' => false, 'error' => 'No patients waiting.']);
                exit();
            }

            $db->prepare("UPDATE queue SET status = 'in-progress', called_at = NOW() WHERE queue_id = ?")
                ->execute([$next['queue_id']]);

            echo json_encode(['success' => true, 'patient' => $next]);
            exit();
        }

        if ($action === 'call_specific') {
            $queueId = (int)$_POST['queue_id'];
            $db->prepare("UPDATE queue SET status = 'in-progress', called_at = NOW() WHERE queue_id = ?")
                ->execute([$queueId]);
            echo json_encode(['success' => true]);
            exit();
        }

        if ($action === 'mark_done') {
            $queueId = (int)$_POST['queue_id'];
            
            // Fetch patient and queue info for billing
            $qstmt = $db->prepare("
                SELECT q.queue_id, q.patient_id, p.full_name
                FROM queue q
                JOIN patients p ON p.patient_id = q.patient_id
                WHERE q.queue_id = ?
            ");
            $qstmt->execute([$queueId]);
            $queueInfo = $qstmt->fetch(PDO::FETCH_ASSOC);
            
            // Update queue status
            $db->prepare("UPDATE queue SET status = 'done', done_at = NOW() WHERE queue_id = ?")
               ->execute([$queueId]);
            echo json_encode(['success' => true]);
            exit();
        }

        if ($action === 'remove') {
            $queueId = (int)$_POST['queue_id'];
            $db->prepare("DELETE FROM queue WHERE queue_id = ?")
                ->execute([$queueId]);
            echo json_encode(['success' => true]);
            exit();
        }

        if ($action === 'toggle_priority') {
            $queueId  = (int)$_POST['queue_id'];
            $priority = $_POST['priority'] === 'urgent' ? 'normal' : 'urgent';
            $db->prepare("UPDATE queue SET priority = ? WHERE queue_id = ?")
                ->execute([$priority, $queueId]);
            echo json_encode(['success' => true, 'priority' => $priority]);
            exit();
        }
    } catch (Exception $e) {

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit();
    }
}

/* ================= AJAX: FETCH QUEUE STATE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_queue'])) {
    header('Content-Type: application/json');
    $today = date('Y-m-d');

    $stmt = $db->prepare(" 
        SELECT q.queue_id, q.queue_number, q.patient_id, q.status, q.priority,
               q.created_at, q.called_at, q.done_at,
               p.full_name, p.age, p.sex
        FROM queue q
        JOIN patients p ON p.patient_id = q.patient_id
        WHERE DATE(q.created_at) = ?
        ORDER BY
            CASE q.status
                WHEN 'in-progress' THEN 0
                WHEN 'waiting'     THEN 1
                WHEN 'done'        THEN 2
                ELSE 3
            END,
            CASE WHEN q.priority = 'urgent' THEN 0 ELSE 1 END,
            q.created_at ASC
    ");
    $stmt->execute([$today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $waiting = array_filter($rows, fn($r) => $r['status'] === 'waiting');
    $inprog  = array_filter($rows, fn($r) => $r['status'] === 'in-progress');
    $done    = array_filter($rows, fn($r) => $r['status'] === 'done');

    $waitTimes = array_map(function ($r) {
        if (!$r['called_at'] || !$r['created_at']) return null;
        return (strtotime($r['called_at']) - strtotime($r['created_at'])) / 60;
    }, array_values($done));
    $waitTimes = array_filter($waitTimes, fn($v) => $v !== null);
    $avgWait   = count($waitTimes) ? round(array_sum($waitTimes) / count($waitTimes)) : null;

    echo json_encode([
        'rows'     => array_values($rows),
        'counts'   => [
            'waiting' => count($waiting),
            'inprog'  => count($inprog),
            'done'    => count($done),
            'total'   => count($rows),
        ],
        'avg_wait' => $avgWait,
    ]);
    exit();
}

/* ================= INITIAL COUNTS (for SSR) ================= */
$today = date('Y-m-d');
$countStmt = $db->prepare("
    SELECT
        SUM(CASE WHEN status = 'waiting'     THEN 1 ELSE 0 END) AS waiting,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) AS inprog,
        SUM(CASE WHEN status = 'done'        THEN 1 ELSE 0 END) AS done,
        COUNT(*) AS total
    FROM queue WHERE DATE(created_at) = ?
");
$countStmt->execute([$today]);
$counts = $countStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="../Includes/favicon_obeso.png">
    <title>Queue Management — Obeso's Clinic</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
    <link href="../Includes/sidebarStyle.css" rel="stylesheet">

    <style>
        /* ── Match billing page palette ── */
        :root {
            --accent: #1565c0;
            /* blue header/buttons */
            --accent-dark: #0d47a1;
            --accent-lt: #e3edf9;
            --success: #2e7d32;
            --success-lt: #e8f5e9;
            --danger: #c62828;
            --danger-lt: #ffebee;
            --amber: #e65100;
            --amber-lt: #fff3e0;
            --text: #212121;
            --muted: #757575;
            --border: #e0e0e0;
            --surface: #ffffff;
            --page-bg: #f5f5f5;
            --radius: 4px;
            --radius-lg: 6px;
        }

        body {
            background: var(--page-bg);
        }

        /* ── Page header strip (matches blue billing header) ── */
        .page-header-bar {
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }

        .page-header-bar h4 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 600;
            color: #212121;
        }

        .page-header-bar .sub {
            font-size: .8rem;
            color: #757575;
            margin-top: 2px;
        }

        /* ── Stat cards ── */
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1rem 1.25rem;
        }

        .stat-card .stat-val {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
            line-height: 1;
        }

        .stat-card .stat-lbl {
            font-size: .75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            margin-top: 5px;
        }

        /* ── Section cards (matches billing form / records cards) ── */
        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .section-title {
            color: var(--accent);
            font-weight: 600;
            font-size: .95rem;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--surface);
        }

        .section-title i {
            margin-right: 6px;
        }

        /* ── Now-serving banner ── */
        .now-serving-banner {
            background: var(--accent-lt);
            border: 1px solid #90caf9;
            border-radius: var(--radius);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .now-serving-banner.empty {
            background: #fafafa;
            border-color: var(--border);
            color: var(--muted);
        }

        .ns-pulse {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--success);
            flex-shrink: 0;
            animation: pulse 1.4s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .25
            }
        }

        .ns-ticket {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
            font-variant-numeric: tabular-nums;
            min-width: 80px;
            line-height: 1;
        }

        .ns-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text);
        }

        .ns-meta {
            font-size: .82rem;
            color: var(--muted);
            margin-top: 3px;
        }

        /* ── Table ── */
        .table thead th {
            background: #fafafa;
            border-bottom: 1px solid var(--border);
            font-size: .78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--muted);
            padding: 10px 14px;
        }

        .table tbody td {
            padding: 10px 14px;
            font-size: .875rem;
            border-color: #f0f0f0;
        }

        .table tbody tr:hover {
            background: #fafafa;
        }

        /* ── Status & priority badges ── */
        .badge {
            font-size: .72rem;
            font-weight: 600;
            padding: 4px 9px;
            border-radius: 50px;
        }

        .badge-waiting {
            background: var(--accent-lt);
            color: var(--accent);
        }

        .badge-inprog {
            background: #fff8e1;
            color: #e65100;
        }

        .badge-done {
            background: var(--success-lt);
            color: var(--success);
        }

        .badge-urgent {
            background: var(--danger-lt);
            color: var(--danger);
        }

        .badge-normal {
            background: #f5f5f5;
            color: #616161;
        }

        /* ── Buttons ── */
        .btn-accent {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            padding: 9px 20px;
            font-size: .875rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-accent:hover {
            background: var(--accent-dark);
            color: #fff;
        }

        .btn-tv {
            background: #fff;
            color: var(--accent);
            border: 1px solid #90caf9;
            border-radius: var(--radius);
            padding: 8px 16px;
            font-size: .875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }

        .btn-tv:hover {
            background: var(--accent-lt);
            color: var(--accent);
        }

        .btn-tbl {
            border: none;
            border-radius: var(--radius);
            padding: 5px 10px;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: filter .12s;
        }

        .btn-tbl:hover {
            filter: brightness(.92);
        }

        .btn-call-row {
            background: var(--accent-lt);
            color: var(--accent);
        }

        .btn-done-row {
            background: var(--success-lt);
            color: var(--success);
        }

        .btn-urgent-row {
            background: var(--amber-lt);
            color: var(--amber);
        }

        .btn-remove-row {
            background: var(--danger-lt);
            color: var(--danger);
        }

        /* ── Done row dim ── */
        tr.row-done td {
            opacity: .45;
        }

        /* ── Modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: var(--radius-lg);
            box-shadow: 0 8px 32px rgba(0, 0, 0, .18);
            padding: 28px 32px 24px;
            max-width: 380px;
            width: 90%;
            text-align: center;
        }

        .modal-box h5 {
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
            font-size: 1rem;
        }

        .modal-box p {
            color: var(--muted);
            font-size: .875rem;
            margin-bottom: 20px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .modal-actions .btn {
            min-width: 100px;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: .875rem;
        }

        /* ── Empty state ── */
        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
            color: #bdbdbd;
        }

        .empty-state i {
            font-size: 2rem;
            display: block;
            margin-bottom: .5rem;
        }

        .empty-state span {
            font-size: .875rem;
        }
    </style>
</head>

<body class="sb-nav-fixed">

    <?php include "../Includes/header.html"; ?>
    <?php include "../Includes/navbar_doctor.html"; ?>

    <!-- Remove confirmation modal -->
    <div class="modal-overlay" id="removeModal">
        <div class="modal-box">
            <h5><i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>Remove Patient?</h5>
            <p>Are you sure you want to remove this patient from today's queue? This cannot be undone.</p>
            <div class="modal-actions">
                <button class="btn btn-outline-secondary btn-sm" onclick="closeRemoveModal()">
                    <i class="fa-solid fa-xmark me-1"></i> Cancel
                </button>
                <button class="btn btn-danger btn-sm" onclick="confirmRemove()">
                    <i class="fa-solid fa-trash me-1"></i> Remove
                </button>
            </div>
        </div>
    </div>


    <div id="layoutSidenav">
        <div id="layoutSidenav_nav"><?php include "../Includes/doctorSidebar.php"; ?></div>

        <div id="layoutSidenav_content">
            <main class="container-fluid px-0 pb-4">

                <div class="page-header-bar">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="tv_display.php" target="_blank" class="btn-tv">
                            Open TV Display
                        </a>
                        <button class="btn-accent" onclick="callNext()">
                            Call Next Patient
                        </button>
                    </div>
                </div>

                <div class="px-4">

                    <!-- Stat Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <div class="stat-val" id="stat-waiting"><?= (int)($counts['waiting'] ?? 0) ?></div>
                                <div class="stat-lbl"><i class="fa-solid fa-clock me-1"></i>Waiting</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <div class="stat-val" id="stat-inprog"><?= (int)($counts['inprog'] ?? 0) ?></div>
                                <div class="stat-lbl"><i class="fa-solid fa-stethoscope me-1"></i>In Progress</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <div class="stat-val" id="stat-done"><?= (int)($counts['done'] ?? 0) ?></div>
                                <div class="stat-lbl"><i class="fa-solid fa-circle-check me-1"></i>Done Today</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <div class="stat-val" id="stat-avg">—</div>
                                <div class="stat-lbl"><i class="fa-regular fa-hourglass me-1"></i>Avg Wait (min)</div>
                            </div>
                        </div>
                    </div>

                    <!-- Now Serving -->
                    <div class="section-card mb-4">
                        <div class="section-title">
                            <span><i class="fa-solid fa-volume-high"></i> Now Serving</span>
                        </div>
                        <div class="p-3">
                            <div id="nowServingBox">
                                <div class="now-serving-banner empty">
                                    <i class="fa-regular fa-face-meh fa-lg me-2"></i>
                                    No patient is currently being called. Press
                                    <strong>Call Next Patient</strong> above to begin.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Queue Table -->
                    <div class="section-card">
                        <div class="section-title">
                            <span><i class="fa-solid fa-list"></i> Today's Queue</span>
                            <span class="badge bg-light text-secondary border fw-semibold" id="total-badge">
                                <?= (int)($counts['total'] ?? 0) ?> total
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:110px;">Queue #</th>
                                        <th>Patient</th>
                                        <th style="width:110px;">Age / Sex</th>
                                        <th style="width:110px;">Priority</th>
                                        <th style="width:120px;">Status</th>
                                        <th style="width:120px;">Wait Time</th>
                                        <th style="width:190px;" class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="queueTableBody">
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="fa-solid fa-spinner fa-spin"></i>
                                                <span>Loading queue…</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div><!-- /px-4 -->
            </main>
            <?php include "../Includes/footer.html"; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const SELF = window.location.pathname;

        /* Remove modal */
        let _removeQueueId = null;

        function openRemoveModal(id) {
            _removeQueueId = id;
            document.getElementById('removeModal').classList.add('show');
        }

        function closeRemoveModal() {
            _removeQueueId = null;
            document.getElementById('removeModal').classList.remove('show');
        }

        function confirmRemove() {
            if (!_removeQueueId) return;
            postAction('remove', {
                queue_id: _removeQueueId
            }).then(() => {
                closeRemoveModal();
                fetchQueue();
            });
        }

        /* POST helper */
        function postAction(action, extra = {}) {
            const fd = new FormData();
            fd.append('action', action);
            for (const [k, v] of Object.entries(extra)) fd.append(k, v);
            return fetch(SELF, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            }).then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error("Invalid JSON from server:", text);
                    throw new Error("Server response is not JSON");
                }
            })
        }

function callNext() {
    postAction('call_next').then(d => { if (!d.success) alert(d.error || 'No patients waiting.'); fetchQueue(); });
}
function callSpecific(id) { postAction('call_specific', { queue_id: id }).then(() => fetchQueue()); }
function markDone(id)     { postAction('mark_done',     { queue_id: id }).then(() => fetchQueue()); }
function togglePriority(id, cur) { postAction('toggle_priority', { queue_id: id, priority: cur }).then(() => fetchQueue()); }

        function minutesAgo(dateStr) {
            if (!dateStr) return '—';
            const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 60000);
            return diff < 1 ? 'just now' : diff + ' min ago';
        }


        function renderNowServing(inprog) {
            const box = document.getElementById('nowServingBox');
            if (!inprog) {
                box.innerHTML = `<div class="now-serving-banner empty">
            <i class="fa-regular fa-face-meh fa-lg me-2"></i>
            No patient is currently being called. Press<strong>Call Next Patient</strong>above to begin.
        </div>`;
                return;
            }
            box.innerHTML = `<div class="now-serving-banner">
        <div class="ns-pulse"></div>
        <div class="ns-ticket">${escHtml(inprog.queue_number)}</div>
        <div class="flex-grow-1">
<div class="ns-name" style="cursor:pointer;color:#1565c0;" onclick="openMedicalRecord(${inprog.patient_id})"> ${escHtml(inprog.full_name)}</div>
            <div class="ns-meta">
                ${escHtml(inprog.age ?? '—')} yrs &nbsp;·&nbsp; ${escHtml(inprog.sex ?? '—')}
                &nbsp;·&nbsp; Called ${minutesAgo(inprog.called_at)}
            </div>
        </div>
        <button class="btn-tbl btn-done-row" onclick="markDone(${inprog.queue_id})">
            <i class="fa-solid fa-check"></i> Mark Done
        </button>
    </div>`;
        }

        function renderTable(rows) {
            const tbody = document.getElementById('queueTableBody');
            if (!rows.length) {
                tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">
            <i class="fa-solid fa-inbox"></i>
            <span>No patients in today's queue yet.</span>
        </div></td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const isWaiting = r.status === 'waiting';
                const isInprog = r.status === 'in-progress';
                const isDone = r.status === 'done';
                const isUrgent = r.priority === 'urgent';

                const statusBadge = isWaiting ?
                    `<span class="badge badge-waiting">Waiting</span>` :
                    isInprog ?
                    `<span class="badge badge-inprog"><i class="fa-solid fa-stethoscope me-1"></i>In Progress</span>` :
                    `<span class="badge badge-done"><i class="fa-solid fa-check me-1"></i>Done</span>`;

                const priorityBadge = isUrgent ?
                    `<span class="badge badge-urgent"><i class="fa-solid fa-bolt me-1"></i>Urgent</span>` :
                    `<span class="badge badge-normal">Normal</span>`;

                const waitDisplay = isDone ?
                    (r.called_at && r.created_at ?
                        Math.round((new Date(r.called_at) - new Date(r.created_at)) / 60000) + ' min' :
                        '—') :
                    minutesAgo(r.created_at);

                let actions = '';
                if (isWaiting) {
                    actions = `
                <button class="btn-tbl btn-call-row me-1" onclick="callSpecific(${r.queue_id})" title="Call this patient">
                    <i class="fa-solid fa-bullhorn"></i>
                </button>
                <button class="btn-tbl btn-urgent-row me-1" onclick="togglePriority(${r.queue_id}, '${r.priority}')" title="${isUrgent ? 'Remove urgent' : 'Mark as urgent'}">
                    <i class="fa-solid fa-bolt"></i>
                </button>
                <button class="btn-tbl btn-remove-row" onclick="openRemoveModal(${r.queue_id})" title="Remove">
                    <i class="fa-solid fa-xmark"></i>
                </button>`;
                } else if (isInprog) {
                    actions = `<button class="btn-tbl btn-done-row" onclick="markDone(${r.queue_id})">
                <i class="fa-solid fa-check"></i> Done
            </button>`;
                } else {
                    actions = `<span class="text-muted small">—</span>`;
                }

                return `<tr class="${isDone ? 'row-done' : ''}">
            <td><strong style="font-family:monospace;font-size:.9rem;">${escHtml(r.queue_number)}</strong></td>
            <td>
<div class="fw-semibold"style="font-size:.875rem; cursor:pointer; color:#1565c0;"onclick="openMedicalRecord(${r.patient_id})">${escHtml(r.full_name)}</div>                <div class="text-muted" style="font-size:.75rem;">Added ${escHtml(r.created_at ? r.created_at.slice(11,16) : '—')}</div>
            </td>
            <td class="text-muted">${escHtml(r.age ?? '—')} / ${escHtml(r.sex ?? '—')}</td>
            <td>${priorityBadge}</td>
            <td>${statusBadge}</td>
            <td class="text-muted" style="font-size:.82rem;">${waitDisplay}</td>
            <td class="text-end pe-3">${actions}</td>
        </tr>`;
            }).join('');
        }

        function openMedicalRecord(patientId) {
            window.location.href =
                'doctor_medical_records_management.php?patient_id=' +
                encodeURIComponent(patientId);
        }

        function fetchQueue() {
            fetch(SELF + '?fetch_queue=1&t=' + Date.now(), {
                credentials: 'same-origin'
            }).then(r => r.json()).then(data => {
                const {
                    rows,
                    counts,
                    avg_wait
                } = data;
                document.getElementById('stat-waiting').textContent = counts.waiting;
                document.getElementById('stat-inprog').textContent = counts.inprog;
                document.getElementById('stat-done').textContent = counts.done;
                document.getElementById('stat-avg').textContent = avg_wait !== null ? avg_wait : '—';
                document.getElementById('total-badge').textContent = counts.total + ' total';
                renderNowServing(rows.find(r => r.status === 'in-progress') || null);
                renderTable(rows);
            }).catch(err => console.error('Queue fetch error:', err));
        }

        function escHtml(str) {
            if (str === null || str === undefined) return '—';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.addEventListener('DOMContentLoaded', function() {
            fetchQueue();
            setInterval(fetchQueue, 15000);
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>