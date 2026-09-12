<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

define('SCHOOL_NAME', 'Little Friends School');
define('MPESA_TILL',  '8023488');

// ─── HELPER: get student balance ─────────────────────────────
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

// ─── BASE DATA ────────────────────────────────────────────────
$all_terms = [];
$res = $conn->query("SELECT * FROM terms ORDER BY id ASC");
while ($r = $res->fetch_assoc()) $all_terms[] = $r;

$classes = [];
$res = $conn->query("SELECT * FROM classes ORDER BY class_name");
while ($r = $res->fetch_assoc()) $classes[] = $r;

// ─── STATE ────────────────────────────────────────────────────
$sel_class = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$sel_term  = isset($_GET['term_id'])  ? (int)$_GET['term_id']  : 0;
$view      = isset($_GET['view']) && $_GET['view'] === 'report' ? 'report' : 'pay';

if (!$sel_term)  { foreach ($all_terms as $t) { if ($t['is_active']) { $sel_term  = $t['id'];    break; } } }
if (!$sel_class) { if (count($classes))        { $sel_class = $classes[0]['id']; } }

// ─── EARLY-EXIT HANDLERS ──────────────────────────────────────

// AJAX: payment history
if (isset($_GET['hist'])) {
    $sid = (int)$_GET['sid'];
    $res = $conn->query("SELECT fp.id, fp.amount_paid, fp.pay_method,
        COALESCE(fp.pay_ref,'') as pay_ref, COALESCE(fp.receipt_no,'') as receipt_no,
        fp.status, fp.payment_date, t.term_name
        FROM fees_payments fp
        JOIN terms t ON fp.term_id = t.id
        WHERE fp.student_id = $sid ORDER BY fp.payment_date DESC");
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// PRINT: single receipt
if (isset($_GET['print_receipt'], $_GET['pid'])) {
    $pid = (int)$_GET['pid'];
    $rp  = $conn->query("SELECT fp.*, u.name, st.admission_number, c.class_name, t.term_name,
        COALESCE(pu.phone_number,'') as parent_phone
        FROM fees_payments fp
        JOIN students st ON fp.student_id = st.user_id
        JOIN users u ON st.user_id = u.id
        JOIN classes c ON fp.class_id = c.id
        JOIN terms t ON fp.term_id = t.id
        LEFT JOIN users pu ON st.parent_id = pu.id
        WHERE fp.id = $pid")->fetch_assoc();
    if ($rp) {
        header('Content-Type: text/html; charset=UTF-8');
        ?><!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>Receipt <?= htmlspecialchars($rp['receipt_no']) ?></title>
        <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:Arial,sans-serif;font-size:11px;background:#fff;padding:8mm}
        .hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #166534;padding-bottom:8px;margin-bottom:10px}
        h1{font-size:16px;font-weight:900;color:#166534}
        h2{font-size:10px;color:#777;margin-top:2px}
        .rno{text-align:right}.rno .no{font-size:13px;font-weight:900;font-family:monospace;color:#166534}
        .rno .lbl{font-size:9px;color:#999}
        .amt-box{background:#f0fdf4;border:2px solid #166534;border-radius:6px;padding:10px 14px;margin:10px 0;display:flex;justify-content:space-between;align-items:center}
        .big{font-size:26px;font-weight:900;color:#166534;font-family:monospace}
        .row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dotted #ddd;font-size:11px}
        .row:last-child{border:none}
        .k{color:#666}.v{font-weight:700}
        .ft{margin-top:12px;border-top:1px dashed #ccc;padding-top:8px;display:flex;justify-content:space-between;font-size:10px;color:#999}
        .sig-line{border-bottom:1px solid #999;width:90px;margin:20px 0 3px auto}
        @media print{@page{size:A5 portrait;margin:5mm}}
        </style></head><body>
        <div class="hd">
            <div><h1><?= SCHOOL_NAME ?></h1><h2>Official Fee Receipt</h2></div>
            <div class="rno"><div class="lbl">Receipt No.</div><div class="no"><?= htmlspecialchars($rp['receipt_no']) ?></div></div>
        </div>
        <div class="amt-box">
            <div>
                <div style="font-size:9px;color:#555;text-transform:uppercase;letter-spacing:1px">Amount Received</div>
                <div class="big">KES <?= number_format($rp['amount_paid']) ?></div>
            </div>
            <div style="text-align:right">
                <div style="font-weight:700;color:<?= $rp['status']==='Paid'?'#166534':'#b45309' ?>"><?= htmlspecialchars($rp['status']) ?></div>
                <div style="font-size:10px;color:#666"><?= date('d M Y, g:i A', strtotime($rp['payment_date'])) ?></div>
            </div>
        </div>
        <?php foreach ([
            'Student'    => $rp['name'],
            'Adm. No.'   => $rp['admission_number'],
            'Class'      => $rp['class_name'],
            'Term'       => $rp['term_name'],
            'Parent Tel' => $rp['parent_phone'] ?: 'N/A',
            'Method'     => $rp['pay_method'],
        ] as $k => $v): ?>
        <div class="row"><span class="k"><?= $k ?></span><span class="v"><?= htmlspecialchars($v) ?></span></div>
        <?php endforeach; ?>
        <?php if ($rp['pay_ref']): ?>
        <div class="row"><span class="k">M-Pesa Code</span><span class="v" style="color:#166534;font-family:monospace"><?= htmlspecialchars($rp['pay_ref']) ?></span></div>
        <?php endif; ?>
        <div class="ft">
            <div><?= SCHOOL_NAME ?> · Sotik, Bomet County</div>
            <div><div class="sig-line"></div>Authorised Signature</div>
        </div>
        <script>window.onload=function(){window.print()}</script>
        </body></html>
        <?php
        exit();
    }
}

// PRINT: class fee list
if (isset($_GET['print_list'])) {
    $p_students = [];
    $res = $conn->query("SELECT st.user_id, st.admission_number, u.name
        FROM students st JOIN users u ON st.user_id = u.id
        WHERE st.class_id = ".(int)$sel_class." ORDER BY u.name ASC");
    if ($res) while ($r = $res->fetch_assoc()) $p_students[] = $r;

    $cn = ''; $tn = '';
    foreach ($classes   as $c) { if ($c['id'] == $sel_class) { $cn = $c['class_name']; break; } }
    foreach ($all_terms as $t) { if ($t['id'] == $sel_term)  { $tn = $t['term_name'];  break; } }

    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fees List</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:Arial,sans-serif;font-size:11px;padding:8mm}
    h1{font-size:15px;font-weight:900;color:#15803d}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    thead th{background:#15803d;color:#fff;padding:7px 10px;text-align:left;font-size:10px;text-transform:uppercase}
    tbody tr:nth-child(even){background:#f9fafb}
    tbody td{padding:7px 10px;border-bottom:1px solid #e5e7eb}
    tfoot td{padding:8px 10px;font-weight:700;background:#dcfce7;color:#14532d;border-top:2px solid #bbf7d0}
    @media print{@page{size:A4 landscape;margin:8mm}}
    </style></head><body>
    <h1><?= SCHOOL_NAME ?> — Fee Collection: <?= htmlspecialchars($cn) ?> · <?= htmlspecialchars($tn) ?></h1>
    <div style="font-size:10px;color:#888;margin-top:3px">Printed: <?= date('d M Y, g:i A') ?> · <?= count($p_students) ?> students</div>
    <table>
    <thead><tr><th>#</th><th>Name</th><th>Adm No</th><th>Term Fee</th><th>Arrears</th><th>Total Due</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
    <tbody>
    <?php
    $xp=$xt=$xb=0;
    foreach ($p_students as $i => $s) {
        $b  = get_balance($conn, $s['user_id'], $sel_class, $sel_term, $all_terms);
        $st = $b['balance']<=0 ? 'PAID' : ($b['paid']>0 ? 'PARTIAL' : 'UNPAID');
        $xp += $b['paid']; $xt += $b['total_due']; $xb += $b['balance'];
        echo '<tr><td>'.($i+1).'</td>
            <td><strong>'.htmlspecialchars($s['name']).'</strong></td>
            <td style="font-family:monospace">'.htmlspecialchars($s['admission_number']).'</td>
            <td>KES '.number_format($b['term_fee']).'</td>
            <td>'.($b['arrears']>0 ? '+'.number_format($b['arrears']) : '—').'</td>
            <td><strong>KES '.number_format($b['total_due']).'</strong></td>
            <td>KES '.number_format($b['paid']).'</td>
            <td style="color:'.($b['balance']>0?'#dc2626':'#15803d').';font-weight:700">'.($b['balance']>0 ? 'KES '.number_format($b['balance']) : '✓').'</td>
            <td style="color:'.($st==='PAID'?'#15803d':($st==='PARTIAL'?'#d97706':'#dc2626')).';font-weight:700">'.$st.'</td>
        </tr>';
    }
    ?>
    </tbody>
    <tfoot><tr>
        <td colspan="5">TOTALS — <?= count($p_students) ?> students</td>
        <td>KES <?= number_format($xt) ?></td>
        <td>KES <?= number_format($xp) ?></td>
        <td>KES <?= number_format($xb) ?></td>
        <td><?= $xt>0 ? round(($xp/$xt)*100) : 0 ?>% collected</td>
    </tr></tfoot>
    </table>
    <script>window.onload=function(){window.print()}</script>
    </body></html>
    <?php
    exit();
}

// EXPORT: CSV
if (isset($_GET['export_csv'])) {
    $w = "fp.term_id=".(int)$sel_term;
    if ($sel_class) $w .= " AND fp.class_id=".(int)$sel_class;
    $res = $conn->query("SELECT fp.receipt_no, u.name, st.admission_number, c.class_name,
        t.term_name, fp.amount_paid, fp.pay_method,
        COALESCE(fp.pay_ref,'') as pay_ref, fp.status, fp.payment_date
        FROM fees_payments fp
        JOIN students st ON fp.student_id = st.user_id
        JOIN users u ON st.user_id = u.id
        JOIN classes c ON fp.class_id = c.id
        JOIN terms t ON fp.term_id = t.id
        WHERE $w ORDER BY fp.payment_date DESC");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fees_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Receipt No','Student','Adm No','Class','Term','Amount (KES)','Method','M-Pesa Code','Status','Date']);
    if ($res) while ($r = $res->fetch_assoc()) {
        fputcsv($out, [$r['receipt_no'],$r['name'],$r['admission_number'],$r['class_name'],
            $r['term_name'],$r['amount_paid'],$r['pay_method'],$r['pay_ref'],$r['status'],$r['payment_date']]);
    }
    fclose($out);
    exit();
}

// PRINT: today's receipts as 2-per-A4 PDF-ready page
if (isset($_GET['print_today'])) {
    $today_start = date('Y-m-d') . ' 00:00:00';
    $today_end   = date('Y-m-d') . ' 23:59:59';

    $w = "fp.payment_date BETWEEN '$today_start' AND '$today_end'";
    if ($sel_term)  $w .= " AND fp.term_id=".(int)$sel_term;
    if ($sel_class) $w .= " AND fp.class_id=".(int)$sel_class;

    $res = $conn->query("SELECT fp.*, u.name, st.admission_number, c.class_name, t.term_name,
        COALESCE(pu.phone_number,'') as parent_phone
        FROM fees_payments fp
        JOIN students st ON fp.student_id = st.user_id
        JOIN users u ON st.user_id = u.id
        JOIN classes c ON fp.class_id = c.id
        JOIN terms t ON fp.term_id = t.id
        LEFT JOIN users pu ON st.parent_id = pu.id
        WHERE $w ORDER BY fp.payment_date ASC");

    $recs = [];
    if ($res) while ($r = $res->fetch_assoc()) $recs[] = $r;

    // Class / term label for the header
    $cn = ''; $tn = '';
    foreach ($classes   as $c) { if ($c['id'] == $sel_class) { $cn = $c['class_name']; break; } }
    foreach ($all_terms as $t) { if ($t['id'] == $sel_term)  { $tn = $t['term_name'];  break; } }

    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Receipts – <?= date('d M Y') ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#fff;font-size:11px}

/* cover page */
.cover{
  width:210mm;height:297mm;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  text-align:center;page-break-after:always;
}
.cover h1{font-size:26px;font-weight:900;color:#15803d;margin-bottom:6px}
.cover p{font-size:13px;color:#555;margin-top:6px}
.cover .big{font-size:48px;font-weight:900;color:#15803d;margin:20px 0;font-family:monospace}
.cover .info{font-size:14px;color:#333;margin-top:4px;line-height:2}

/* A4 sheet: 2 receipts stacked */
.sheet{
  width:210mm;height:297mm;
  display:flex;flex-direction:column;
  page-break-after:always;
}
.half{
  width:100%;height:148.5mm;
  padding:8mm 10mm;
  position:relative;
  overflow:hidden;
}
.half:first-child{
  border-bottom:1px dashed #999;
}
/* scissors icon on cut line */
.half:first-child::after{
  content:'✂ cut here';
  position:absolute;bottom:-1px;left:50%;transform:translateX(-50%);
  background:#fff;padding:0 8px;
  font-size:9px;color:#999;letter-spacing:1px;
  font-style:italic;
}

.r-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #166534;padding-bottom:6px;margin-bottom:8px}
.r-head h1{font-size:14px;font-weight:900;color:#166534}
.r-head h2{font-size:9px;color:#777;margin-top:1px}
.rno .no{font-size:12px;font-weight:900;font-family:monospace;color:#166534}
.rno .lbl{font-size:9px;color:#aaa;text-align:right}

.amt-box{background:#f0fdf4;border:2px solid #166534;border-radius:5px;padding:8px 12px;margin:7px 0;display:flex;justify-content:space-between;align-items:center}
.big-amt{font-size:24px;font-weight:900;color:#166534;font-family:monospace}
.row{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dotted #ddd;font-size:10px}
.row:last-child{border:none}
.k{color:#666}.v{font-weight:700}
.ft{margin-top:8px;display:flex;justify-content:space-between;font-size:9px;color:#aaa;border-top:1px dashed #ccc;padding-top:6px}
.sig-line{border-bottom:1px solid #aaa;width:80px;margin:14px 0 2px auto}

@media print{
  @page{size:A4 portrait;margin:0}
  body{margin:0}
  .cover,.sheet{page-break-after:always}
  .sheet:last-child{page-break-after:auto}
}
</style>
</head>
<body>

<!-- COVER PAGE -->
<div class="cover">
  <h1><?= SCHOOL_NAME ?></h1>
  <p style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:1px">Fee Receipts Batch</p>
  <div class="big"><?= count($recs) ?></div>
  <div class="info">
    <strong>Date:</strong> <?= date('l, d F Y') ?><br>
    <?php if ($cn): ?><strong>Class:</strong> <?= htmlspecialchars($cn) ?><br><?php endif; ?>
    <?php if ($tn): ?><strong>Term:</strong>  <?= htmlspecialchars($tn) ?><br><?php endif; ?>
    <strong>Total Collected:</strong> KES <?= number_format(array_sum(array_column($recs,'amount_paid'))) ?>
  </div>
  <p style="margin-top:30px;font-size:11px;color:#aaa">Print · Cut along dashed lines · Distribute to students</p>
</div>

<?php
// Pair up receipts: 2 per A4 sheet
$chunks = array_chunk($recs, 2);
foreach ($chunks as $pair):
  // pad to always have 2 slots
  while (count($pair) < 2) $pair[] = null;
?>
<div class="sheet">
<?php foreach ($pair as $rp): ?>
<div class="half">
<?php if ($rp): ?>
  <div class="r-head">
    <div><h1><?= SCHOOL_NAME ?></h1><h2>Official Fee Receipt</h2></div>
    <div class="rno"><div class="lbl">Receipt No.</div><div class="no"><?= htmlspecialchars($rp['receipt_no']) ?></div></div>
  </div>
  <div class="amt-box">
    <div>
      <div style="font-size:8px;color:#555;text-transform:uppercase;letter-spacing:1px">Amount Received</div>
      <div class="big-amt">KES <?= number_format($rp['amount_paid']) ?></div>
    </div>
    <div style="text-align:right">
      <div style="font-weight:700;color:<?= $rp['status']==='Paid'?'#166534':'#b45309' ?>"><?= htmlspecialchars($rp['status']) ?></div>
      <div style="font-size:9px;color:#666"><?= date('d M Y, g:i A', strtotime($rp['payment_date'])) ?></div>
    </div>
  </div>
  <?php foreach ([
    'Student'    => $rp['name'],
    'Adm. No.'   => $rp['admission_number'],
    'Class'      => $rp['class_name'],
    'Term'       => $rp['term_name'],
    'Method'     => $rp['pay_method'],
  ] as $k => $v): ?>
  <div class="row"><span class="k"><?= $k ?></span><span class="v"><?= htmlspecialchars($v) ?></span></div>
  <?php endforeach; ?>
  <?php if ($rp['pay_ref']): ?>
  <div class="row"><span class="k">M-Pesa Code</span><span class="v" style="color:#166534;font-family:monospace"><?= htmlspecialchars($rp['pay_ref']) ?></span></div>
  <?php endif; ?>
  <div class="ft">
    <div><?= SCHOOL_NAME ?> · Sotik, Bomet County</div>
    <div><div class="sig-line"></div>Authorised Signature</div>
  </div>
<?php else: ?>
  <!-- empty slot — intentionally blank -->
  <div style="height:100%;display:flex;align-items:center;justify-content:center;opacity:.15;font-size:13px">[ blank ]</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if (empty($recs)): ?>
<div style="width:210mm;height:297mm;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px">
  <div style="font-size:40px">📭</div>
  <div style="font-weight:700;font-size:16px">No payments recorded today</div>
  <div style="color:#888;font-size:13px"><?= date('d F Y') ?> · <?= htmlspecialchars($cn) ?> · <?= htmlspecialchars($tn) ?></div>
</div>
<?php endif; ?>

<script>window.onload=function(){window.print()}</script>
</body></html>
<?php
    exit();
}

// ─── HANDLE PAYMENT POST ──────────────────────────────────────
$flash      = '';
$flash_type = 'ok';
$new_receipt = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_pay'])) {
    $stu_id  = (int)$_POST['student_id'];
    $amount  = (float)$_POST['amount'];
    $method  = (isset($_POST['method']) && $_POST['method'] === 'M-Pesa') ? 'M-Pesa' : 'Cash';
    $ref     = strtoupper(trim($_POST['ref'] ?? ''));
    $p_class = (int)$_POST['class_id'];
    $p_term  = (int)$_POST['term_id'];
    $err     = '';

    if ($method === 'M-Pesa') {
        if (!preg_match('/^[A-Z0-9]{8,14}$/', $ref)) {
            $err = 'Enter a valid M-Pesa code (e.g. QHG8X12345)';
        } else {
            $dup = $conn->query("SELECT id FROM fees_payments WHERE pay_ref='".$conn->real_escape_string($ref)."' LIMIT 1");
            if ($dup && $dup->num_rows) $err = "M-Pesa code $ref already recorded — duplicate blocked.";
        }
    }

    if (!$err && $stu_id && $amount > 0 && $p_class && $p_term) {
        $rc = $conn->query("SELECT registered_term_id FROM students WHERE user_id=$stu_id")->fetch_assoc();
        if (empty($rc['registered_term_id'])) {
            $conn->query("UPDATE students SET registered_term_id=$p_term WHERE user_id=$stu_id");
        }

        $b       = get_balance($conn, $stu_id, $p_class, $p_term, $all_terms);
        $total   = $b['total_due'];
        $status  = ($b['paid'] + $amount) >= $total ? 'Paid' : 'Partial';
        $rcp_no  = 'RCP-'.date('Y').'-'.str_pad(rand(1,99999),5,'0',STR_PAD_LEFT);
        $new_bal = max(0, $total - ($b['paid'] + $amount));

        $st = $conn->prepare("INSERT INTO fees_payments
            (student_id,class_id,term_id,amount_due,amount_paid,payment_date,status,pay_method,pay_ref,receipt_no)
            VALUES (?,?,?,?,?,NOW(),?,?,?,?)");
        $st->bind_param("iiiddssss", $stu_id, $p_class, $p_term, $total, $amount, $status, $method, $ref, $rcp_no);
        $st->execute();
        $last_id = $conn->insert_id;
        $st->close();

        $info = $conn->query("SELECT u.name, st.admission_number, c.class_name,
            COALESCE(pu.phone_number,'') as parent_phone
            FROM students st
            JOIN users u ON st.user_id = u.id
            LEFT JOIN classes c ON st.class_id = c.id
            LEFT JOIN users pu ON st.parent_id = pu.id
            WHERE st.user_id = $stu_id")->fetch_assoc();

        $tname = '';
        foreach ($all_terms as $t) { if ($t['id'] == $p_term) { $tname = $t['term_name']; break; } }

        $new_receipt = [
            'no'      => $rcp_no,
            'name'    => $info['name'] ?? '',
            'adm'     => $info['admission_number'] ?? '',
            'class'   => $info['class_name'] ?? '',
            'term'    => $tname,
            'amount'  => $amount,
            'method'  => $method,
            'ref'     => $ref,
            'total'   => $total,
            'balance' => $new_bal,
            'status'  => $status,
            'phone'   => $info['parent_phone'] ?? '',
            'date'    => date('d M Y, g:i A'),
            'pid'     => $last_id,
        ];
        $flash     = "Payment recorded — ".$rcp_no;
        $sel_class = $p_class;
        $sel_term  = $p_term;
    } else {
        $flash      = $err ?: 'Please fill in all fields correctly.';
        $flash_type = 'err';
        $sel_class  = (int)($_POST['class_id'] ?? $sel_class);
        $sel_term   = (int)($_POST['term_id']  ?? $sel_term);
    }
}

// ─── DELETE PAYMENT ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_id'])) {
    $conn->query("DELETE FROM fees_payments WHERE id=".(int)$_POST['del_id']);
    $flash     = 'Payment deleted.';
    $sel_class = (int)($_POST['class_id'] ?? $sel_class);
    $sel_term  = (int)($_POST['term_id']  ?? $sel_term);
}

// ─── LOAD STUDENTS ────────────────────────────────────────────
$students   = [];
$class_info = null;
$term_info  = null;

if ($sel_class && $sel_term) {
    foreach ($classes   as $c) { if ($c['id'] == $sel_class) { $class_info = $c; break; } }
    foreach ($all_terms as $t) { if ($t['id'] == $sel_term)  { $term_info  = $t; break; } }

    $res = $conn->query("SELECT st.user_id, st.admission_number, u.name,
        COALESCE(pu.phone_number,'') as parent_phone
        FROM students st
        JOIN users u ON st.user_id = u.id
        LEFT JOIN users pu ON st.parent_id = pu.id
        WHERE st.class_id = ".(int)$sel_class." ORDER BY u.name ASC");
    if ($res) while ($r = $res->fetch_assoc()) $students[] = $r;
}

// ─── TERM-WIDE TOTALS (all classes for selected term) ─────────
$term_total_received = 0;
$term_total_expected = 0;
if ($sel_term) {
    // Total received for the whole term across all classes
    $tr = $conn->query("SELECT COALESCE(SUM(amount_paid),0) as r FROM fees_payments WHERE term_id=".(int)$sel_term." AND status IN ('Paid','Partial')");
    if ($tr) $term_total_received = (float)$tr->fetch_assoc()['r'];

    // Expected: sum of termly_fees * student count per class
    $te = $conn->query("SELECT COALESCE(SUM(c.termly_fees),0) as e
        FROM students st
        JOIN classes c ON st.class_id = c.id");
    if ($te) $term_total_expected = (float)$te->fetch_assoc()['e'];
}
$term_pending     = max(0, $term_total_expected - $term_total_received);
$term_collect_pct = $term_total_expected > 0 ? min(100, round(($term_total_received / $term_total_expected) * 100)) : 0;

// Current term name
$current_term_name = '';
foreach ($all_terms as $t) { if ($t['id'] == $sel_term) { $current_term_name = $t['term_name']; break; } }

// ─── REPORT DATA ─────────────────────────────────────────────
$report_payments = [];
if ($view === 'report' && $sel_term) {
    $w = "fp.term_id=".(int)$sel_term;
    if ($sel_class) $w .= " AND fp.class_id=".(int)$sel_class;
    $res = $conn->query("SELECT fp.id, fp.amount_paid, fp.payment_date, fp.status,
        fp.pay_method, COALESCE(fp.pay_ref,'') as pay_ref, COALESCE(fp.receipt_no,'') as receipt_no,
        u.name, st.admission_number, c.class_name, t.term_name
        FROM fees_payments fp
        JOIN students st ON fp.student_id = st.user_id
        JOIN users u ON st.user_id = u.id
        JOIN classes c ON fp.class_id = c.id
        JOIN terms t ON fp.term_id = t.id
        WHERE $w ORDER BY fp.payment_date DESC");
    if ($res) while ($r = $res->fetch_assoc()) $report_payments[] = $r;
}

// ─── CLASS SUMMARY ────────────────────────────────────────────
$total_collected = $total_expected = 0;
foreach ($students as $s) {
    $b = get_balance($conn, $s['user_id'], $sel_class, $sel_term, $all_terms);
    $total_expected  += $b['total_due'];
    $total_collected += $b['paid'];
}
$collection_pct = $total_expected > 0 ? round(($total_collected / $total_expected) * 100) : 0;
$outstanding    = max(0, $total_expected - $total_collected);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SCHOOL_NAME ?> — Fees</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --g:#15803d;--g2:#16a34a;--gb:#dcfce7;--gb2:#bbf7d0;--gd:#14532d;
  --red:#dc2626;--rb:#fee2e2;
  --amber:#d97706;--ab:#fef3c7;
  --ink:#0f172a;--ink2:#475569;--ink3:#94a3b8;
  --bg:#f1f5f9;--border:#e2e8f0;
  --sans:'Plus Jakarta Sans',sans-serif;
  --mono:'JetBrains Mono',monospace;
  --r:12px;--rs:7px;
}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);min-height:100vh;font-size:14px}

/* TOPBAR */
.bar{background:var(--g);color:#fff;padding:0 20px;height:54px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.bar-brand{display:flex;align-items:center;gap:10px}
.bar-icon{width:34px;height:34px;background:rgba(255,255,255,.2);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px}
.bar-title{font-weight:800;font-size:15px}
.bar-sub{font-size:11px;opacity:.7;font-family:var(--mono)}
.bar-back{padding:7px 13px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:var(--rs);font-size:12px;font-weight:600;color:#fff;text-decoration:none}
.bar-back:hover{background:rgba(255,255,255,.25)}

/* PAGE */
.page{max-width:960px;margin:0 auto;padding:20px 14px 60px}

/* ── TERM BANNER ─────────────────────────────────────────── */
.term-banner{
  background:linear-gradient(135deg,#14532d 0%,#15803d 60%,#16a34a 100%);
  border-radius:var(--r);
  padding:18px 20px 14px;
  margin-bottom:18px;
  color:#fff;
  position:relative;
  overflow:hidden;
}
.term-banner::before{
  content:'';
  position:absolute;top:-30px;right:-30px;
  width:120px;height:120px;
  background:rgba(255,255,255,.06);
  border-radius:50%;
}
.term-banner::after{
  content:'';
  position:absolute;bottom:-20px;right:60px;
  width:80px;height:80px;
  background:rgba(255,255,255,.04);
  border-radius:50%;
}
.tb-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;opacity:.75;margin-bottom:4px}
.tb-term{font-size:13px;font-weight:800;opacity:.9;margin-bottom:14px;font-family:var(--mono)}
.tb-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;position:relative;z-index:1}
@media(max-width:480px){.tb-grid{grid-template-columns:1fr 1fr}.tb-grid .tbc:last-child{grid-column:span 2}}
.tbc{}
.tbc-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;opacity:.65;margin-bottom:4px}
.tbc-val{font-size:20px;font-weight:800;font-family:var(--mono);line-height:1}
.tbc-val.green{color:#86efac}
.tbc-val.red{color:#fca5a5}
.tbc-val.white{color:#fff}
.tb-prog-wrap{margin-top:14px;position:relative;z-index:1}
.tb-prog-label{display:flex;justify-content:space-between;font-size:11px;opacity:.7;margin-bottom:5px}
.tb-prog{height:6px;background:rgba(255,255,255,.2);border-radius:99px;overflow:hidden}
.tb-prog-fill{height:100%;border-radius:99px;background:#86efac;transition:width 1.2s ease}

/* FLASH */
.flash{padding:11px 16px;border-radius:var(--r);font-weight:600;font-size:13px;margin-bottom:18px;border:1px solid}
.flash.ok{background:var(--gb);color:var(--g);border-color:var(--gb2)}
.flash.err{background:var(--rb);color:var(--red);border-color:#fca5a5}

/* TABS */
.tabs{display:flex;gap:4px;background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:4px;margin-bottom:18px}
.tab{flex:1;display:flex;align-items:center;justify-content:center;gap:7px;padding:10px;border-radius:9px;font-weight:700;font-size:13px;color:var(--ink2);text-decoration:none;transition:.15s}
.tab:hover{background:var(--bg);color:var(--ink)}
.tab.active{background:var(--g);color:#fff}

/* FILTERS */
.filters{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
@media(max-width:540px){.filters{grid-template-columns:1fr}}
.filter-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink2);margin-bottom:5px}
.filter-sel{width:100%;padding:10px 32px 10px 13px;background:#fff;border:1.5px solid var(--border);border-radius:var(--rs);font-family:var(--sans);font-size:13px;color:var(--ink);appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%2394a3b8'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;background-size:18px;cursor:pointer}
.filter-sel:focus{outline:none;border-color:var(--g);box-shadow:0 0 0 3px rgba(21,128,61,.1)}

/* SUMMARY CARDS */
.sum-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
@media(max-width:480px){.sum-row{grid-template-columns:1fr 1fr}.sum-row .sc:nth-child(3){grid-column:span 2}}
.sc{background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:14px 16px}
.sc-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--ink3);margin-bottom:5px}
.sc-val{font-size:19px;font-weight:800;font-family:var(--mono)}
.sc-val.green{color:var(--g)}.sc-val.red{color:var(--red)}.sc-val.blue{color:#2563eb}
.prog{height:4px;background:var(--border);border-radius:99px;overflow:hidden;margin-top:8px}
.prog-fill{height:100%;border-radius:99px;background:var(--g);transition:width 1s ease}

/* TOOLS */
.tools-row{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.search-wrap{flex:1;min-width:180px;position:relative}
.search-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none}
.search-inp{width:100%;padding:9px 12px 9px 34px;background:#fff;border:1.5px solid var(--border);border-radius:var(--rs);font-family:var(--sans);font-size:13px;color:var(--ink)}
.search-inp:focus{outline:none;border-color:var(--g);box-shadow:0 0 0 3px rgba(21,128,61,.1)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:9px 14px;border-radius:var(--rs);font-family:var(--sans);font-size:12px;font-weight:700;cursor:pointer;transition:.15s;border:1px solid var(--border);text-decoration:none;white-space:nowrap;background:#fff;color:var(--ink2)}
.btn:hover{background:var(--bg);color:var(--ink)}
.btn-green{background:var(--g)!important;color:#fff!important;border-color:var(--g)!important}
.btn-green:hover{background:var(--g2)!important}

/* TABLE */
.tbl-wrap{background:#fff;border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{padding:10px 14px;text-align:left;font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--ink2);border-bottom:1px solid var(--border);background:#f8fafc;white-space:nowrap}
tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#f8fafc}
tbody td{padding:11px 14px;font-size:13px;vertical-align:middle}
tfoot td{padding:10px 14px;font-weight:700;font-size:12px;background:var(--gb);border-top:2px solid var(--gb2);color:var(--g)}

/* BADGES */
.badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700;font-family:var(--mono)}
.b-paid{background:var(--gb);color:var(--g);border:1px solid var(--gb2)}
.b-partial{background:var(--ab);color:var(--amber);border:1px solid #fcd34d}
.b-unpaid{background:var(--rb);color:var(--red);border:1px solid #fca5a5}
.b-cash{background:#f1f5f9;color:#334155;border:1px solid var(--border)}
.b-mpesa{background:var(--gb);color:var(--gd);border:1px solid var(--gb2)}

/* ACTION BUTTONS */
.pay-btn{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;background:var(--g);color:#fff;border:none;border-radius:var(--rs);font-size:12px;font-weight:700;cursor:pointer;font-family:var(--sans);transition:.15s}
.pay-btn:hover{background:var(--g2)}
.hist-btn{display:inline-flex;align-items:center;padding:6px 10px;background:#fff;border:1px solid var(--border);border-radius:var(--rs);font-size:12px;font-weight:600;cursor:pointer;font-family:var(--sans);color:var(--ink2);text-decoration:none;transition:.15s}
.hist-btn:hover{background:var(--bg)}
.paid-tag{font-size:12px;font-weight:700;color:var(--g)}
.del-btn{padding:5px 9px;background:var(--rb);border:1px solid #fca5a5;border-radius:var(--rs);color:var(--red);font-size:11px;font-weight:700;cursor:pointer;font-family:var(--sans)}
.del-btn:hover{background:var(--red);color:#fff}

/* MINI PROGRESS */
.mp{height:3px;background:var(--border);border-radius:99px;overflow:hidden;margin-top:3px}
.mp-fill{height:100%;border-radius:99px}

/* EMPTY */
.empty{text-align:center;padding:44px 20px}
.empty-icon{font-size:44px;opacity:.3;margin-bottom:12px}
.empty-msg{font-weight:700;font-size:15px;margin-bottom:5px}
.empty-sub{font-size:13px;color:var(--ink2)}

/* ═══ MODAL ═══════════════════════════════════════════════════ */
.overlay{
  position:fixed;top:0;left:0;width:100%;height:100%;
  background:rgba(15,23,42,.5);
  z-index:1000;
  display:none;
  align-items:flex-end;
  justify-content:center;
  padding:0;
}
.overlay.open{ display:flex; }
@media(min-width:600px){
  .overlay{align-items:center;padding:20px}
  .modal{border-radius:16px!important;max-height:88vh}
}
.modal{
  background:#fff;width:100%;max-width:440px;
  max-height:92vh;border-radius:16px 16px 0 0;
  overflow:hidden;display:flex;flex-direction:column;
}
.m-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.m-title{font-weight:800;font-size:15px}
.m-close{width:30px;height:30px;border-radius:var(--rs);background:var(--bg);border:1px solid var(--border);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;line-height:1;flex-shrink:0;font-family:var(--sans)}
.m-close:hover{background:#e2e8f0}
.m-body{flex:1;overflow-y:auto;padding:18px}

/* pay modal internals */
.stu-card{display:flex;align-items:center;gap:12px;background:var(--gb);border:1px solid var(--gb2);border-radius:var(--r);padding:12px 14px;margin-bottom:14px}
.stu-av{width:40px;height:40px;border-radius:10px;background:var(--g);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:800;flex-shrink:0}
.stu-name{font-weight:700;font-size:14px}
.stu-adm{font-size:11px;color:var(--ink2);font-family:var(--mono);margin-top:2px}

.bal-row{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.bal-box{background:#f8fafc;border:1px solid var(--border);border-radius:var(--rs);padding:10px 12px;text-align:center}
.bal-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.bal-v{font-family:var(--mono);font-size:12px;font-weight:700;margin-top:3px}

.form-group{margin-bottom:13px}
.form-label{display:block;font-size:11px;font-weight:700;color:var(--ink2);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
.form-inp{width:100%;padding:10px 13px;background:#fff;border:1.5px solid var(--border);border-radius:var(--rs);font-family:var(--sans);font-size:14px;color:var(--ink);transition:.15s}
.form-inp:focus{outline:none;border-color:var(--g);box-shadow:0 0 0 3px rgba(21,128,61,.12)}
.form-inp::placeholder{color:var(--ink3)}

.method-toggle{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.mth-btn{padding:14px 10px;border:2px solid var(--border);border-radius:var(--r);background:#fff;font-family:var(--sans);font-size:13px;font-weight:700;cursor:pointer;transition:.2s;text-align:center;display:flex;flex-direction:column;align-items:center;gap:5px;color:var(--ink2)}
.mth-btn .mth-icon{font-size:26px;line-height:1}
.mth-btn:hover,.mth-btn.sel{border-color:var(--g);background:var(--gb);color:var(--g)}

.mpesa-tip{background:var(--gb);border:1px solid var(--gb2);border-radius:var(--rs);padding:11px 13px;margin-bottom:13px;font-size:12px;color:var(--gd);display:none}
.mpesa-tip strong{font-family:var(--mono);font-size:13px}

.quick-btns{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.qb{padding:6px 12px;background:#fff;border:1.5px solid var(--border);border-radius:99px;font-size:12px;font-weight:600;color:var(--ink2);cursor:pointer;transition:.15s}
.qb:hover,.qb.qb-full{background:var(--gb);border-color:var(--g);color:var(--g)}

.code-note{font-size:12px;margin-top:5px;padding:5px 9px;border-radius:var(--rs);display:none}
.code-ok{background:var(--gb);color:var(--g)}
.code-err{background:var(--rb);color:var(--red)}

.submit-btn{width:100%;padding:13px;background:var(--g);color:#fff;border:none;border-radius:var(--rs);font-family:var(--sans);font-size:15px;font-weight:800;cursor:pointer;transition:.15s;margin-top:4px}
.submit-btn:hover{background:var(--g2)}

/* history */
.hist-item{padding:11px 0;border-bottom:1px solid #f1f5f9;display:flex;align-items:flex-start;gap:10px}
.hist-item:last-child{border-bottom:none}
.hist-amt{font-weight:800;font-size:14px;color:var(--g);font-family:var(--mono)}
.hist-meta{font-size:11px;color:var(--ink2);margin-top:2px;line-height:1.7}
.no-hist{text-align:center;padding:32px;color:var(--ink3);font-size:13px}

/* receipt modal */
.rcp-amount-box{background:var(--gb);border:1px solid var(--gb2);border-radius:var(--r);padding:16px;text-align:center;margin-bottom:14px}
.rcp-big{font-size:30px;font-weight:800;color:var(--g);font-family:var(--mono)}
.rcp-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
.rcp-row:last-child{border:none}
.rcp-k{color:var(--ink2)}.rcp-v{font-weight:700;font-family:var(--mono)}

@media(max-width:560px){
  table thead th:nth-child(3),table tbody td:nth-child(3){display:none}
}
</style>
</head>
<body>

<header class="bar">
  <div class="bar-brand">
    <div class="bar-icon">🎓</div>
    <div>
      <div class="bar-title"><?= SCHOOL_NAME ?></div>
      <div class="bar-sub">Fee Management</div>
    </div>
  </div>
  <a href="../multitask_school_system/dashboards/admin.php" class="bar-back">← Back</a>
</header>

<div class="page">

<?php if ($flash): ?>
<div class="flash <?= $flash_type ?>"><?= $flash_type==='ok'?'✅':'❌' ?> <?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- ══ TERM SUMMARY BANNER ══════════════════════════════════ -->
<?php if ($current_term_name): ?>

<!-- PIN GATE -->
<div id="pinGate" style="background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:18px 20px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <div style="flex:1;min-width:160px">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink2);margin-bottom:4px">School-Wide Statistics</div>
    <div style="font-size:13px;color:var(--ink3)">Enter your access code to view</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <input type="password" id="pinInput" maxlength="8" placeholder="••••••••"
      style="padding:10px 13px;border:1.5px solid var(--border);border-radius:var(--rs);font-family:var(--mono);font-size:15px;letter-spacing:3px;width:130px;color:var(--ink);outline:none"
      oninput="tryPin(this)">
    <div id="pinErr" style="font-size:12px;font-weight:700;color:var(--red);display:none">Wrong code</div>
  </div>
</div>

<div id="statsBanner" style="display:none">
<div class="term-banner">
  <div class="tb-label">School-Wide Overview</div>
  <div class="tb-term"><?= htmlspecialchars($current_term_name) ?></div>
  <div class="tb-grid">
    <div class="tbc">
      <div class="tbc-lbl">Total Received</div>
      <div class="tbc-val green">KES <?= number_format($term_total_received) ?></div>
    </div>
    <div class="tbc">
      <div class="tbc-lbl">Pending</div>
      <div class="tbc-val red">KES <?= number_format($term_pending) ?></div>
    </div>
    <div class="tbc">
      <div class="tbc-lbl">Collection Rate</div>
      <div class="tbc-val white"><?= $term_collect_pct ?>%</div>
    </div>
  </div>
  <div class="tb-prog-wrap">
    <div class="tb-prog-label">
      <span>Fees Collected</span>
      <span><?= $term_collect_pct ?>% of KES <?= number_format($term_total_expected) ?> expected</span>
    </div>
    <div class="tb-prog">
      <div class="tb-prog-fill" id="tbPf" style="width:0%"></div>
    </div>
  </div>
</div>
</div><!-- /statsBanner -->
<?php endif; ?>

<!-- TABS -->
<div class="tabs">
  <a href="?view=pay&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" class="tab <?= $view==='pay'?'active':'' ?>">💳 Record Payment</a>
  <a href="?view=report&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" class="tab <?= $view==='report'?'active':'' ?>">📊 Reports</a>
</div>

<!-- FILTERS -->
<div class="filters">
  <div>
    <div class="filter-label">Class</div>
    <select class="filter-sel" id="fClass" onchange="applyFilter()">
      <?php foreach ($classes as $c): ?>
      <option value="<?= $c['id'] ?>" <?= $c['id']==$sel_class?'selected':'' ?>><?= htmlspecialchars($c['class_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <div class="filter-label">Term</div>
    <select class="filter-sel" id="fTerm" onchange="applyFilter()">
      <?php foreach ($all_terms as $t): ?>
      <option value="<?= $t['id'] ?>" <?= $t['id']==$sel_term?'selected':'' ?>><?= htmlspecialchars($t['term_name']) ?><?= $t['is_active']?' ✦':'' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<?php if ($view === 'pay'): ?>

<!-- CLASS SUMMARY -->
<div class="sum-row">
  <div class="sc"><div class="sc-lbl">Collected</div><div class="sc-val green">KES <?= number_format($total_collected) ?></div><div class="prog"><div class="prog-fill" id="pf" style="width:0%"></div></div></div>
  <div class="sc"><div class="sc-lbl">Outstanding</div><div class="sc-val red">KES <?= number_format($outstanding) ?></div></div>
  <div class="sc"><div class="sc-lbl">Rate</div><div class="sc-val blue"><?= $collection_pct ?>%</div></div>
</div>

<!-- TOOLS -->
<div class="tools-row">
  <div class="search-wrap">
    <span class="search-icon">🔍</span>
    <input type="text" class="search-inp" id="sSearch" placeholder="Search student…" oninput="doSearch()">
  </div>
  <a href="?view=pay&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>&print_list=1" target="_blank" class="btn">🖨 Print</a>
  <a href="?view=pay&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>&export_csv=1" class="btn">⬇ CSV</a>
</div>

<?php if (empty($students)): ?>
<div class="empty"><div class="empty-icon">🏫</div><div class="empty-msg">No students found</div><div class="empty-sub">Select a class and term above</div></div>
<?php else: ?>
<div class="tbl-wrap">
  <table id="stuTbl">
    <thead><tr><th>Student</th><th>Balance</th><th>Progress</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php
      $tf=$tp=$tb=0;
      foreach ($students as $s):
        $b    = get_balance($conn,$s['user_id'],$sel_class,$sel_term,$all_terms);
        $stat = $b['balance']<=0 ? 'paid' : ($b['paid']>0 ? 'partial' : 'unpaid');
        $pc   = $b['pct'];
        $pclr = $stat==='paid'?'#15803d':($stat==='partial'?'#d97706':'#dc2626');
        $tf  += $b['total_due']; $tp += $b['paid']; $tb += $b['balance'];
    ?>
    <tr data-n="<?= htmlspecialchars(strtolower($s['name']), ENT_QUOTES) ?>"
        data-a="<?= htmlspecialchars(strtolower($s['admission_number']), ENT_QUOTES) ?>">
      <td>
        <div style="font-weight:700"><?= htmlspecialchars($s['name']) ?></div>
        <div style="font-size:11px;color:var(--ink2);font-family:var(--mono)"><?= htmlspecialchars($s['admission_number']) ?></div>
        <?php if ($b['arrears']>0): ?><div style="font-size:10px;color:var(--amber)">+KES <?= number_format($b['arrears']) ?> arrears</div><?php endif; ?>
      </td>
      <td style="font-family:var(--mono);font-weight:700;color:<?= $b['balance']>0?'var(--red)':'var(--g)' ?>">
        <?= $b['balance']>0 ? 'KES '.number_format($b['balance']) : '✓ Clear' ?>
      </td>
      <td>
        <div style="font-size:10px;color:var(--ink3);margin-bottom:3px"><?= $pc ?>%</div>
        <div class="mp" style="width:80px"><div class="mp-fill" style="width:<?= $pc ?>%;background:<?= $pclr ?>"></div></div>
      </td>
      <td><span class="badge b-<?= $stat ?>"><?= strtoupper($stat) ?></span></td>
      <td>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
          <?php if ($b['balance']>0): ?>
          <!-- ✅ FIX: data attributes instead of inline JS arguments — no quote-breaking -->
          <button class="pay-btn"
            data-uid="<?= (int)$s['user_id'] ?>"
            data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>"
            data-adm="<?= htmlspecialchars($s['admission_number'], ENT_QUOTES) ?>"
            data-fee="<?= (float)$b['term_fee'] ?>"
            data-arr="<?= (float)$b['arrears'] ?>"
            data-tot="<?= (float)$b['total_due'] ?>"
            data-paid="<?= (float)$b['paid'] ?>"
            data-bal="<?= (float)$b['balance'] ?>"
            onclick="openPayFromBtn(this)">💳 Pay</button>
          <?php else: ?>
          <span class="paid-tag">✓ Paid</span>
          <?php endif; ?>
          <!-- ✅ FIX: data attributes for history button too -->
          <button class="hist-btn"
            data-uid="<?= (int)$s['user_id'] ?>"
            data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>"
            onclick="openHistFromBtn(this)">History</button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td><?= count($students) ?> students</td>
      <td>KES <?= number_format($tb) ?> outstanding</td>
      <td>KES <?= number_format($tp) ?> paid</td>
      <td colspan="2"><?= $collection_pct ?>% collected</td>
    </tr></tfoot>
  </table>
</div>
<?php endif; ?>

<?php else: /* REPORT */ ?>

<?php
  $r_total = array_sum(array_column($report_payments,'amount_paid'));
  $r_cash  = array_sum(array_map(fn($p)=>$p['pay_method']==='Cash'?$p['amount_paid']:0,$report_payments));
  $r_mpesa = array_sum(array_map(fn($p)=>$p['pay_method']==='M-Pesa'?$p['amount_paid']:0,$report_payments));
?>
<div class="sum-row">
  <div class="sc"><div class="sc-lbl">Total Collected</div><div class="sc-val green">KES <?= number_format($r_total) ?></div></div>
  <div class="sc"><div class="sc-lbl">💵 Cash</div><div class="sc-val" style="color:#334155">KES <?= number_format($r_cash) ?></div></div>
  <div class="sc"><div class="sc-lbl">📱 M-Pesa</div><div class="sc-val green">KES <?= number_format($r_mpesa) ?></div></div>
</div>

<div class="tools-row">
  <div class="search-wrap">
    <span class="search-icon">🔍</span>
    <input type="text" class="search-inp" id="rSearch" placeholder="Search name, receipt, M-Pesa code…" oninput="doRSearch()">
  </div>
  <a href="?view=report&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>&export_csv=1" class="btn">⬇ Export CSV</a>
  <a href="?print_today=1&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" target="_blank" class="btn btn-green">🧾 Today's Receipts</a>
</div>
</div>

<?php if (empty($report_payments)): ?>
<div class="empty"><div class="empty-icon">📭</div><div class="empty-msg">No payments recorded</div><div class="empty-sub">No payments found for the selected filters</div></div>
<?php else: ?>
<div class="tbl-wrap">
  <table id="rptTbl">
    <thead><tr><th>Date</th><th>Student</th><th>Amount</th><th>Method</th><th>Receipt</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($report_payments as $p): $mp=$p['pay_method']==='M-Pesa'; ?>
    <tr data-search="<?= htmlspecialchars(strtolower($p['name'].' '.$p['receipt_no'].' '.$p['pay_ref']), ENT_QUOTES) ?>">
      <td style="font-size:12px;color:var(--ink2);white-space:nowrap">
        <?= date('d M Y',strtotime($p['payment_date'])) ?><br>
        <span style="font-size:10px"><?= date('g:i A',strtotime($p['payment_date'])) ?></span>
      </td>
      <td>
        <div style="font-weight:700"><?= htmlspecialchars($p['name']) ?></div>
        <div style="font-size:11px;color:var(--ink2);font-family:var(--mono)"><?= htmlspecialchars($p['admission_number']) ?></div>
      </td>
      <td style="font-family:var(--mono);font-weight:700;color:var(--g)">KES <?= number_format($p['amount_paid']) ?></td>
      <td>
        <span class="badge <?= $mp?'b-mpesa':'b-cash' ?>"><?= htmlspecialchars($p['pay_method']) ?></span>
        <?php if ($p['pay_ref']): ?><div style="font-size:10px;font-family:var(--mono);color:var(--ink2);margin-top:2px"><?= htmlspecialchars($p['pay_ref']) ?></div><?php endif; ?>
      </td>
      <td style="font-family:var(--mono);font-size:11px;color:var(--ink2)"><?= htmlspecialchars($p['receipt_no']) ?></td>
      <td>
        <div style="display:flex;gap:5px;align-items:center">
          <a href="?print_receipt=1&pid=<?= $p['id'] ?>&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" target="_blank" class="hist-btn" style="font-size:11px">🖨</a>
          <form method="POST" action="?view=report&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" onsubmit="return confirm('Delete this payment?')" style="display:inline">
            <input type="hidden" name="del_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="class_id" value="<?= $sel_class ?>">
            <input type="hidden" name="term_id" value="<?= $sel_term ?>">
            <button type="submit" class="del-btn">✕</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td colspan="2"><?= count($report_payments) ?> transactions</td>
      <td>KES <?= number_format($r_total) ?></td>
      <td colspan="3">Cash: KES <?= number_format($r_cash) ?> &nbsp;|&nbsp; M-Pesa: KES <?= number_format($r_mpesa) ?></td>
    </tr></tfoot>
  </table>
</div>
<?php endif; ?>
<?php endif; /* view */ ?>

</div><!-- /.page -->

<script>
/* ── PIN GATE ── code lives only in HTML, never hits the server */
(function(){
  var PIN = '24509996';
  var SK  = 'nymix_stats_unlocked';

  var lockTimer = null;

  function lock(){
    sessionStorage.removeItem(SK);
    document.getElementById('statsBanner').style.display = 'none';
    document.getElementById('pinGate').style.display     = 'flex';
    var inp = document.getElementById('pinInput');
    inp.value = '';
    inp.style.borderColor = 'var(--border)';
    document.getElementById('pinErr').style.display = 'none';
    if(lockTimer) clearTimeout(lockTimer);
  }

  function unlock(){
    document.getElementById('pinGate').style.display    = 'none';
    document.getElementById('statsBanner').style.display = 'block';
    var tbPf = document.getElementById('tbPf');
    if(tbPf) setTimeout(function(){ tbPf.style.width = '<?= $term_collect_pct ?>%'; }, 200);
    if(lockTimer) clearTimeout(lockTimer);
    lockTimer = setTimeout(lock, 60000);
  }

  if(sessionStorage.getItem(SK) === '1'){ unlock(); return; }

  window.tryPin = function(inp){
    var v = inp.value;
    if(v.length < PIN.length) return;
    if(v === PIN){
      sessionStorage.setItem(SK,'1');
      document.getElementById('pinErr').style.display = 'none';
      inp.style.borderColor = 'var(--g)';
      unlock();
    } else {
      document.getElementById('pinErr').style.display = 'block';
      inp.style.borderColor = 'var(--red)';
      setTimeout(function(){
        inp.value = '';
        inp.style.borderColor = 'var(--border)';
        document.getElementById('pinErr').style.display = 'none';
      }, 1200);
    }
  };
})();
</script>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- PAY MODAL                                                  -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="overlay" id="payOverlay">
  <div class="modal">
    <div class="m-head">
      <div class="m-title">Record Payment</div>
      <button class="m-close" type="button" onclick="closeModal('payOverlay')">✕</button>
    </div>
    <div class="m-body">
      <div class="stu-card">
        <div class="stu-av" id="pAv">?</div>
        <div><div class="stu-name" id="pName">—</div><div class="stu-adm" id="pAdm">—</div></div>
      </div>
      <div class="bal-row">
        <div class="bal-box"><div class="bal-lbl">Term Fee</div><div class="bal-v" id="bFee">—</div></div>
        <div class="bal-box"><div class="bal-lbl">Arrears</div><div class="bal-v" id="bArr" style="color:var(--amber)">—</div></div>
        <div class="bal-box"><div class="bal-lbl">Balance</div><div class="bal-v" id="bBal" style="color:var(--red)">—</div></div>
      </div>
      <form method="POST" id="payForm" action="?view=pay&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" onsubmit="return validatePay()">
        <input type="hidden" name="do_pay" value="1">
        <input type="hidden" name="student_id" id="fStu" value="">
        <input type="hidden" name="class_id" value="<?= $sel_class ?>">
        <input type="hidden" name="term_id"  value="<?= $sel_term ?>">
        <input type="hidden" name="method"   id="fMeth" value="Cash">

        <div class="form-group">
          <label class="form-label">How are they paying?</label>
          <div class="method-toggle">
            <button type="button" class="mth-btn sel" id="btnCash" onclick="setMeth('Cash')">
              <span class="mth-icon">💵</span>Cash
            </button>
            <button type="button" class="mth-btn" id="btnMpesa" onclick="setMeth('M-Pesa')">
              <span class="mth-icon">📱</span>M-Pesa
            </button>
          </div>
        </div>

        <div class="mpesa-tip" id="mpesaTip">
          Send to Till <strong><?= MPESA_TILL ?></strong> (Lipa na M-Pesa → Buy Goods), then enter the confirmation code below.
        </div>

        <div class="form-group">
          <label class="form-label">Amount (KES)</label>
          <div class="quick-btns">
            <span class="qb" onclick="qSet(500)">500</span>
            <span class="qb" onclick="qSet(1000)">1,000</span>
            <span class="qb" onclick="qSet(2000)">2,000</span>
            <span class="qb" onclick="qSet(3000)">3,000</span>
            <span class="qb" onclick="qSet(5000)">5,000</span>
            <span class="qb qb-full" id="qFull" onclick="qFull()" data-bal="0">Full Balance</span>
          </div>
          <input type="number" name="amount" id="fAmt" class="form-inp" placeholder="Enter amount" min="1" style="margin-top:8px" required>
        </div>

        <div class="form-group" id="refWrap" style="display:none">
          <label class="form-label">M-Pesa Code</label>
          <input type="text" name="ref" id="fRef" class="form-inp" placeholder="e.g. QHG8X12345" oninput="checkCode(this)">
          <div class="code-note" id="cNote"></div>
        </div>

        <button type="submit" class="submit-btn">✓ Record Payment</button>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- HISTORY MODAL                                              -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="overlay" id="histOverlay">
  <div class="modal">
    <div class="m-head">
      <div class="m-title" id="histTitle">Payment History</div>
      <button class="m-close" type="button" onclick="closeModal('histOverlay')">✕</button>
    </div>
    <div class="m-body" id="histBody"><div class="no-hist">Loading…</div></div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- RECEIPT MODAL                                              -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if ($new_receipt): ?>
<div class="overlay" id="rcpOverlay">
  <div class="modal">
    <div class="m-head">
      <div class="m-title">🧾 Receipt</div>
      <button class="m-close" type="button" onclick="closeModal('rcpOverlay')">✕</button>
    </div>
    <div class="m-body">
      <div class="rcp-amount-box">
        <div style="font-size:11px;color:var(--g);font-weight:700;text-transform:uppercase;letter-spacing:1px">Amount Received</div>
        <div class="rcp-big">KES <?= number_format($new_receipt['amount']) ?></div>
        <div style="font-size:12px;color:var(--ink2);margin-top:4px"><?= htmlspecialchars($new_receipt['method']) ?> · <?= $new_receipt['date'] ?></div>
      </div>
      <?php foreach ([
        'Receipt No.' => $new_receipt['no'],
        'Student'     => $new_receipt['name'],
        'Adm. No.'    => $new_receipt['adm'],
        'Class & Term'=> $new_receipt['class'].' · '.$new_receipt['term'],
        'Total Due'   => 'KES '.number_format($new_receipt['total']),
        'Balance'     => $new_receipt['balance']>0 ? 'KES '.number_format($new_receipt['balance']) : '✓ Fully Paid',
        'Status'      => ucfirst($new_receipt['status']),
      ] as $k=>$v): ?>
      <div class="rcp-row"><span class="rcp-k"><?= $k ?></span><span class="rcp-v"><?= htmlspecialchars($v) ?></span></div>
      <?php endforeach; ?>
      <?php if ($new_receipt['ref']): ?>
      <div class="rcp-row"><span class="rcp-k">M-Pesa Code</span><span class="rcp-v" style="color:var(--g)"><?= htmlspecialchars($new_receipt['ref']) ?></span></div>
      <?php endif; ?>
      <div style="display:flex;gap:8px;margin-top:16px">
        <a href="?print_receipt=1&pid=<?= $new_receipt['pid'] ?>&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" target="_blank" class="btn btn-green" style="flex:1;justify-content:center">🖨 Print Receipt</a>
        <?php if ($new_receipt['phone']): ?>
        <button type="button" class="btn" onclick="sendSms()" style="flex:1;justify-content:center">💬 SMS Parent</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
document.getElementById('rcpOverlay').style.display = 'flex';
<?php if ($new_receipt['phone']): ?>
function sendSms(){
  var p='<?= preg_replace('/\D/','',$new_receipt['phone']) ?>';
  if(p.length===9)p='0'+p;
  if(p.length===12&&p.substring(0,3)==='254')p='0'+p.substring(3);
  var txt=<?= json_encode(SCHOOL_NAME." - FEE RECEIPT\nReceipt: ".$new_receipt['no']."\nStudent: ".$new_receipt['name']." (".$new_receipt['adm'].")\nPaid: KES ".number_format($new_receipt['amount'])." via ".$new_receipt['method']."\nBalance: ".($new_receipt['balance']>0?'KES '.number_format($new_receipt['balance']):'CLEARED')."\nDate: ".$new_receipt['date']) ?>;
  var ios=/iphone|ipad|ipod/i.test(navigator.userAgent);
  window.location.href='sms:'+p+(ios?'&':'?')+'body='+encodeURIComponent(txt);
}
<?php endif; ?>
</script>
<?php endif; ?>

<script>
/* FILTER */
function applyFilter(){
  var c=document.getElementById('fClass').value;
  var t=document.getElementById('fTerm').value;
  window.location.href='?view=<?= $view ?>&class_id='+c+'&term_id='+t;
}

/* MODALS */
function closeModal(id){ document.getElementById(id).style.display='none'; }
function openModal(id){  document.getElementById(id).style.display='flex'; }

document.addEventListener('click',function(e){
  if(e.target.classList.contains('overlay')) e.target.style.display='none';
});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape') document.querySelectorAll('.overlay').forEach(function(o){ o.style.display='none'; });
});

/* ✅ KEY FIX: read from data attributes — no inline quote issues */
function openPayFromBtn(btn){
  var d=btn.dataset;
  openPay(d.uid, d.name, d.adm, d.fee, d.arr, d.tot, d.paid, d.bal);
}
function openHistFromBtn(btn){
  openHist(btn.dataset.uid, btn.dataset.name);
}

/* PAY MODAL */
function openPay(id,name,adm,fee,arr,tot,paid,bal){
  document.getElementById('fStu').value         = id;
  document.getElementById('pName').textContent  = name;
  document.getElementById('pAdm').textContent   = 'Adm: '+adm;
  document.getElementById('pAv').textContent    = (name||'?').charAt(0).toUpperCase();
  document.getElementById('bFee').textContent   = 'KES '+Number(fee).toLocaleString();
  document.getElementById('bArr').textContent   = Number(arr)>0 ? 'KES '+Number(arr).toLocaleString() : '—';
  document.getElementById('bBal').textContent   = 'KES '+Number(bal).toLocaleString();
  document.getElementById('qFull').dataset.bal  = bal;
  document.getElementById('fAmt').value         = '';
  document.getElementById('fRef').value         = '';
  document.getElementById('cNote').style.display='none';
  setMeth('Cash');
  openModal('payOverlay');
  setTimeout(function(){ document.getElementById('fAmt').focus(); }, 200);
}

function setMeth(m){
  document.getElementById('fMeth').value=m;
  document.getElementById('btnCash').className  ='mth-btn'+(m==='Cash'?' sel':'');
  document.getElementById('btnMpesa').className ='mth-btn'+(m==='M-Pesa'?' sel':'');
  document.getElementById('mpesaTip').style.display = m==='M-Pesa'?'block':'none';
  document.getElementById('refWrap').style.display  = m==='M-Pesa'?'block':'none';
}

function qSet(v){ document.getElementById('fAmt').value=v; }
function qFull(){
  var b=Number(document.getElementById('qFull').dataset.bal);
  if(b>0) document.getElementById('fAmt').value=Math.ceil(b);
}

var _ct;
function checkCode(inp){
  inp.value=inp.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
  clearTimeout(_ct);
  var n=document.getElementById('cNote');
  if(!inp.value){ n.style.display='none'; return; }
  _ct=setTimeout(function(){
    if(/^[A-Z0-9]{8,14}$/.test(inp.value)){
      n.className='code-note code-ok'; n.textContent='✓ Valid format'; n.style.display='block';
    } else {
      n.className='code-note code-err'; n.textContent='Format: 8–14 letters/numbers (e.g. QHG8X12345)'; n.style.display='block';
    }
  },300);
}

function validatePay(){
  var m  =document.getElementById('fMeth').value;
  var amt=parseFloat(document.getElementById('fAmt').value);
  var ref=document.getElementById('fRef').value.trim();
  if(!amt||amt<=0){ alert('Please enter the amount.'); return false; }
  if(m==='M-Pesa'){
    if(!ref){ alert('Please enter the M-Pesa confirmation code.'); document.getElementById('fRef').focus(); return false; }
    if(!/^[A-Z0-9]{8,14}$/.test(ref)){ alert('Invalid M-Pesa code. Example: QHG8X12345'); document.getElementById('fRef').focus(); return false; }
  }
  return true;
}

/* HISTORY */
function openHist(sid,name){
  document.getElementById('histTitle').textContent = name+' — Payments';
  document.getElementById('histBody').innerHTML    = '<div class="no-hist">Loading…</div>';
  openModal('histOverlay');
  fetch('?hist=1&sid='+sid)
    .then(function(r){ return r.json(); })
    .then(function(rows){
      if(!rows.length){ document.getElementById('histBody').innerHTML='<div class="no-hist">No payments yet.</div>'; return; }
      var html='';
      rows.forEach(function(p){
        html+='<div class="hist-item">'+
          '<div style="flex:1">'+
          '<div class="hist-amt">KES '+Number(p.amount_paid).toLocaleString()+'</div>'+
          '<div class="hist-meta">'+p.term_name+' · '+p.pay_method+
          (p.pay_ref?' · <span style="font-family:var(--mono);color:var(--g)">'+p.pay_ref+'</span>':'')+
          '<br>'+p.payment_date.substring(0,16)+
          (p.receipt_no?' · <span style="font-family:var(--mono);font-size:10px;color:var(--ink3)">'+p.receipt_no+'</span>':'')+
          ' <a href="?print_receipt=1&pid='+p.id+'&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" target="_blank" style="font-size:11px;font-weight:700;color:var(--g);text-decoration:none">🖨</a>'+
          '</div></div>'+
          '<form method="POST" action="?view=pay&class_id=<?= $sel_class ?>&term_id=<?= $sel_term ?>" onsubmit="return confirm(\'Delete this payment?\')">'+
          '<input type="hidden" name="del_id" value="'+p.id+'">'+
          '<input type="hidden" name="class_id" value="<?= $sel_class ?>">'+
          '<input type="hidden" name="term_id" value="<?= $sel_term ?>">'+
          '<button type="submit" class="del-btn">✕</button></form>'+
          '</div>';
      });
      document.getElementById('histBody').innerHTML=html;
    })
    .catch(function(){ document.getElementById('histBody').innerHTML='<div class="no-hist">Error loading.</div>'; });
}

/* SEARCH */
function doSearch(){
  var q=(document.getElementById('sSearch').value||'').toLowerCase();
  document.querySelectorAll('#stuTbl tbody tr').forEach(function(tr){
    tr.style.display=(!q||tr.dataset.n.includes(q)||tr.dataset.a.includes(q))?'':'none';
  });
}
function doRSearch(){
  var q=(document.getElementById('rSearch').value||'').toLowerCase();
  document.querySelectorAll('#rptTbl tbody tr').forEach(function(tr){
    tr.style.display=(!q||tr.dataset.search.includes(q))?'':'none';
  });
}

/* PROGRESS BARS */
window.addEventListener('DOMContentLoaded',function(){
  var pf=document.getElementById('pf');
  if(pf) setTimeout(function(){ pf.style.width='<?= $collection_pct ?>%'; },400);
  var tbPf=document.getElementById('tbPf');
  if(tbPf) setTimeout(function(){ tbPf.style.width='<?= $term_collect_pct ?>%'; },600);
});
</script>
</body>
</html>