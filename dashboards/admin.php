<?php
    // 🔥 DEBUG MODE — SHOW ALL ERRORS
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("../config/db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user = $conn->query("SELECT name, profile_pic FROM users WHERE id=$user_id")->fetch_assoc();
$username = $user['name'] ?? 'Admin';
$initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($username)))));
$avatar   = !empty($user['profile_pic']) ? "../uploads/" . basename($user['profile_pic']) : null;
$firstname = htmlspecialchars(explode(' ', $username)[0]);

/* ─── TERM FILTER ─────────────────────────────── */
$selected_term = isset($_GET['term']) ? (int)$_GET['term'] : 0;
$term_condition = $selected_term > 0 ? "AND fp.term_id = $selected_term" : "";;

/* ─── STATS ─────────────────────────────────────── */
$total_paid = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount_paid),0) t FROM fees_payments fp WHERE 1=1 $term_condition"))['t'];

$total_expected = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(c.termly_fees),0) e FROM students s LEFT JOIN classes c ON s.class_id=c.id"))['e'];

if ($selected_term > 0) {
    $total_expected = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(c.termly_fees),0) e FROM students s
         LEFT JOIN classes c ON s.class_id=c.id
         WHERE 1=1"))['e'];
}

$pending = max(0, $total_expected - $total_paid);
$pct     = $total_expected > 0 ? round(($total_paid / $total_expected) * 100) : 0;

$total_students = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) n FROM students"))['n'] ?? 0;
$total_classes  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) n FROM classes"))['n']  ?? 0;

/* ─── CHART DATA ─────────────────────────────────── */


$q = $conn->query("
    SELECT c.class_name,
           COALESCE(SUM(fp.amount_paid),0) paid,
           COALESCE(c.termly_fees * COUNT(DISTINCT s.user_id),0) expected
    FROM classes c
    LEFT JOIN students s ON s.class_id = c.id
    LEFT JOIN fees_payments fp 
        ON fp.student_id = s.user_id $term_condition
    GROUP BY c.id
    ORDER BY c.class_name
");
while ($r = $q->fetch_assoc()) {
    $chartLabels[] = $r['class_name'];
    $chartPaid[]   = (float)$r['paid'];
    $chartExp[]    = (float)$r['expected'];
}

/* ─── RECENT PAYMENTS ─────────────────────────────── */
$rq = $conn->query("
    SELECT u.name, c.class_name, fp.amount_paid, fp.payment_date, fp.term_id
    FROM fees_payments fp
    JOIN users u ON u.id = fp.student_id
    JOIN students s ON s.user_id = fp.student_id
    JOIN classes c ON c.id = s.class_id
    " . ($selected_term > 0 ? "WHERE fp.term_id = $selected_term" : "") . "
    ORDER BY fp.payment_date DESC
    LIMIT 6
");
if ($rq) while ($r = $rq->fetch_assoc()) $recent[] = $r;

/* ─── TERM STATS ─────────────────────────────────── */
$term_stats = [];
for ($t = 1; $t <= 3; $t++) {
    $ts = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount_paid),0) paid FROM fees_payments WHERE term_id=$t"));
    $term_stats[$t] = (float)$ts['paid'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Little Friends Schools — Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --brand:#6C63FF;
  --brand-2:#A78BFA;
  --brand-light:rgba(108,99,255,.12);
  --brand-glow:rgba(108,99,255,.25);
  --green:#10B981;
  --green-light:rgba(16,185,129,.12);
  --amber:#F59E0B;
  --amber-light:rgba(245,158,11,.12);
  --red:#EF4444;
  --red-light:rgba(239,68,68,.1);
  --violet:#8B5CF6;
  --cyan:#06B6D4;

  --bg:#07080F;
  --bg2:#0D0E1A;
  --surface:#111222;
  --surface2:#181929;
  --surface3:#1F2035;
  --surface4:#262840;
  --border:rgba(255,255,255,.06);
  --border2:rgba(255,255,255,.11);
  --border3:rgba(108,99,255,.3);

  --text:#EEEEF5;
  --text2:#9899B0;
  --text3:#5B5C74;

  --sidebar-w:260px;
  --topbar-h:64px;
  --radius:16px;
  --radius-sm:10px;
  --radius-xs:6px;

  --font:'Plus Jakarta Sans',sans-serif;
  --font-head:'Space Grotesk',sans-serif;
}

html{scroll-behavior:smooth}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--surface4);border-radius:99px}

/* ─── NOISE OVERLAY ─── */
body::before{
  content:'';
  position:fixed;inset:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
  pointer-events:none;
  z-index:0;
  opacity:.4;
}

/* ─── SIDEBAR ─── */
.sidebar{
  position:fixed;top:0;left:0;
  width:var(--sidebar-w);height:100vh;
  background:var(--surface);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  z-index:200;
  transition:transform .3s cubic-bezier(.4,0,.2,1);
}

/* Subtle glow on sidebar top */
.sidebar::before{
  content:'';
  position:absolute;top:-60px;left:50%;
  transform:translateX(-50%);
  width:180px;height:180px;
  background:var(--brand);
  border-radius:50%;
  filter:blur(80px);
  opacity:.07;
  pointer-events:none;
}

.sidebar-logo{
  display:flex;align-items:center;gap:11px;
  padding:20px 20px 18px;
  border-bottom:1px solid var(--border);
}
.logo-mark{
  width:38px;height:38px;
  background:linear-gradient(135deg,var(--brand),var(--brand-2));
  border-radius:11px;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
  font-size:18px;
  box-shadow:0 4px 14px var(--brand-glow);
}
.logo-name{font-family:var(--font-head);font-size:15px;font-weight:700;line-height:1.1}
.logo-sub{font-size:10.5px;color:var(--text3);font-weight:500;letter-spacing:.5px;margin-top:1px}

.nav-wrap{flex:1;overflow-y:auto;padding:12px 10px;display:flex;flex-direction:column;gap:2px}
.nav-section-label{
  font-size:9.5px;font-weight:700;letter-spacing:1.4px;
  text-transform:uppercase;color:var(--text3);
  padding:10px 10px 4px;margin-top:6px;
}
.nav-link{
  display:flex;align-items:center;gap:11px;
  padding:10px 12px;
  border-radius:var(--radius-sm);
  color:var(--text2);
  text-decoration:none;
  font-size:13.5px;
  font-weight:500;
  transition:all .18s;
  position:relative;
  white-space:nowrap;
}
.nav-icon{
  width:32px;height:32px;
  border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:15px;
  flex-shrink:0;
  background:transparent;
  transition:all .18s;
}
.nav-link:hover{background:var(--surface2);color:var(--text)}
.nav-link:hover .nav-icon{background:var(--surface3)}
.nav-link.active{background:var(--brand-light);color:var(--brand-2)}
.nav-link.active .nav-icon{background:rgba(108,99,255,.18)}
.nav-link.active::before{
  content:'';
  position:absolute;left:0;top:50%;transform:translateY(-50%);
  width:3px;height:60%;
  background:var(--brand);
  border-radius:0 2px 2px 0;
}
.nav-badge{
  margin-left:auto;
  font-size:10px;font-weight:700;
  background:var(--brand-light);color:var(--brand-2);
  padding:2px 7px;border-radius:99px;
}

.sidebar-footer{
  padding:12px;
  border-top:1px solid var(--border);
}
.sidebar-user{
  display:flex;align-items:center;gap:10px;
  padding:10px;border-radius:var(--radius-sm);
  cursor:pointer;transition:background .18s;
}
.sidebar-user:hover{background:var(--surface2)}
.avatar{
  width:36px;height:36px;border-radius:50%;
  background:linear-gradient(135deg,var(--brand),var(--brand-2));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-head);font-size:12px;font-weight:700;color:#fff;
  flex-shrink:0;overflow:hidden;
}
.avatar img{width:100%;height:100%;object-fit:cover}
.user-name{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:11px;color:var(--text3);margin-top:1px}
.user-dot{
  width:7px;height:7px;border-radius:50%;
  background:var(--green);
  box-shadow:0 0 0 2px rgba(16,185,129,.2);
  margin-left:auto;flex-shrink:0;
}

/* ─── MAIN ─── */
.main{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column;position:relative;z-index:1}

/* ─── TOPBAR ─── */
.topbar{
  height:var(--topbar-h);
  background:rgba(7,8,15,.8);
  backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 24px;
  position:sticky;top:0;z-index:100;
}
.hamburger{
  display:none;background:none;border:none;
  color:var(--text2);cursor:pointer;
  width:36px;height:36px;
  border-radius:var(--radius-xs);
  font-size:18px;
  align-items:center;justify-content:center;
  transition:all .18s;margin-right:12px;
}
.hamburger:hover{background:var(--surface2);color:var(--text)}
.topbar-left{display:flex;align-items:center}
.page-heading{font-family:var(--font-head);font-size:17px;font-weight:700;letter-spacing:-.3px}
.page-sub{font-size:11.5px;color:var(--text3);margin-top:1px}

.topbar-right{display:flex;align-items:center;gap:10px}
.greeting-pill{
  display:flex;align-items:center;gap:8px;
  background:var(--surface2);
  border:1px solid var(--border);
  border-radius:99px;
  padding:6px 14px;
  font-size:12.5px;color:var(--text2);
}
.greeting-name{font-weight:600;color:var(--text)}
.icon-btn{
  width:36px;height:36px;
  background:var(--surface2);
  border:1px solid var(--border);
  border-radius:var(--radius-xs);
  display:flex;align-items:center;justify-content:center;
  color:var(--text2);font-size:15px;
  text-decoration:none;cursor:pointer;
  transition:all .18s;
}
.icon-btn:hover{background:var(--surface3);color:var(--text);border-color:var(--border2)}
.topbar-avatar{
  width:36px;height:36px;border-radius:50%;
  background:linear-gradient(135deg,var(--brand),var(--brand-2));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-head);font-size:12px;font-weight:700;color:#fff;
  cursor:pointer;overflow:hidden;
  border:2px solid var(--border2);transition:border-color .18s;
}
.topbar-avatar:hover{border-color:var(--brand)}
.topbar-avatar img{width:100%;height:100%;object-fit:cover}

/* ─── CONTENT ─── */
.content{padding:24px;flex:1}

/* ─── TERM FILTER BAR ─── */
.filter-bar{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:22px;
  flex-wrap:wrap;gap:12px;
}
.filter-label{
  font-size:12px;color:var(--text3);font-weight:600;
  letter-spacing:.8px;text-transform:uppercase;
}
.term-tabs{
  display:flex;align-items:center;gap:6px;
  background:var(--surface2);
  border:1px solid var(--border);
  border-radius:99px;
  padding:4px;
}
.term-tab{
  padding:7px 18px;
  border-radius:99px;
  font-size:12.5px;font-weight:600;
  color:var(--text3);
  text-decoration:none;
  transition:all .2s;
  white-space:nowrap;
}
.term-tab:hover{color:var(--text2);background:var(--surface3)}
.term-tab.active{
  background:var(--brand);
  color:#fff;
  box-shadow:0 4px 12px var(--brand-glow);
}

/* ─── STATS GRID ─── */
.stats-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:14px;
  margin-bottom:20px;
}

.stat-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:20px;
  position:relative;
  overflow:hidden;
  cursor:default;
  transition:border-color .22s,transform .22s;
}
.stat-card:hover{border-color:var(--border2);transform:translateY(-3px)}

/* Accent top line */
.stat-card::after{
  content:'';
  position:absolute;top:0;left:0;right:0;
  height:2px;
  border-radius:var(--radius) var(--radius) 0 0;
  opacity:0;
  transition:opacity .22s;
}
.stat-card:hover::after{opacity:1}
.stat-card.green::after{background:var(--green)}
.stat-card.brand::after{background:var(--brand)}
.stat-card.amber::after{background:var(--amber)}
.stat-card.violet::after{background:var(--violet)}

/* Corner glow */
.stat-card .glow{
  position:absolute;top:-40px;right:-40px;
  width:130px;height:130px;
  border-radius:50%;
  opacity:.06;
  pointer-events:none;
}
.stat-card.green .glow{background:var(--green)}
.stat-card.brand .glow{background:var(--brand)}
.stat-card.amber .glow{background:var(--amber)}
.stat-card.violet .glow{background:var(--violet)}

.stat-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.stat-icon-wrap{
  width:40px;height:40px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:17px;flex-shrink:0;
}
.stat-card.green .stat-icon-wrap{background:var(--green-light)}
.stat-card.brand .stat-icon-wrap{background:var(--brand-light)}
.stat-card.amber .stat-icon-wrap{background:var(--amber-light)}
.stat-card.violet .stat-icon-wrap{background:rgba(139,92,246,.12)}

.stat-pill{
  font-size:10.5px;font-weight:700;
  padding:3px 9px;border-radius:99px;
  display:inline-flex;align-items:center;gap:3px;
}
.pill-green{background:var(--green-light);color:var(--green)}
.pill-amber{background:var(--amber-light);color:var(--amber)}
.pill-brand{background:var(--brand-light);color:var(--brand-2)}
.pill-violet{background:rgba(139,92,246,.12);color:var(--violet)}

.stat-label{font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text3);margin-bottom:6px}
.stat-value{
  font-family:var(--font-head);font-size:26px;font-weight:700;
  color:var(--text);letter-spacing:-.5px;line-height:1;margin-bottom:12px;
}

/* Progress */
.prog-wrap{margin-top:2px}
.prog-row{display:flex;justify-content:space-between;font-size:11px;color:var(--text3);margin-bottom:5px}
.prog-track{height:4px;background:var(--surface4);border-radius:99px;overflow:hidden}
.prog-fill{height:100%;border-radius:99px;transition:width 1.2s cubic-bezier(.4,0,.2,1)}
.stat-card.green .prog-fill{background:var(--green)}
.stat-card.brand .prog-fill{background:linear-gradient(90deg,var(--brand),var(--brand-2))}

/* ─── GRID BOTTOM ─── */
.bottom-grid{
  display:grid;
  grid-template-columns:1fr 360px;
  gap:16px;
  align-items:start;
}

/* ─── PANEL ─── */
.panel{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius);
  overflow:hidden;
}
.panel-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:20px 22px 0;
  margin-bottom:18px;
}
.panel-title{font-family:var(--font-head);font-size:15px;font-weight:700;color:var(--text)}
.panel-sub{font-size:12px;color:var(--text3);margin-top:2px}
.panel-body{padding:0 22px 22px}
.chart-wrap{height:270px;position:relative}

/* ─── TERM DONUT ROW ─── */
.term-row{
  display:grid;grid-template-columns:repeat(3,1fr);gap:10px;
  padding:16px 22px;
  border-top:1px solid var(--border);
}
.term-mini{
  background:var(--surface2);border-radius:var(--radius-sm);
  padding:14px;text-align:center;
  border:1px solid var(--border);
}
.term-mini-label{font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--text3);margin-bottom:6px}
.term-mini-val{font-family:var(--font-head);font-size:16px;font-weight:700;color:var(--text)}

/* ─── RECENT PAYMENTS ─── */
.pay-list{list-style:none;display:flex;flex-direction:column;gap:2px}
.pay-item{
  display:flex;align-items:center;gap:12px;
  padding:11px 14px;
  border-radius:var(--radius-sm);
  transition:background .15s;
  cursor:default;
}
.pay-item:hover{background:var(--surface2)}
.pay-av{
  width:36px;height:36px;border-radius:50%;
  background:var(--surface3);
  border:1px solid var(--border2);
  display:flex;align-items:center;justify-content:center;
  font-size:12px;font-weight:700;color:var(--brand-2);
  flex-shrink:0;
}
.pay-info{flex:1;min-width:0}
.pay-name{font-size:13px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pay-meta{font-size:11px;color:var(--text3);margin-top:2px}
.pay-amt{
  font-family:var(--font-head);font-size:13.5px;font-weight:700;
  color:var(--green);white-space:nowrap;
}
.pay-term-badge{
  font-size:9.5px;font-weight:700;
  padding:2px 7px;border-radius:99px;
  background:var(--brand-light);color:var(--brand-2);
  margin-left:4px;
}

/* ─── QUICK ACTIONS ─── */
.actions-grid{
  display:grid;grid-template-columns:1fr 1fr;gap:8px;
}
.action-tile{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:8px;padding:16px 10px;
  background:var(--surface2);
  border:1px solid var(--border);
  border-radius:var(--radius-sm);
  text-decoration:none;color:var(--text2);
  font-size:12px;font-weight:600;text-align:center;
  transition:all .2s;cursor:pointer;
}
.action-tile:hover{
  background:var(--brand-light);border-color:var(--border3);
  color:var(--brand-2);transform:translateY(-2px);
}
.a-icon{font-size:18px}
.action-full{
  grid-column:1/-1;
  flex-direction:row;gap:8px;
  padding:14px 16px;
  background:linear-gradient(135deg,var(--brand),var(--violet));
  border:none;color:#fff;
  font-size:13px;font-weight:700;
  border-radius:var(--radius-sm);
  box-shadow:0 4px 18px var(--brand-glow);
}
.action-full:hover{opacity:.9;transform:translateY(-2px);color:#fff}

/* ─── VIEW ALL LINK ─── */
.view-link{font-size:12px;color:var(--brand-2);text-decoration:none;font-weight:600;transition:color .15s}
.view-link:hover{color:var(--brand)}

/* ─── EMPTY STATE ─── */
.empty-state{text-align:center;padding:30px 20px;color:var(--text3);font-size:13px}
.empty-icon{font-size:32px;margin-bottom:10px;opacity:.4}

/* ─── OVERLAY ─── */
.overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.6);
  z-index:199;backdrop-filter:blur(4px);
}
.overlay.show{display:block}

/* ─── ANIMATIONS ─── */
@keyframes fadeUp{
  from{opacity:0;transform:translateY(18px)}
  to{opacity:1;transform:translateY(0)}
}
.stat-card{animation:fadeUp .45s ease both}
.stat-card:nth-child(1){animation-delay:.05s}
.stat-card:nth-child(2){animation-delay:.1s}
.stat-card:nth-child(3){animation-delay:.15s}
.stat-card:nth-child(4){animation-delay:.2s}
.bottom-grid > *{animation:fadeUp .45s ease .25s both}

/* ─── RESPONSIVE ─── */
@media(max-width:1200px){
  .stats-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:1024px){
  .bottom-grid{grid-template-columns:1fr}
}
@media(max-width:768px){
  .sidebar{transform:translateX(-110%)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0}
  .hamburger{display:flex}
  .content{padding:14px}
  .stats-grid{grid-template-columns:1fr 1fr;gap:10px}
  .stat-value{font-size:21px}
  .topbar{padding:0 14px}
  .greeting-pill{display:none}
  .page-heading{font-size:14px}
  .term-tabs{padding:3px}
  .term-tab{padding:6px 12px;font-size:11.5px}
  .filter-bar{flex-direction:column;align-items:flex-start}
}
@media(max-width:480px){
  .stats-grid{grid-template-columns:1fr}
  .term-row{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ─── SIDEBAR ─── -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">🎓</div>
    <div>
      <div class="logo-name">Little Friends</div>
      <div class="logo-sub">Schools Management</div>
    </div>
  </div>

  <nav class="nav-wrap">
    <div class="nav-section-label">Main Menu</div>
    <a href="dashboard.php" class="nav-link active">
      <div class="nav-icon">⊞</div> Dashboard
    </a>
    <a href="add_student.php" class="nav-link">
      <div class="nav-icon">👤</div> Students
      <span class="nav-badge"><?= number_format($total_students) ?></span>
    </a>
    <a href="add_teacher.php" class="nav-link">
      <div class="nav-icon">🧑‍🏫</div> Teachers
    </a>
    <a href="fees.php" class="nav-link">
      <div class="nav-icon">💳</div> Fees
    </a>
    <a href="reports.php" class="nav-link">
      <div class="nav-icon">📊</div> Reports
    </a>
    <a href="inventory.php" class="nav-link">
      <div class="nav-icon">📦</div> Inventory
    </a>
       <a href="teacher_dashboard.php" class="nav-link">
      <div class="nav-icon">📦</div> MARKS AND ACADEMICS
    </a>

    <div class="nav-section-label" style="margin-top:8px">System</div>
    <a href="#" class="nav-link">
      <div class="nav-icon">⚙</div> Settings
    </a>
    <a href="../auth/logout.php" class="nav-link">
      <div class="nav-icon">🚪</div> Logout
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar">
        <?php if($avatar): ?>
          <img src="<?= htmlspecialchars($avatar) ?>" alt="">
        <?php else: ?>
          <?= htmlspecialchars(substr($initials,0,2)) ?>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:0">
        <div class="user-name"><?= htmlspecialchars($username) ?></div>
        <div class="user-role">Administrator</div>
      </div>
      <div class="user-dot"></div>
    </div>
  </div>
</aside>

<!-- ─── MAIN ─── -->
<div class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" id="hamburger" onclick="toggleSidebar()" aria-label="Menu">☰</button>
      <div>
        <div class="page-heading">Little Friends Schools</div>
        <div class="page-sub" id="topdate"></div>
      </div>
    </div>
    <div class="topbar-right">
      <div class="greeting-pill">
        <span id="greet-emoji">👋</span>
        <span id="greet-text">Hello,</span>
        <span class="greeting-name"><?= $firstname ?></span>
      </div>
      <a href="reports.php" class="icon-btn" title="Reports">📊</a>
      <a href="fees.php" class="icon-btn" title="Fees">💳</a>
      <div class="topbar-avatar">
        <?php if($avatar): ?>
          <img src="<?= htmlspecialchars($avatar) ?>" alt="">
        <?php else: ?>
          <?= htmlspecialchars(substr($initials,0,2)) ?>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- CONTENT -->
  <main class="content">

    <!-- FILTER BAR -->
    <div class="filter-bar">
      <div>
        <div class="page-heading" style="font-size:22px" id="dash-greeting">Dashboard Overview</div>
        <div class="page-sub" style="margin-top:4px">
          <?= $selected_term > 0 ? "Showing Term $selected_term data" : "Showing all terms · <?= $total_classes ?> active classes" ?>
        </div>
      </div>
      <div>
        <div class="filter-label" style="margin-bottom:8px">Filter by Term</div>
        <div class="term-tabs">
          <a href="?term=0" class="term-tab <?= $selected_term == 0 ? 'active' : '' ?>">All Terms</a>
          <a href="?term=1" class="term-tab <?= $selected_term == 1 ? 'active' : '' ?>">Term 1</a>
          <a href="?term=2" class="term-tab <?= $selected_term == 2 ? 'active' : '' ?>">Term 2</a>
          <a href="?term=3" class="term-tab <?= $selected_term == 3 ? 'active' : '' ?>">Term 3</a>
        </div>
      </div>
    </div>

    <!-- STATS GRID -->
    <div class="stats-grid">

      <!-- Collected -->
      <div class="stat-card green">
        <div class="glow"></div>
        <div class="stat-header">
          <div class="stat-icon-wrap">💰</div>
          <span class="stat-pill pill-green">↑ Collected</span>
        </div>
        <div class="stat-label">Total Collected</div>
        <div class="stat-value" id="val-paid" data-target="<?= $total_paid ?>">KES 0</div>
        <div class="prog-wrap">
          <div class="prog-row"><span>Collection rate</span><span><?= $pct ?>%</span></div>
          <div class="prog-track"><div class="prog-fill" id="pf" style="width:0%;background:var(--green)"></div></div>
        </div>
      </div>

      <!-- Expected -->
      <div class="stat-card brand">
        <div class="glow"></div>
        <div class="stat-header">
          <div class="stat-icon-wrap">📋</div>
          <span class="stat-pill pill-brand">Full Year</span>
        </div>
        <div class="stat-label">Total Expected</div>
        <div class="stat-value" id="val-exp" data-target="<?= $total_expected ?>">KES 0</div>
        <div class="prog-wrap">
          <div class="prog-row"><span>Term progress</span><span><?= $pct ?>%</span></div>
          <div class="prog-track"><div class="prog-fill" id="pf2" style="width:0%"></div></div>
        </div>
      </div>

      <!-- Outstanding -->
      <div class="stat-card amber">
        <div class="glow"></div>
        <div class="stat-header">
          <div class="stat-icon-wrap">⏳</div>
          <span class="stat-pill <?= $pending > 0 ? 'pill-amber' : 'pill-green' ?>">
            <?= $pending > 0 ? '⚠ Pending' : '✓ Cleared' ?>
          </span>
        </div>
        <div class="stat-label">Outstanding Balance</div>
        <div class="stat-value" id="val-pending" data-target="<?= $pending ?>">KES 0</div>
        <div style="font-size:12px;color:var(--text3);margin-top:10px">
          <?= $pending > 0 ? number_format($pending / max($total_students,1)) . ' avg per student' : 'All fees cleared 🎉' ?>
        </div>
      </div>

      <!-- Students -->
      <div class="stat-card violet">
        <div class="glow"></div>
        <div class="stat-header">
          <div class="stat-icon-wrap">🎓</div>
          <span class="stat-pill pill-violet"><?= $total_classes ?> Classes</span>
        </div>
        <div class="stat-label">Enrolled Students</div>
        <div class="stat-value" id="val-students" data-target="<?= $total_students ?>"><?= number_format($total_students) ?></div>
        <div style="font-size:12px;color:var(--text3);margin-top:10px">
          ~<?= $total_classes > 0 ? round($total_students/$total_classes) : 0 ?> students per class avg
        </div>
      </div>

    </div>
    <!-- /STATS GRID -->

    <!-- BOTTOM GRID -->
    <div class="bottom-grid">

      <!-- CHART PANEL -->
      <div class="panel">
        <div class="panel-head">
          <div>
            <div class="panel-title">Fees Collection by Class</div>
            <div class="panel-sub">Collected vs expected — <?= $selected_term > 0 ? "Term $selected_term" : "All terms combined" ?></div>
          </div>
        </div>
        <div class="panel-body">
          <div class="chart-wrap">
            <canvas id="feesChart"></canvas>
          </div>
        </div>

        <!-- TERM BREAKDOWN ROW -->
        <div class="term-row">
          <?php for($t=1;$t<=3;$t++): ?>
          <div class="term-mini">
            <div class="term-mini-label">Term <?= $t ?></div>
            <div class="term-mini-val">KES <?= number_format($term_stats[$t] ?? 0) ?></div>
          </div>
          <?php endfor; ?>
        </div>
      </div>

      <!-- RIGHT COLUMN -->
      <div style="display:flex;flex-direction:column;gap:16px">

        <!-- RECENT PAYMENTS -->
        <div class="panel">
          <div class="panel-head">
            <div>
              <div class="panel-title">Recent Payments</div>
              <div class="panel-sub">Latest transactions</div>
            </div>
            <a href="fees.php" class="view-link">View all →</a>
          </div>
          <div class="panel-body" style="padding-top:0">
            <?php if(empty($recent)): ?>
              <div class="empty-state">
                <div class="empty-icon">💸</div>
                No payments recorded yet.
              </div>
            <?php else: ?>
            <ul class="pay-list">
              <?php foreach($recent as $p): ?>
              <li class="pay-item">
                <div class="pay-av"><?= strtoupper(substr($p['name'],0,1)) ?></div>
                <div class="pay-info">
                  <div class="pay-name"><?= htmlspecialchars($p['name']) ?></div>
                  <div class="pay-meta">
                    <?= htmlspecialchars($p['class_name']) ?>
                    · <?= date('d M', strtotime($p['payment_date'])) ?>
                    <?php if(!empty($p['term'])): ?>
                      <span class="pay-term-badge">T<?= $p['term'] ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="pay-amt">+<?= number_format($p['amount_paid']) ?></div>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">Quick Actions</div>
          </div>
          <div class="panel-body" style="padding-top:0">
            <div class="actions-grid">
              <a href="add_student.php" class="action-tile">
                <span class="a-icon">👤</span> Add Student
              </a>
              <a href="fees.php" class="action-tile">
                <span class="a-icon">💳</span> Record Fee
              </a>
              <a href="add_teacher.php" class="action-tile">
                <span class="a-icon">🧑‍🏫</span> Add Teacher
              </a>
              <a href="inventory.php" class="action-tile">
                <span class="a-icon">📦</span> Inventory
              </a>
              <a href="reports.php" class="action-tile action-full">
                <span class="a-icon">📄</span> Generate Term Report
              </a>
            </div>
          </div>
        </div>

      </div>
    </div>
    <!-- /BOTTOM GRID -->

  </main>
</div>

<script>
/* ─── GREETING (JS — fixes the timezone issue) ─── */
(function(){
  const h = new Date().getHours();
  const greetings = {
    text: h < 12 ? 'Good Morning,' : h < 17 ? 'Good Afternoon,' : 'Good Evening,',
    emoji: h < 12 ? '🌅' : h < 17 ? '☀️' : '🌙'
  };
  document.getElementById('greet-text').textContent = greetings.text;
  document.getElementById('greet-emoji').textContent = greetings.emoji;

  /* Dashboard greeting header */
  const dashGreet = h < 12 ? 'Good Morning 🌅' : h < 17 ? 'Good Afternoon ☀️' : 'Good Evening 🌙';
  document.getElementById('dash-greeting').textContent = dashGreet;

  /* Date sub */
  const opts = {weekday:'long',year:'numeric',month:'long',day:'numeric'};
  document.getElementById('topdate').textContent = new Date().toLocaleDateString('en-KE', opts);
})();

/* ─── SIDEBAR ─── */
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}

/* ─── ANIMATED COUNTERS ─── */
function animateCounter(el, target, prefix){
  const duration = 1000;
  const start = performance.now();
  function step(now){
    const p = Math.min((now-start)/duration,1);
    const ease = 1-Math.pow(1-p,3);
    const val = Math.round(ease*target);
    el.textContent = prefix + val.toLocaleString();
    if(p < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

setTimeout(()=>{
  const paid = <?= (float)$total_paid ?>;
  const exp  = <?= (float)$total_expected ?>;
  const pend = <?= (float)$pending ?>;
  const stu  = <?= (int)$total_students ?>;

  animateCounter(document.getElementById('val-paid'),    paid, 'KES ');
  animateCounter(document.getElementById('val-exp'),     exp,  'KES ');
  animateCounter(document.getElementById('val-pending'), pend, 'KES ');

  /* Progress bars */
  const pct = <?= $pct ?>;
  document.getElementById('pf').style.width  = pct + '%';
  document.getElementById('pf2').style.width = pct + '%';
}, 300);

/* ─── CHART ─── */
const ctx = document.getElementById('feesChart').getContext('2d');

const gradPaid = ctx.createLinearGradient(0, 0, 0, 270);
gradPaid.addColorStop(0, 'rgba(108,99,255,0.9)');
gradPaid.addColorStop(1, 'rgba(167,139,250,0.7)');

new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      {
        label: 'Collected',
        data: <?= json_encode($chartPaid) ?>,
        backgroundColor: gradPaid,
        borderRadius: 7,
        borderSkipped: false,
        barPercentage: 0.55,
      },
      {
        label: 'Expected',
        data: <?= json_encode($chartExp) ?>,
        backgroundColor: 'rgba(255,255,255,0.05)',
        borderColor: 'rgba(255,255,255,0.1)',
        borderWidth: 1,
        borderRadius: 7,
        borderSkipped: false,
        barPercentage: 0.55,
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'top',
        align: 'end',
        labels: {
          color: '#9899B0',
          font: { size: 11, family: "'Plus Jakarta Sans',sans-serif", weight: '600' },
          boxWidth: 10, boxHeight: 10, borderRadius: 3, usePointStyle: false,
          padding: 16,
        }
      },
      tooltip: {
        backgroundColor: '#181929',
        borderColor: 'rgba(255,255,255,0.1)',
        borderWidth: 1,
        titleColor: '#EEEEF5',
        bodyColor: '#9899B0',
        padding: 12,
        cornerRadius: 10,
        callbacks: {
          label: c => '  KES ' + c.parsed.y.toLocaleString()
        }
      }
    },
    scales: {
      x: {
        grid: { color: 'rgba(255,255,255,0.03)', drawBorder: false },
        ticks: { color: '#9899B0', font: { size: 11, family: "'Plus Jakarta Sans',sans-serif" } },
        border: { color: 'transparent' }
      },
      y: {
        grid: { color: 'rgba(255,255,255,0.05)', drawBorder: false },
        ticks: {
          color: '#9899B0',
          font: { size: 11, family: "'Plus Jakarta Sans',sans-serif" },
          callback: v => 'KES ' + (v >= 1000 ? (v/1000).toFixed(0)+'K' : v)
        },
        border: { color: 'transparent' }
      }
    },
    animation: { duration: 1000, easing: 'easeOutQuart' }
  }
});
</script>
</body>
</html>