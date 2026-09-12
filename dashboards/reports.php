<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$search_student = $_GET['search_student'] ?? '';
$term_filter    = $_GET['term_id'] ?? '';

/* ─── TERMS ─────────────────────────────────────── */
$terms = [];
$terms_result = mysqli_query($conn, "SELECT * FROM terms ORDER BY id");
while($t = mysqli_fetch_assoc($terms_result)) $terms[] = $t;

/* ─── STUDENTS ───────────────────────────────────── */
$where_student = "1=1";
if(!empty($search_student)){
    $s = mysqli_real_escape_string($conn, $search_student);
    $where_student .= " AND u.name LIKE '%$s%'";
}

$students_result = mysqli_query($conn, "
    SELECT st.user_id AS student_id, u.name AS student_name,
           c.class_name
    FROM students st
    JOIN users u ON st.user_id = u.id
    LEFT JOIN classes c ON st.class_id = c.id
    WHERE $where_student
    ORDER BY u.name
");
if(!$students_result) die("Query error: ".mysqli_error($conn));

$students = [];
while($stu = mysqli_fetch_assoc($students_result)){
    $students[$stu['student_id']] = [
        'name'     => $stu['student_name'],
        'class'    => $stu['class_name'] ?? '—',
        'subjects' => []
    ];
}

/* ─── MARKS ──────────────────────────────────────── */
if(!empty($students)){
    $where_term = !empty($term_filter)
        ? "AND m.term_id='".mysqli_real_escape_string($conn,$term_filter)."'"
        : "";

    $marks_result = mysqli_query($conn, "
        SELECT m.student_id, sub.subject_name, t.term_name,
               m.score, m.level, te.name AS teacher_name
        FROM marks m
        LEFT JOIN subjects sub ON m.subject_id = sub.id
        LEFT JOIN terms t      ON m.term_id    = t.id
        LEFT JOIN users te     ON m.teacher_id = te.id
        WHERE m.student_id IN (".implode(',',array_keys($students)).") $where_term
        ORDER BY m.student_id, sub.subject_name
    ");
    if(!$marks_result) die("Query error: ".mysqli_error($conn));

    while($row = mysqli_fetch_assoc($marks_result)){
        $sid = $row['student_id'];
        if(isset($students[$sid])){
            $students[$sid]['subjects'][] = [
                'subject' => $row['subject_name']  ?? 'Unknown Subject',
                'score'   => $row['score']          ?? '-',
                'level'   => $row['level']          ?? '-',
                'teacher' => $row['teacher_name']   ?? 'Unknown Teacher',
                'term'    => $row['term_name']       ?? 'Unknown Term',
            ];
        }
    }
}

/* ─── HELPERS ────────────────────────────────────── */
function gradeColor($score){
    if(!is_numeric($score)) return 'badge-neutral';
    $s = (int)$score;
    if($s >= 75) return 'badge-green';
    if($s >= 50) return 'badge-amber';
    return 'badge-red';
}
function levelBadge($level){
    $l = strtoupper(trim($level ?? ''));
    $map = [
        'EE'=>'badge-green','ME'=>'badge-indigo',
        'AE'=>'badge-amber', 'BE'=>'badge-red',
    ];
    return $map[$l] ?? 'badge-neutral';
}

$has_data = false;
foreach($students as $stu) if(count($stu['subjects'])>0){ $has_data=true; break; }

$total_students = count($students);
$total_with_marks = 0;
foreach($students as $s) if(count($s['subjects'])>0) $total_with_marks++;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Academic Reports — School Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --indigo:#5B5FED;
  --indigo-dim:#7B7EF1;
  --indigo-glow:rgba(91,95,237,.18);
  --green:#22C55E;
  --amber:#F59E0B;
  --red:#EF4444;
  --bg:#0C0D14;
  --surface:#13141F;
  --surface2:#1A1C2E;
  --surface3:#222438;
  --border:rgba(255,255,255,.07);
  --border2:rgba(255,255,255,.13);
  --text:#F0F0FA;
  --muted:#8B8FA8;
  --hint:#555972;
  --radius:14px;
  --radius-sm:8px;
  --sidebar-w:260px;
  --topbar-h:68px;
  --font-head:'Syne',sans-serif;
  --font-body:'DM Sans',sans-serif;
}
html{scroll-behavior:smooth}
body{
  font-family:var(--font-body);
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  -webkit-font-smoothing:antialiased;
  display:flex;
}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--surface3);border-radius:99px}

/* ─── SIDEBAR ─────────────── */
.sidebar{
  position:fixed;top:0;left:0;
  width:var(--sidebar-w);height:100vh;
  background:var(--surface);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  z-index:100;
  transition:transform .32s cubic-bezier(.4,0,.2,1);
  overflow:hidden;
}
.sidebar-logo{
  display:flex;align-items:center;gap:12px;
  padding:22px 24px 20px;
  border-bottom:1px solid var(--border);
}
.logo-icon{
  width:36px;height:36px;
  background:var(--indigo);border-radius:10px;
  display:flex;align-items:center;justify-content:center;font-size:18px;
}
.logo-text{
  font-family:var(--font-head);font-size:17px;font-weight:700;
  color:var(--text);letter-spacing:-.3px;
}
.nav-section{padding:18px 14px 8px}
.nav-label{
  font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;
  color:var(--hint);padding:0 10px;margin-bottom:8px;
}
.nav-link{
  display:flex;align-items:center;gap:12px;padding:11px 14px;
  border-radius:var(--radius-sm);color:var(--muted);
  text-decoration:none;font-size:14.5px;font-weight:400;
  margin-bottom:2px;transition:background .18s,color .18s;white-space:nowrap;
}
.nav-link .nav-icon{font-size:16px;width:20px;text-align:center;flex-shrink:0}
.nav-link:hover{background:var(--surface2);color:var(--text)}
.nav-link.active{background:var(--indigo-glow);color:var(--indigo-dim);font-weight:500}
.nav-link.active .nav-icon{color:var(--indigo)}
.sidebar-footer{
  margin-top:auto;padding:16px;
  border-top:1px solid var(--border);
}
.sidebar-user{
  display:flex;align-items:center;gap:10px;padding:10px;
  border-radius:var(--radius-sm);cursor:pointer;transition:background .18s;
}
.sidebar-user:hover{background:var(--surface2)}
.avatar{
  width:36px;height:36px;border-radius:50%;
  background:var(--indigo);
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-head);font-size:13px;font-weight:700;color:#fff;flex-shrink:0;
}
.user-info{flex:1;min-width:0}
.user-name{font-size:13.5px;font-weight:500;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:11.5px;color:var(--muted)}

/* ─── MAIN ─────────────────── */
.main{margin-left:var(--sidebar-w);width:100%;display:flex;flex-direction:column}

/* ─── TOPBAR ───────────────── */
.topbar{
  height:var(--topbar-h);background:var(--bg);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 28px;position:sticky;top:0;z-index:90;
}
.topbar-left{display:flex;align-items:center;gap:14px}
.hamburger{
  display:none;background:none;border:none;color:var(--muted);
  cursor:pointer;padding:6px;border-radius:var(--radius-sm);
  font-size:20px;line-height:1;transition:background .18s,color .18s;
}
.hamburger:hover{background:var(--surface2);color:var(--text)}
.page-title{font-family:var(--font-head);font-size:20px;font-weight:700;letter-spacing:-.3px}
.topbar-right{display:flex;align-items:center;gap:12px}
.icon-btn{
  width:38px;height:38px;background:var(--surface2);border:1px solid var(--border);
  border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;
  cursor:pointer;font-size:16px;color:var(--muted);transition:all .18s;text-decoration:none;
}
.icon-btn:hover{background:var(--surface3);color:var(--text);border-color:var(--border2)}
.print-btn{
  display:flex;align-items:center;gap:8px;
  padding:0 16px;height:38px;
  background:var(--indigo);border:none;
  border-radius:var(--radius-sm);color:#fff;
  font-family:var(--font-body);font-size:13.5px;font-weight:500;
  cursor:pointer;transition:background .18s;
}
.print-btn:hover{background:#6366f1}

/* ─── CONTENT ──────────────── */
.content{padding:28px;flex:1}

/* ─── STAT STRIP ───────────── */
.stat-strip{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
  gap:14px;margin-bottom:24px;
}
.sstat{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);padding:18px 20px;
  transition:border-color .2s,transform .2s;
  animation:fadeUp .4s ease both;
}
.sstat:nth-child(1){animation-delay:.05s}
.sstat:nth-child(2){animation-delay:.1s}
.sstat:nth-child(3){animation-delay:.15s}
.sstat:hover{border-color:var(--border2);transform:translateY(-2px)}
.sstat-label{font-size:11px;text-transform:uppercase;letter-spacing:.9px;font-weight:500;color:var(--muted);margin-bottom:6px}
.sstat-val{font-family:var(--font-head);font-size:26px;font-weight:700;color:var(--text);line-height:1}
.sstat-sub{font-size:12px;color:var(--muted);margin-top:5px}

/* ─── FILTER PANEL ─────────── */
.filter-panel{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);padding:22px 24px;
  margin-bottom:24px;
  animation:fadeUp .4s ease .2s both;
}
.filter-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.filter-group{display:flex;flex-direction:column;gap:6px;flex:1;min-width:180px}
.filter-label{font-size:12px;font-weight:500;color:var(--muted);letter-spacing:.3px}
.filter-input,
.filter-select{
  height:42px;padding:0 14px;
  background:var(--surface2);
  border:1px solid var(--border);
  border-radius:var(--radius-sm);
  color:var(--text);
  font-family:var(--font-body);font-size:14px;
  outline:none;transition:border-color .18s;
  width:100%;
}
.filter-input::placeholder{color:var(--hint)}
.filter-select option{background:var(--surface2)}
.filter-input:focus,.filter-select:focus{border-color:var(--indigo)}
.filter-btn{
  height:42px;padding:0 22px;
  background:var(--indigo);border:none;
  border-radius:var(--radius-sm);
  color:#fff;font-family:var(--font-body);
  font-size:14px;font-weight:500;
  cursor:pointer;transition:background .18s;
  white-space:nowrap;flex-shrink:0;
}
.filter-btn:hover{background:#6366f1}
.clear-btn{
  height:42px;padding:0 16px;
  background:var(--surface2);
  border:1px solid var(--border);
  border-radius:var(--radius-sm);
  color:var(--muted);font-family:var(--font-body);font-size:14px;
  cursor:pointer;transition:all .18s;white-space:nowrap;flex-shrink:0;
  text-decoration:none;display:flex;align-items:center;
}
.clear-btn:hover{background:var(--surface3);color:var(--text);border-color:var(--border2)}

/* ─── STUDENT CARDS ─────────── */
.students-list{display:flex;flex-direction:column;gap:16px;animation:fadeUp .4s ease .25s both}

.student-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius);
  overflow:hidden;
  transition:border-color .2s;
}
.student-card:hover{border-color:var(--border2)}

.student-header{
  display:flex;align-items:center;gap:14px;
  padding:18px 22px;
  background:var(--surface2);
  border-bottom:1px solid var(--border);
  cursor:pointer;
  user-select:none;
}
.student-header:hover{background:var(--surface3)}
.stu-avatar{
  width:40px;height:40px;border-radius:50%;
  background:var(--indigo-glow);
  border:1px solid var(--indigo);
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-head);font-size:14px;font-weight:700;
  color:var(--indigo-dim);flex-shrink:0;
}
.stu-info{flex:1;min-width:0}
.stu-name{
  font-family:var(--font-head);font-size:15px;font-weight:600;
  color:var(--text);
}
.stu-meta{font-size:12.5px;color:var(--muted);margin-top:2px}
.stu-badges{display:flex;align-items:center;gap:8px;margin-left:auto;flex-shrink:0}
.collapse-icon{
  color:var(--hint);font-size:18px;
  transition:transform .25s;flex-shrink:0;margin-left:8px;
}
.student-card.collapsed .collapse-icon{transform:rotate(-90deg)}

/* ─── TABLE ────────────────── */
.marks-wrap{
  overflow-x:auto;
  transition:max-height .3s cubic-bezier(.4,0,.2,1),opacity .25s;
  max-height:800px;opacity:1;
}
.student-card.collapsed .marks-wrap{max-height:0;opacity:0;overflow:hidden}

.marks-table{
  width:100%;border-collapse:collapse;min-width:560px;
}
.marks-table thead tr{
  background:rgba(91,95,237,.08);
  border-bottom:1px solid var(--border2);
}
.marks-table th{
  font-size:11px;font-weight:600;
  text-transform:uppercase;letter-spacing:.9px;
  color:var(--muted);padding:12px 18px;
  white-space:nowrap;text-align:left;
}
.marks-table td{
  padding:13px 18px;
  font-size:13.5px;
  color:var(--text);
  border-bottom:1px solid var(--border);
}
.marks-table tbody tr:last-child td{border-bottom:none}
.marks-table tbody tr{transition:background .15s}
.marks-table tbody tr:hover{background:var(--surface2)}
.marks-table .subject-name{font-weight:500;color:var(--text)}
.marks-table .teacher-cell{color:var(--muted);font-size:13px}

/* ─── BADGES ───────────────── */
.badge{
  display:inline-flex;align-items:center;
  font-size:11.5px;font-weight:600;
  padding:3px 10px;border-radius:99px;
  white-space:nowrap;
}
.badge-green{background:rgba(34,197,94,.12);color:#4ade80}
.badge-amber{background:rgba(245,158,11,.12);color:#FCD34D}
.badge-red{background:rgba(239,68,68,.12);color:#F87171}
.badge-indigo{background:var(--indigo-glow);color:var(--indigo-dim)}
.badge-neutral{background:var(--surface3);color:var(--muted)}

/* ─── EMPTY STATE ──────────── */
.empty-row td{
  text-align:center;padding:22px;
  color:var(--muted);font-size:13.5px;
}
.empty-row .empty-icon{font-size:22px;display:block;margin-bottom:6px}

/* ─── NO RESULTS ───────────── */
.no-results{
  text-align:center;padding:60px 20px;
  color:var(--muted);
}
.no-results .nr-icon{font-size:40px;margin-bottom:12px}
.no-results p{font-size:15px}

/* ─── TOAST ─────────────────── */
.toast{
  position:fixed;bottom:28px;right:28px;
  background:var(--surface2);
  border:1px solid var(--border2);
  border-left:3px solid var(--green);
  border-radius:var(--radius-sm);
  padding:14px 18px;
  display:flex;align-items:center;gap:10px;
  font-size:13.5px;color:var(--text);
  z-index:999;
  transform:translateY(80px);opacity:0;
  transition:transform .35s cubic-bezier(.4,0,.2,1),opacity .3s;
  pointer-events:none;
}
.toast.show{transform:translateY(0);opacity:1}
.toast-dot{width:8px;height:8px;border-radius:50%;background:var(--green);flex-shrink:0}

/* ─── OVERLAY ──────────────── */
.overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.5);z-index:99;backdrop-filter:blur(3px);
}
.overlay.show{display:block}

/* ─── PRINT ─────────────────── */
@media print{
  .sidebar,.topbar,.filter-panel,.stat-strip,.toast,.overlay,.no-print{display:none!important}
  .main{margin-left:0!important}
  .student-card.collapsed .marks-wrap{max-height:none!important;opacity:1!important}
  body{background:#fff!important;color:#000!important}
  .marks-table th,.marks-table td{color:#000!important;border-color:#ccc!important}
  .student-header{background:#f5f5f5!important;color:#000!important}
  .stu-name{color:#000!important}
}

/* ─── MOBILE ─────────────────── */
@media(max-width:768px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.show{transform:translateX(0)}
  .main{margin-left:0}
  .hamburger{display:flex}
  .content{padding:16px}
  .topbar{padding:0 16px}
  .print-btn span{display:none}
  .stat-strip{grid-template-columns:1fr 1fr}
  .filter-row{flex-direction:column}
  .filter-group{min-width:unset;width:100%}
}

/* ─── ANIMATIONS ─────────────── */
@keyframes fadeUp{
  from{opacity:0;transform:translateY(14px)}
  to{opacity:1;transform:translateY(0)}
}
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ─── SIDEBAR ─────────────────────────── -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">🎓</div>
    <span class="logo-text">SchoolAdmin</span>
  </div>
  <div class="nav-section">
    <div class="nav-label">Main</div>
    <a href="admin.php" class="nav-link"><span class="nav-icon">⊞</span> Dashboard</a>
    <a href="add_student.php" class="nav-link"><span class="nav-icon">👤</span> Students</a>
    <a href="fees.php" class="nav-link"><span class="nav-icon">💳</span> Fees</a>
    <a href="reports.php" class="nav-link active"><span class="nav-icon">📊</span> Reports</a>
    <a href="inventory.php" class="nav-link"><span class="nav-icon">📦</span> Inventory</a>
  </div>
  <div class="nav-section" style="margin-top:auto">
    <div class="nav-label">System</div>
    <a href="#" class="nav-link"><span class="nav-icon">⚙</span> Settings</a>
    <a href="../auth/logout.php" class="nav-link"><span class="nav-icon">↩</span> Logout</a>
  </div>
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar">AD</div>
      <div class="user-info">
        <div class="user-name">Administrator</div>
        <div class="user-role">Admin Panel</div>
      </div>
    </div>
  </div>
</aside>

<!-- ─── MAIN ─────────────────────────────── -->
<div class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger" onclick="toggleSidebar()">☰</button>
      <div class="page-title">Academic Reports</div>
    </div>
    <div class="topbar-right">
      <a href="admin.php" class="icon-btn no-print" title="Dashboard">⊞</a>
      <button class="print-btn no-print" onclick="window.print()">
        🖨 <span>Print Report</span>
      </button>
    </div>
  </header>

  <main class="content">

    <!-- STAT STRIP -->
    <div class="stat-strip">
      <div class="sstat">
        <div class="sstat-label">Total Students</div>
        <div class="sstat-val"><?= number_format($total_students) ?></div>
        <div class="sstat-sub">in current filter</div>
      </div>
      <div class="sstat">
        <div class="sstat-label">With Records</div>
        <div class="sstat-val"><?= number_format($total_with_marks) ?></div>
        <div class="sstat-sub">marks recorded</div>
      </div>
      <div class="sstat">
        <div class="sstat-label">No Records</div>
        <div class="sstat-val"><?= number_format($total_students - $total_with_marks) ?></div>
        <div class="sstat-sub">awaiting entry</div>
      </div>
      <div class="sstat">
        <div class="sstat-label">Active Term</div>
        <div class="sstat-val" style="font-size:16px;line-height:1.3">
          <?php
          if(!empty($term_filter)){
            foreach($terms as $t) if($t['id']==$term_filter){ echo htmlspecialchars($t['term_name']); break; }
          } else {
            echo 'All Terms';
          }
          ?>
        </div>
        <div class="sstat-sub">current selection</div>
      </div>
    </div>

    <!-- FILTER PANEL -->
    <div class="filter-panel">
      <form method="GET">
        <div class="filter-row">
          <div class="filter-group">
            <label class="filter-label">Search Student</label>
            <input type="text" name="search_student"
              class="filter-input"
              placeholder="Type student name…"
              value="<?= htmlspecialchars($search_student) ?>">
          </div>
          <div class="filter-group">
            <label class="filter-label">Term</label>
            <select name="term_id" class="filter-select">
              <option value="">All Terms</option>
              <?php foreach($terms as $term): ?>
                <option value="<?= $term['id'] ?>" <?= ($term_filter==$term['id'])?'selected':'' ?>>
                  <?= htmlspecialchars($term['term_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="filter-btn">🔍 Filter</button>
          <?php if(!empty($search_student) || !empty($term_filter)): ?>
            <a href="reports.php" class="clear-btn">✕ Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- STUDENT CARDS -->
    <?php if(empty($students)): ?>
      <div class="no-results">
        <div class="nr-icon">🔍</div>
        <p>No students found<?= !empty($search_student) ? ' for "'.htmlspecialchars($search_student).'"' : '' ?>.</p>
      </div>

    <?php else: ?>
    <div class="students-list">
      <?php foreach($students as $sid => $stu):
        $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(explode(' ', trim($stu['name'])), 0, 2))));
        $subCount = count($stu['subjects']);
        $avgScore = 0;
        if($subCount > 0){
          $scores = array_filter(array_column($stu['subjects'],'score'),'is_numeric');
          $avgScore = count($scores) ? round(array_sum($scores)/count($scores)) : 0;
        }
      ?>
      <div class="student-card" id="card-<?= $sid ?>">

        <div class="student-header" onclick="toggleCard(<?= $sid ?>)">
          <div class="stu-avatar"><?= htmlspecialchars($initials) ?></div>
          <div class="stu-info">
            <div class="stu-name"><?= htmlspecialchars($stu['name']) ?></div>
            <div class="stu-meta">
              <?= htmlspecialchars($stu['class']) ?>
              <?php if($subCount > 0): ?> · <?= $subCount ?> subject<?= $subCount!=1?'s':'' ?><?php endif; ?>
            </div>
          </div>
          <div class="stu-badges">
            <?php if($subCount === 0): ?>
              <span class="badge badge-neutral">No records</span>
            <?php else: ?>
              <span class="badge <?= gradeColor($avgScore) ?>">Avg: <?= $avgScore ?>%</span>
              <span class="badge badge-indigo"><?= $subCount ?> subjects</span>
            <?php endif; ?>
          </div>
          <span class="collapse-icon">⌄</span>
        </div>

        <div class="marks-wrap">
          <table class="marks-table">
            <thead>
              <tr>
                <th>Subject</th>
                <th>Score</th>
                <th>Level</th>
                <th>Teacher</th>
                <th>Term</th>
              </tr>
            </thead>
            <tbody>
              <?php if($subCount === 0): ?>
                <tr class="empty-row">
                  <td colspan="5">
                    <span class="empty-icon">📭</span>
                    No marks recorded for this student yet.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach($stu['subjects'] as $sub): ?>
                <tr>
                  <td class="subject-name"><?= htmlspecialchars($sub['subject']) ?></td>
                  <td>
                    <?php if(is_numeric($sub['score'])): ?>
                      <span class="badge <?= gradeColor($sub['score']) ?>"><?= htmlspecialchars($sub['score']) ?>%</span>
                    <?php else: ?>
                      <span style="color:var(--hint)">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if($sub['level'] !== '-'): ?>
                      <span class="badge <?= levelBadge($sub['level']) ?>"><?= htmlspecialchars($sub['level']) ?></span>
                    <?php else: ?>
                      <span style="color:var(--hint)">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="teacher-cell"><?= htmlspecialchars($sub['teacher']) ?></td>
                  <td><span class="badge badge-neutral"><?= htmlspecialchars($sub['term']) ?></span></td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </main>
</div>

<!-- TOAST -->
<?php if($has_data): ?>
<div class="toast" id="toast">
  <div class="toast-dot"></div>
  <?= number_format($total_with_marks) ?> student<?= $total_with_marks!=1?'s':'' ?> with marks loaded
</div>
<?php endif; ?>

<script>
/* ─── SIDEBAR ───────────── */
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('show');
  document.getElementById('overlay').classList.toggle('show');
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('show');
  document.getElementById('overlay').classList.remove('show');
}

/* ─── COLLAPSE CARDS ────── */
function toggleCard(id){
  document.getElementById('card-'+id).classList.toggle('collapsed');
}

/* ─── TOAST ─────────────── */
<?php if($has_data): ?>
setTimeout(()=>{
  const t = document.getElementById('toast');
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), 3500);
}, 600);
<?php endif; ?>
</script>

</body>
</html>