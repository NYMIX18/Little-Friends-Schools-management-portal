<?php
session_start();
include("../config/db.php");

// Logout — destroys the session, then sends the user back to the real
// login page (index.php at the site root, two levels up from here).
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: ../index.php");
    exit();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header("Location: ../index.php");
    exit();
}

define('SCHOOL_NAME', 'Little Friends School');
$uid = (int)$_SESSION['user_id'];

// ─── STUDENT RECORD ────────────────────────────────────────────
// NOTE: students.id (sid) is what `marks` references.
//       students.user_id (== $uid) is what `fees_payments` references.
$stu = $conn->query("
    SELECT s.id AS sid, s.admission_number, s.class_id, s.registered_term_id,
           u.name, u.email, c.class_name, c.termly_fees
    FROM students s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE s.user_id = $uid
")->fetch_assoc();

if (!$stu) {
    die("Student record not found. Please contact the school office.");
}

// ─── TERMS ─────────────────────────────────────────────────────
$all_terms = [];
$res = $conn->query("SELECT * FROM terms ORDER BY id ASC");
while ($r = $res->fetch_assoc()) $all_terms[] = $r;

$sel_term = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
if (!$sel_term) {
    foreach ($all_terms as $t) { if ($t['is_active']) { $sel_term = $t['id']; break; } }
    if (!$sel_term && count($all_terms)) $sel_term = end($all_terms)['id'];
}

$view      = (isset($_GET['view']) && $_GET['view'] === 'fees') ? 'fees' : 'results';
$exam_type = (isset($_GET['exam_type']) && $_GET['exam_type'] === 'CAT') ? 'CAT' : 'End-term';

// ─── HELPER: fee balance (same logic as admin fees.php) ───────
function get_balance($conn, $student_id, $class_id, $term_id, $all_terms) {
    $r = $conn->query("SELECT termly_fees FROM classes WHERE id=" . (int)$class_id)->fetch_assoc();
    $term_fee = (float)($r['termly_fees'] ?? 0);

    $reg = $conn->query("SELECT registered_term_id FROM students WHERE user_id=" . (int)$student_id)->fetch_assoc();
    $start_term = (int)($reg['registered_term_id'] ?? 0);
    if (!$start_term) {
        $fp = $conn->query("SELECT MIN(term_id) as t FROM fees_payments WHERE student_id=" . (int)$student_id)->fetch_assoc();
        $start_term = (int)($fp['t'] ?? ($all_terms[0]['id'] ?? 1));
    }

    $arrears = 0;
    foreach ($all_terms as $t) {
        if ((int)$t['id'] >= (int)$term_id) break;
        if ((int)$t['id'] < $start_term)    continue;
        $pp = (float)$conn->query("SELECT COALESCE(SUM(amount_paid),0) as p FROM fees_payments
            WHERE student_id=".(int)$student_id." AND class_id=".(int)$class_id." AND term_id=".(int)$t['id'])->fetch_assoc()['p'];
        if ($pp < $term_fee) $arrears += ($term_fee - $pp);
    }

    $paid = (float)$conn->query("SELECT COALESCE(SUM(amount_paid),0) as p FROM fees_payments
        WHERE student_id=".(int)$student_id." AND class_id=".(int)$class_id." AND term_id=".(int)$term_id)->fetch_assoc()['p'];

    $total = $term_fee + $arrears;
    $bal   = max(0, $total - $paid);
    return [
        'term_fee'  => $term_fee,
        'arrears'   => $arrears,
        'total_due' => $total,
        'paid'      => $paid,
        'balance'   => $bal,
        'pct'       => $total > 0 ? min(100, round(($paid / $total) * 100)) : 100,
    ];
}

function grade($score) {
    if ($score >= 80) return "A";
    if ($score >= 65) return "B";
    if ($score >= 50) return "C";
    if ($score >= 35) return "D";
    return "E";
}
function gradeColor($g) {
    return ['A'=>'#10b981','B'=>'#3b82f6','C'=>'#f59e0b','D'=>'#f97316','E'=>'#ef4444'][$g] ?? '#94a3b8';
}
function remark($avg) {
    if ($avg >= 75) return ["Excellent performance!", "🏆"];
    if ($avg >= 60) return ["Very good work.",        "⭐"];
    if ($avg >= 50) return ["Good effort.",            "👍"];
    if ($avg >= 40) return ["Fair — improve more.",    "📈"];
    return ["Needs serious improvement.", "📚"];
}

// ─── FEE DATA ───────────────────────────────────────────────────
$bal = $stu['class_id'] ? get_balance($conn, $uid, $stu['class_id'], $sel_term, $all_terms) : null;

$payments = [];
$pr = $conn->query("SELECT fp.*, t.term_name FROM fees_payments fp
    JOIN terms t ON fp.term_id = t.id
    WHERE fp.student_id = $uid ORDER BY fp.payment_date DESC LIMIT 15");
if ($pr) while ($r = $pr->fetch_assoc()) $payments[] = $r;

// ─── RESULTS DATA ───────────────────────────────────────────────
$marks = [];
$total = 0; $count = 0;
$mr = $conn->query("
    SELECT m.score, m.grade, sub.subject_name
    FROM marks m
    JOIN subjects sub ON m.subject_id = sub.id
    WHERE m.student_id = {$stu['sid']} AND m.term_id = ".(int)$sel_term." AND m.exam_type = '".$conn->real_escape_string($exam_type)."'
    ORDER BY sub.subject_name ASC
");
if ($mr) while ($r = $mr->fetch_assoc()) { $marks[] = $r; $total += $r['score']; $count++; }
$avg = $count ? $total / $count : 0;
$rem = remark($avg);

$current_term_name = '';
foreach ($all_terms as $t) { if ($t['id'] == $sel_term) { $current_term_name = $t['term_name']; break; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SCHOOL_NAME ?> — Student Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --g:#15803d;--g2:#16a34a;--gb:#dcfce7;--gb2:#bbf7d0;--gd:#14532d;
  --blue:#2563eb;--bb:#dbeafe;
  --red:#dc2626;--rb:#fee2e2;
  --amber:#d97706;--ab:#fef3c7;
  --ink:#0f172a;--ink2:#475569;--ink3:#94a3b8;
  --bg:#f1f5f9;--border:#e2e8f0;
  --sans:'Plus Jakarta Sans',sans-serif;
  --mono:'JetBrains Mono',monospace;
  --r:16px;--rs:10px;
}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);min-height:100vh;font-size:14px}

.bar{background:#fff;border-bottom:1px solid var(--border);padding:0 20px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.bar-brand{display:flex;align-items:center;gap:10px}
.bar-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--g),var(--g2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
.bar-title{font-weight:800;font-size:15px}
.bar-sub{font-size:11px;color:var(--ink3);font-family:var(--mono)}
.bar-logout{padding:8px 14px;background:var(--bg);border:1px solid var(--border);border-radius:var(--rs);font-size:12px;font-weight:700;color:var(--ink2);text-decoration:none}
.bar-logout:hover{background:var(--rb);color:var(--red);border-color:#fca5a5}

.page{max-width:920px;margin:0 auto;padding:20px 14px 60px}

/* HERO */
.hero{
  background:linear-gradient(135deg,#14532d 0%,#15803d 55%,#16a34a 100%);
  border-radius:var(--r);padding:22px 22px 18px;margin-bottom:18px;color:#fff;
  position:relative;overflow:hidden;
}
.hero::before{content:'';position:absolute;top:-40px;right:-40px;width:150px;height:150px;background:rgba(255,255,255,.07);border-radius:50%}
.hero::after{content:'';position:absolute;bottom:-30px;right:70px;width:90px;height:90px;background:rgba(255,255,255,.05);border-radius:50%}
.hero-top{display:flex;align-items:center;gap:14px;position:relative;z-index:1}
.hero-av{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;flex-shrink:0}
.hero-name{font-size:18px;font-weight:800}
.hero-meta{font-size:12px;opacity:.8;font-family:var(--mono);margin-top:2px}
.hero-term{margin-top:16px;font-size:12px;opacity:.75;text-transform:uppercase;letter-spacing:.6px;font-weight:700}
.hero-term span{opacity:1;font-family:var(--mono);text-transform:none;letter-spacing:0}

/* TABS */
.tabs{display:flex;gap:4px;background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:4px;margin-bottom:16px}
.tab{flex:1;display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border-radius:11px;font-weight:700;font-size:13px;color:var(--ink2);text-decoration:none;transition:.15s}
.tab:hover{background:var(--bg)}
.tab.active{background:var(--g);color:#fff}

/* TERM SELECT */
.term-row{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.term-row label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink2)}
.term-sel{padding:9px 32px 9px 13px;background:#fff;border:1.5px solid var(--border);border-radius:var(--rs);font-family:var(--sans);font-size:13px;color:var(--ink);appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%2394a3b8'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;background-size:18px;cursor:pointer}
.term-sel:focus{outline:none;border-color:var(--g)}
.pill-toggle{display:flex;gap:4px;background:var(--bg);border:1px solid var(--border);border-radius:99px;padding:3px}
.pill{padding:7px 14px;border-radius:99px;font-size:12px;font-weight:700;color:var(--ink2);text-decoration:none;transition:.15s}
.pill.active{background:var(--g);color:#fff}

/* CARDS */
.card{background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:16px}
.card-title{font-weight:800;font-size:14px;margin-bottom:16px;display:flex;align-items:center;gap:8px}

/* RESULTS SUMMARY */
.sum-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
@media(max-width:480px){.sum-row{grid-template-columns:1fr}}
.sc{background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:16px}
.sc-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--ink3);margin-bottom:6px}
.sc-val{font-size:22px;font-weight:800;font-family:var(--mono)}
.sc-val.green{color:var(--g)}.sc-val.blue{color:var(--blue)}
.sc-sub{font-size:12px;color:var(--ink2);margin-top:4px}

table{width:100%;border-collapse:collapse}
thead th{padding:10px 14px;text-align:left;font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--ink2);border-bottom:1px solid var(--border);background:#f8fafc}
tbody tr{border-bottom:1px solid #f1f5f9}
tbody tr:last-child{border-bottom:none}
tbody td{padding:12px 14px;font-size:13px}
.grade-pill{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;font-size:.85rem;font-weight:800;color:#fff}
.score-bar{flex:1;height:5px;background:var(--bg);border-radius:3px;overflow:hidden;max-width:110px}
.score-fill{height:100%;border-radius:3px}

.remark-box{background:linear-gradient(135deg,var(--gb),#f0fdf4);border:1px solid var(--gb2);border-radius:var(--rs);padding:14px 16px;display:flex;align-items:center;gap:12px;margin-top:14px}
.remark-box .em{font-size:26px}
.remark-box .lbl{font-size:10px;color:var(--ink3);text-transform:uppercase;letter-spacing:.6px;font-weight:700}
.remark-box .txt{font-size:14px;font-weight:700;color:var(--gd);margin-top:2px}

/* FEE BANNER */
.fee-hero{background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:var(--r);padding:20px 22px;color:#fff;margin-bottom:16px;position:relative;overflow:hidden}
.fee-hero::before{content:'';position:absolute;top:-30px;right:-30px;width:130px;height:130px;background:rgba(255,255,255,.05);border-radius:50%}
.fh-lbl{font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:.6px;font-weight:700}
.fh-bal{font-size:34px;font-weight:800;font-family:var(--mono);margin-top:6px;position:relative;z-index:1}
.fh-bal.clear{color:#86efac}
.fh-sub{font-size:12px;opacity:.7;margin-top:6px;position:relative;z-index:1}
.fh-prog-wrap{margin-top:16px;position:relative;z-index:1}
.fh-prog{height:7px;background:rgba(255,255,255,.15);border-radius:99px;overflow:hidden}
.fh-prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#4ade80,#86efac);transition:width 1.2s ease}
.fh-prog-lbl{display:flex;justify-content:space-between;font-size:11px;opacity:.65;margin-top:6px}

.bal-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
@media(max-width:480px){.bal-row{grid-template-columns:1fr 1fr}.bal-row .bx:last-child{grid-column:span 2}}
.bx{background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:14px 16px}
.bx-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink3);margin-bottom:5px}
.bx-val{font-size:17px;font-weight:800;font-family:var(--mono)}
.bx-val.amber{color:var(--amber)}

.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:700;font-family:var(--mono)}
.b-paid{background:var(--gb);color:var(--g);border:1px solid var(--gb2)}
.b-partial{background:var(--ab);color:var(--amber);border:1px solid #fcd34d}
.b-cash{background:#f1f5f9;color:#334155;border:1px solid var(--border)}
.b-mpesa{background:var(--gb);color:var(--gd);border:1px solid var(--gb2)}

.empty{text-align:center;padding:44px 20px}
.empty-icon{font-size:42px;opacity:.3;margin-bottom:10px}
.empty-msg{font-weight:700;font-size:14px;color:var(--ink2)}

.btn-print{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;background:var(--g);color:#fff;border:none;border-radius:var(--rs);font-family:var(--sans);font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;margin-top:14px}
.btn-print:hover{background:var(--g2)}

@media print{
  .bar,.tabs,.term-row,.btn-print{display:none!important}
  body{background:#fff}
  .hero{background:#15803d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
</head>
<body>

<header class="bar">
  <div class="bar-brand">
    <div class="bar-icon">🎓</div>
    <div>
      <div class="bar-title"><?= SCHOOL_NAME ?></div>
      <div class="bar-sub">Student Portal</div>
    </div>
  </div>
  <a href="?logout=1" class="bar-logout" onclick="return confirm('Log out?')">Logout</a>
</header>

<div class="page">

  <!-- HERO -->
  <div class="hero">
    <div class="hero-top">
      <div class="hero-av"><?= strtoupper(substr($stu['name'],0,1)) ?></div>
      <div>
        <div class="hero-name"><?= htmlspecialchars($stu['name']) ?></div>
        <div class="hero-meta"><?= htmlspecialchars($stu['admission_number']) ?> · <?= htmlspecialchars($stu['class_name'] ?? '—') ?></div>
      </div>
    </div>
    <?php if ($current_term_name): ?>
    <div class="hero-term">Viewing <span><?= htmlspecialchars($current_term_name) ?></span></div>
    <?php endif; ?>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <a href="?view=results&term_id=<?= $sel_term ?>&exam_type=<?= $exam_type ?>" class="tab <?= $view==='results'?'active':'' ?>">📊 My Results</a>
    <a href="?view=fees&term_id=<?= $sel_term ?>" class="tab <?= $view==='fees'?'active':'' ?>">💳 Fee Balance</a>
  </div>

  <!-- TERM SELECT -->
  <div class="term-row">
    <label>Term</label>
    <select class="term-sel" onchange="location.href='?view=<?= $view ?>&exam_type=<?= $exam_type ?>&term_id='+this.value">
      <?php foreach ($all_terms as $t): ?>
      <option value="<?= $t['id'] ?>" <?= $t['id']==$sel_term?'selected':'' ?>><?= htmlspecialchars($t['term_name']) ?><?= $t['is_active']?' ✦':'' ?></option>
      <?php endforeach; ?>
    </select>

    <?php if ($view === 'results'): ?>
    <div class="pill-toggle">
      <a href="?view=results&term_id=<?= $sel_term ?>&exam_type=CAT" class="pill <?= $exam_type==='CAT'?'active':'' ?>">CAT</a>
      <a href="?view=results&term_id=<?= $sel_term ?>&exam_type=End-term" class="pill <?= $exam_type==='End-term'?'active':'' ?>">End-term</a>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($view === 'results'): ?>

  <!-- ══ RESULTS ══ -->
  <div class="sum-row">
    <div class="sc"><div class="sc-lbl">Average</div><div class="sc-val green"><?= number_format($avg,1) ?>%</div></div>
    <div class="sc"><div class="sc-lbl">Subjects</div><div class="sc-val blue"><?= $count ?></div></div>
    <div class="sc"><div class="sc-lbl">Total Score</div><div class="sc-val"><?= $total ?>/<?= $count*100 ?></div></div>
  </div>

  <div class="card">
    <div class="card-title">📘 <?= htmlspecialchars($exam_type) ?> Results — <?= htmlspecialchars($current_term_name) ?></div>

    <?php if (empty($marks)): ?>
    <div class="empty">
      <div class="empty-icon">📭</div>
      <div class="empty-msg">No results recorded yet for this term/exam.</div>
    </div>
    <?php else: ?>
    <table>
      <thead><tr><th>Subject</th><th style="text-align:center">Score</th><th style="text-align:center">Grade</th><th>Performance</th></tr></thead>
      <tbody>
      <?php foreach ($marks as $m): $gc = gradeColor($m['grade']); $pct = min(100,max(0,(int)$m['score'])); ?>
      <tr>
        <td style="font-weight:600"><?= htmlspecialchars($m['subject_name']) ?></td>
        <td style="text-align:center;font-family:var(--mono);font-weight:700"><?= $m['score'] ?>/100</td>
        <td style="text-align:center"><span class="grade-pill" style="background:<?= $gc ?>"><?= $m['grade'] ?></span></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div class="score-bar"><div class="score-fill" style="width:<?= $pct ?>%;background:<?= $gc ?>"></div></div>
            <span style="font-size:11px;color:var(--ink3);min-width:30px"><?= $pct ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="remark-box">
      <span class="em"><?= $rem[1] ?></span>
      <div><div class="lbl">Teacher's Remark</div><div class="txt"><?= htmlspecialchars($rem[0]) ?></div></div>
    </div>

    <a href="#" onclick="window.print();return false;" class="btn-print">🖨 Print Result Slip</a>
    <?php endif; ?>
  </div>

  <?php else: /* ══ FEES ══ */ ?>

  <?php if (!$stu['class_id']): ?>
  <div class="card"><div class="empty"><div class="empty-icon">🏫</div><div class="empty-msg">No class assigned yet — contact the school office.</div></div></div>
  <?php else: ?>

  <div class="fee-hero">
    <div class="fh-lbl">Balance Due — <?= htmlspecialchars($current_term_name) ?></div>
    <div class="fh-bal <?= $bal['balance']<=0?'clear':'' ?>">
      <?= $bal['balance']<=0 ? '✓ Fully Paid' : 'KES '.number_format($bal['balance']) ?>
    </div>
    <div class="fh-sub">Total due KES <?= number_format($bal['total_due']) ?> · Paid KES <?= number_format($bal['paid']) ?></div>
    <div class="fh-prog-wrap">
      <div class="fh-prog"><div class="fh-prog-fill" id="fhPf" style="width:0%"></div></div>
      <div class="fh-prog-lbl"><span><?= $bal['pct'] ?>% cleared</span><span>KES <?= number_format($bal['total_due']) ?> expected</span></div>
    </div>
  </div>

  <div class="bal-row">
    <div class="bx"><div class="bx-lbl">Term Fee</div><div class="bx-val">KES <?= number_format($bal['term_fee']) ?></div></div>
    <div class="bx"><div class="bx-lbl">Arrears</div><div class="bx-val amber"><?= $bal['arrears']>0 ? 'KES '.number_format($bal['arrears']) : '—' ?></div></div>
    <div class="bx"><div class="bx-lbl">Amount Paid</div><div class="bx-val" style="color:var(--g)">KES <?= number_format($bal['paid']) ?></div></div>
  </div>

  <div class="card">
    <div class="card-title">🧾 Payment History</div>
    <?php if (empty($payments)): ?>
    <div class="empty"><div class="empty-icon">📭</div><div class="empty-msg">No payments recorded yet.</div></div>
    <?php else: ?>
    <table>
      <thead><tr><th>Date</th><th>Term</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): $mp = $p['pay_method']==='M-Pesa'; ?>
      <tr>
        <td style="font-size:12px;color:var(--ink2)"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
        <td><?= htmlspecialchars($p['term_name']) ?></td>
        <td style="font-family:var(--mono);font-weight:700;color:var(--g)">KES <?= number_format($p['amount_paid']) ?></td>
        <td>
          <span class="badge <?= $mp?'b-mpesa':'b-cash' ?>"><?= htmlspecialchars($p['pay_method']) ?></span>
          <?php if ($p['pay_ref']): ?><div style="font-size:10px;font-family:var(--mono);color:var(--ink2);margin-top:2px"><?= htmlspecialchars($p['pay_ref']) ?></div><?php endif; ?>
        </td>
        <td><span class="badge <?= $p['status']==='Paid'?'b-paid':'b-partial' ?>"><?= htmlspecialchars($p['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php endif; ?>
  <?php endif; ?>

</div><!-- /.page -->

<script>
window.addEventListener('DOMContentLoaded', function(){
  var pf = document.getElementById('fhPf');
  if (pf) setTimeout(function(){ pf.style.width = '<?= $bal['pct'] ?? 0 ?>%'; }, 300);
});
</script>
</body>
</html>