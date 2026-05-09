<?php
session_start();

/* ================= ACCESS CONTROL ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: access_denied.php");
    exit();
}

/* ================= DATABASE ================= */
require_once "../Config/database.php";
$db = (new Database())->connect();

/* ================= AJAX: CALL NEXT / CALL SPECIFIC ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        if ($action === 'call_next') {
            // Mark any currently "in-progress" back to waiting first
            $db->prepare("UPDATE queue SET status = 'waiting' WHERE status = 'in-progress' AND DATE(created_at) = CURDATE()")
               ->execute();

            // Get the next waiting patient (urgent first, then FIFO)
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

        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

/* ================= AJAX: FETCH QUEUE STATE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_queue'])) {
    header('Content-Type: application/json');
    $today = date('Y-m-d');

    $stmt = $db->prepare("
        SELECT q.queue_id, q.queue_number, q.status, q.priority,
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

    $waiting  = array_filter($rows, fn($r) => $r['status'] === 'waiting');
    $inprog   = array_filter($rows, fn($r) => $r['status'] === 'in-progress');
    $done     = array_filter($rows, fn($r) => $r['status'] === 'done');

    $waitTimes = array_map(function($r) {
        if (!$r['called_at'] || !$r['created_at']) return null;
        return (strtotime($r['called_at']) - strtotime($r['created_at'])) / 60;
    }, array_values($done));
    $waitTimes = array_filter($waitTimes, fn($v) => $v !== null);
    $avgWait   = count($waitTimes) ? round(array_sum($waitTimes) / count($waitTimes)) : null;

    echo json_encode([
        'rows'     => array_values($rows),
        'counts'   => [
            'waiting'  => count($waiting),
            'inprog'   => count($inprog),
            'done'     => count($done),
            'total'    => count($rows),
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
<title>Queue Management — Obeso's Clinic</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
<link href="../Includes/sidebarStyle.css" rel="stylesheet">

<style>
/* ── Brand colours ─────────────────────────── */
:root {
    --brand:      #062e6b;
    --brand-lt:   #e8f0fe;
    --brand-mid:  #1a5fd4;
    --danger:     #dc3545;
    --danger-lt:  #fff0f0;
    --success:    #198754;
    --success-lt: #e6f4ed;
    --amber:      #b86800;
    --amber-lt:   #fff8e1;
    --radius:     14px;
}

/* ── Layout ──────────────────────────────── */
.section-card   { border-radius: var(--radius); }
.section-header {
    background: var(--brand);
    color: #fff;
    padding: 12px 18px;
    border-radius: var(--radius) var(--radius) 0 0;
    font-weight: 600;
}
.sb-sidenav .nav-link.active {
    background-color: var(--brand) !important;
    color: #fff !important;
    font-weight: 600;
}

/* ── Stat cards ──────────────────────────── */
.stat-card {
    border-radius: var(--radius);
    border: 2px solid #e2e8f0;
    padding: 1.1rem 1.4rem;
    background: #fff;
}
.stat-card .stat-val {
    font-size: 2.4rem;
    font-weight: 900;
    color: var(--brand);
    line-height: 1;
    letter-spacing: -1px;
}
.stat-card .stat-lbl {
    font-size: .8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #6c757d;
    margin-top: 4px;
}

/* ── Now-serving banner ──────────────────── */
.now-serving-banner {
    background: var(--brand-lt);
    border: 2px solid #b8cef5;
    border-radius: var(--radius);
    padding: 18px 22px;
    display: flex;
    align-items: center;
    gap: 18px;
    animation: fadeIn .3s ease;
}
.now-serving-banner.empty {
    background: #f8f9fa;
    border-color: #dee2e6;
    color: #adb5bd;
}
.ns-pulse {
    width: 14px; height: 14px;
    border-radius: 50%;
    background: var(--success);
    flex-shrink: 0;
    animation: pulse 1.4s ease-in-out infinite;
}
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.2} }
.ns-ticket {
    font-size: 2.2rem;
    font-weight: 900;
    color: var(--brand);
    font-variant-numeric: tabular-nums;
    min-width: 90px;
    line-height: 1;
}
.ns-name  { font-size: 1.1rem; font-weight: 700; color: var(--brand); }
.ns-meta  { font-size: .85rem; color: var(--brand-mid); margin-top: 2px; }

/* ── Queue table ─────────────────────────── */
#queueTableBody tr { vertical-align: middle; transition: background .15s; }
#queueTableBody tr:hover { background: #f8f9fa; }

.badge-waiting  { background: var(--brand-lt); color: var(--brand);   border: 1px solid #b8cef5; }
.badge-inprog   { background: #fff3cd;         color: #856404;        border: 1px solid #ffc107; }
.badge-done     { background: var(--success-lt); color: var(--success); border: 1px solid #a8d9c0; }
.badge-urgent   { background: var(--danger-lt); color: var(--danger);  border: 1px solid #f0b8b8; }
.badge-normal   { background: #f0f4ff;          color: #4a5568;        border: 1px solid #d0d9f0; }

/* ── Action buttons ──────────────────────── */
.btn-call    { background: var(--brand); border-color: var(--brand); color: #fff; }
.btn-call:hover { background: #04235a; border-color: #04235a; color: #fff; }
.btn-done-q  { background: var(--success-lt); border-color: #a8d9c0; color: var(--success); }
.btn-done-q:hover { background: #c8ead9; }
.btn-remove  { background: var(--danger-lt); border-color: #f0b8b8; color: var(--danger); }
.btn-remove:hover { background: #f5cccc; }
.btn-urgent  { background: var(--amber-lt); border-color: #ffd27a; color: var(--amber); }
.btn-urgent:hover { background: #fff0c0; }

/* ── Call-next button ────────────────────── */
.btn-call-next {
    background: var(--brand);
    border: none;
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    padding: 13px 32px;
    border-radius: var(--radius);
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 16px rgba(6,46,107,.22);
    transition: background .18s, box-shadow .18s;
    cursor: pointer;
}
.btn-call-next:hover {
    background: #04235a;
    box-shadow: 0 6px 20px rgba(6,46,107,.30);
}
.btn-call-next:active { transform: scale(.97); }

/* ── Done-row fade ───────────────────────── */
tr.row-done td { opacity: .45; }

/* ── Animations ──────────────────────────── */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.row-new { animation: fadeIn .35s ease; }

/* ── Modal ───────────────────────────────── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(6,46,107,.45);
    z-index: 10000; align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal-box {
    background: #fff; border-radius: 18px;
    box-shadow: 0 16px 48px rgba(6,46,107,.22);
    padding: 36px 40px 30px; max-width: 420px;
    width: 90%; text-align: center;
    animation: fadeIn .22s ease;
}
.modal-box h5 { font-weight: 700; color: var(--brand); margin-bottom: 8px; }
.modal-box p  { color: #5a6a82; font-size: .96rem; margin-bottom: 24px; }
.modal-actions { display: flex; gap: 12px; justify-content: center; }
.modal-actions .btn { min-width: 110px; border-radius: 10px; font-weight: 600; }

/* ── Empty state ─────────────────────────── */
.empty-state { text-align: center; padding: 3rem 1rem; color: #adb5bd; }
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; }
</style>
</head>

<body class="sb-nav-fixed">

<?php include "../Includes/header.html"; ?>
<?php include "../Includes/navbar_staff.html"; ?>

<!-- Remove confirmation modal -->
<div class="modal-overlay" id="removeModal">
    <div class="modal-box">
        <h5><i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>Remove Patient?</h5>
        <p>Are you sure you want to remove this patient from today's queue? This cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn btn-outline-secondary" onclick="closeRemoveModal()">
                <i class="fa-solid fa-xmark me-1"></i> Cancel
            </button>
            <button class="btn btn-danger" onclick="confirmRemove()">
                <i class="fa-solid fa-trash me-1"></i> Remove
            </button>
        </div>
    </div>
</div>

<div id="layoutSidenav">
<div id="layoutSidenav_nav"><?php include "../Includes/staffSidebar.php"; ?></div>

<div id="layoutSidenav_content">
<main class="container-fluid px-4 py-4">

    <!-- Page heading -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h4 class="mb-0 fw-bold" style="color:var(--brand);">
                <i class="fa-solid fa-list-ol me-2"></i>Queue Management
            </h4>
            <p class="text-muted mb-0" style="font-size:.88rem;">
                <?= date('l, F j, Y') ?> &nbsp;·&nbsp; <span id="liveClock">--:--:--</span>
            </p>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <!-- TV Display link -->
            <a href="tv_display.php" target="_blank" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-tv me-1"></i> Open TV Display
            </a>
            <!-- Call Next -->
            <button class="btn-call-next" onclick="callNext()">
                <i class="fa-solid fa-bullhorn"></i> Call Next Patient
            </button>
        </div>
    </div>

    <!-- ── Stat Cards ── -->
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

    <!-- ── Now Serving ── -->
    <div class="card section-card shadow-sm mb-4">
        <div class="section-header">
            <i class="fa-solid fa-volume-high me-2"></i> Now Serving
        </div>
        <div class="card-body">
            <div id="nowServingBox">
                <div class="now-serving-banner empty">
                    <i class="fa-regular fa-face-meh fa-lg me-2"></i>
                    No patient is currently being called. Press <strong>&nbsp;Call Next Patient&nbsp;</strong> above to begin.
                </div>
            </div>
        </div>
    </div>

    <!-- ── Queue Table ── -->
    <div class="card section-card shadow-sm">
        <div class="section-header d-flex align-items-center justify-content-between">
            <span><i class="fa-solid fa-list me-2"></i> Today's Queue</span>
            <span class="badge bg-light text-dark fw-semibold" id="total-badge">
                <?= (int)($counts['total'] ?? 0) ?> total
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:100px;">Queue #</th>
                            <th>Patient</th>
                            <th>Age / Sex</th>
                            <th style="width:110px;">Priority</th>
                            <th style="width:120px;">Status</th>
                            <th>Wait Time</th>
                            <th style="width:200px;" class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="queueTableBody">
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fa-solid fa-spinner fa-spin"></i>
                                    Loading queue…
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>
<?php include "../Includes/footer.html"; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const SELF = window.location.pathname;

/* ── Live clock ──────────────────────────── */
function updateClock() {
    const d = new Date();
    document.getElementById('liveClock').textContent =
        String(d.getHours()).padStart(2,'0') + ':' +
        String(d.getMinutes()).padStart(2,'0') + ':' +
        String(d.getSeconds()).padStart(2,'0');
}
setInterval(updateClock, 1000);
updateClock();

/* ── Remove modal state ──────────────────── */
let _removeQueueId = null;

function openRemoveModal(queueId) {
    _removeQueueId = queueId;
    document.getElementById('removeModal').classList.add('show');
}
function closeRemoveModal() {
    _removeQueueId = null;
    document.getElementById('removeModal').classList.remove('show');
}
function confirmRemove() {
    if (!_removeQueueId) return;
    postAction('remove', { queue_id: _removeQueueId })
        .then(() => { closeRemoveModal(); fetchQueue(); });
}

/* ── Generic POST helper ─────────────────── */
function postAction(action, extra = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(extra)) fd.append(k, v);
    return fetch(SELF, { method: 'POST', body: fd }).then(r => r.json());
}

/* ── Call Next ───────────────────────────── */
function callNext() {
    postAction('call_next').then(data => {
        if (!data.success) { alert(data.error || 'No patients waiting.'); return; }
        fetchQueue();
    });
}

/* ── Call Specific ───────────────────────── */
function callSpecific(queueId) {
    // Un-serve whoever is in-progress first (server-side handles it via call_specific too)
    postAction('call_specific', { queue_id: queueId }).then(() => fetchQueue());
}

/* ── Mark Done ───────────────────────────── */
function markDone(queueId) {
    postAction('mark_done', { queue_id: queueId }).then(() => fetchQueue());
}

/* ── Toggle Priority ─────────────────────── */
function togglePriority(queueId, current) {
    postAction('toggle_priority', { queue_id: queueId, priority: current })
        .then(() => fetchQueue());
}

/* ── Wait time display ───────────────────── */
function minutesAgo(dateStr) {
    if (!dateStr) return '—';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 60000);
    if (diff < 1) return 'just now';
    return diff + ' min ago';
}

/* ── Render Now-Serving banner ───────────── */
function renderNowServing(inprog) {
    const box = document.getElementById('nowServingBox');
    if (!inprog) {
        box.innerHTML = `
            <div class="now-serving-banner empty">
                <i class="fa-regular fa-face-meh fa-lg me-2"></i>
                No patient is currently being called. Press <strong>&nbsp;Call Next Patient&nbsp;</strong> above to begin.
            </div>`;
        return;
    }
    box.innerHTML = `
        <div class="now-serving-banner">
            <div class="ns-pulse"></div>
            <div class="ns-ticket">${escHtml(inprog.queue_number)}</div>
            <div class="flex-grow-1">
                <div class="ns-name">${escHtml(inprog.full_name)}</div>
                <div class="ns-meta">
                    ${escHtml(inprog.age ?? '—')} yrs &nbsp;·&nbsp; ${escHtml(inprog.sex ?? '—')}
                    &nbsp;·&nbsp; Called ${minutesAgo(inprog.called_at)}
                </div>
            </div>
            <button class="btn btn-sm btn-done-q" onclick="markDone(${inprog.queue_id})">
                <i class="fa-solid fa-check me-1"></i> Mark Done
            </button>
        </div>`;
}

/* ── Render queue table rows ─────────────── */
function renderTable(rows) {
    const tbody = document.getElementById('queueTableBody');
    if (!rows.length) {
        tbody.innerHTML = `
            <tr><td colspan="7">
                <div class="empty-state">
                    <i class="fa-solid fa-inbox"></i>
                    No patients in today's queue yet.
                </div>
            </td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map(r => {
        const isWaiting = r.status === 'waiting';
        const isInprog  = r.status === 'in-progress';
        const isDone    = r.status === 'done';
        const isUrgent  = r.priority === 'urgent';

        const statusBadge = isWaiting
            ? `<span class="badge badge-waiting">Waiting</span>`
            : isInprog
                ? `<span class="badge badge-inprog"><i class="fa-solid fa-stethoscope me-1"></i>In Progress</span>`
                : `<span class="badge badge-done"><i class="fa-solid fa-check me-1"></i>Done</span>`;

        const priorityBadge = isUrgent
            ? `<span class="badge badge-urgent"><i class="fa-solid fa-bolt me-1"></i>Urgent</span>`
            : `<span class="badge badge-normal">Normal</span>`;

        const waitDisplay = isDone
            ? (r.called_at && r.created_at
                ? Math.round((new Date(r.called_at) - new Date(r.created_at)) / 60000) + ' min'
                : '—')
            : minutesAgo(r.created_at);

        /* Action buttons — contextual per status */
        let actions = '';
        if (isWaiting) {
            actions = `
                <button class="btn btn-sm btn-call me-1" onclick="callSpecific(${r.queue_id})" title="Call this patient">
                    <i class="fa-solid fa-bullhorn"></i>
                </button>
                <button class="btn btn-sm btn-urgent me-1" onclick="togglePriority(${r.queue_id}, '${r.priority}')" title="${isUrgent ? 'Remove urgent' : 'Mark as urgent'}">
                    <i class="fa-solid fa-bolt"></i>
                </button>
                <button class="btn btn-sm btn-remove" onclick="openRemoveModal(${r.queue_id})" title="Remove">
                    <i class="fa-solid fa-xmark"></i>
                </button>`;
        } else if (isInprog) {
            actions = `
                <button class="btn btn-sm btn-done-q" onclick="markDone(${r.queue_id})">
                    <i class="fa-solid fa-check me-1"></i> Done
                </button>`;
        } else {
            actions = `<span class="text-muted small">—</span>`;
        }

        return `
            <tr class="${isDone ? 'row-done' : ''}">
                <td>
                    <strong class="text-dark" style="font-size:1rem;font-family:monospace;">
                        ${escHtml(r.queue_number)}
                    </strong>
                </td>
                <td>
                    <div class="fw-semibold">${escHtml(r.full_name)}</div>
                    <div class="text-muted" style="font-size:.78rem;">Added ${escHtml(r.created_at ? r.created_at.slice(11,16) : '—')}</div>
                </td>
                <td class="text-muted">${escHtml(r.age ?? '—')} / ${escHtml(r.sex ?? '—')}</td>
                <td>${priorityBadge}</td>
                <td>${statusBadge}</td>
                <td class="text-muted" style="font-size:.88rem;">${waitDisplay}</td>
                <td class="text-end pe-3">${actions}</td>
            </tr>`;
    }).join('');
}

/* ── Fetch & refresh everything ──────────── */
function fetchQueue() {
    fetch(SELF + '?fetch_queue=1')
        .then(r => r.json())
        .then(data => {
            const { rows, counts, avg_wait } = data;

            document.getElementById('stat-waiting').textContent = counts.waiting;
            document.getElementById('stat-inprog').textContent  = counts.inprog;
            document.getElementById('stat-done').textContent    = counts.done;
            document.getElementById('stat-avg').textContent     = avg_wait !== null ? avg_wait : '—';
            document.getElementById('total-badge').textContent  = counts.total + ' total';

            const inprog = rows.find(r => r.status === 'in-progress') || null;
            renderNowServing(inprog);
            renderTable(rows);
        })
        .catch(err => console.error('Queue fetch error:', err));
}

/* ── XSS-safe escaping ───────────────────── */
function escHtml(str) {
    if (str === null || str === undefined) return '—';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Initial load + auto-refresh every 15s ── */
fetchQueue();
setInterval(fetchQueue, 15000);
</script>
</body>
</html>