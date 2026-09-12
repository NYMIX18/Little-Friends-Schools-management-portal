<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("../config/db.php");

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: ../index.php");
    exit();
}

if (!$conn) {
    die("DB connection failed: " . mysqli_connect_error());
}


if (isset($_GET['debug_marks'])) {
    echo '<pre style="background:#111;color:#0f0;padding:16px;margin:16px;border:2px solid orange;font-size:12px;">';
    echo "SESSION user_id: "; var_dump($_SESSION['user_id'] ?? 'NOT SET');
    echo "SESSION role: "; var_dump($_SESSION['role'] ?? 'NOT SET');
    echo '</pre>';
}

// --- TEACHER ID ---
$teacher_id = null;
$stmt = $conn->prepare("SELECT id FROM teachers WHERE user_id=?");
if ($stmt) {
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $teacher_row = $res->fetch_assoc();
    $teacher_id  = $teacher_row['id'] ?? null;
    $stmt->close();
}

// --- LOAD DROPDOWNS (safe — check table exists before querying) ---
$classes      = $conn->query("SELECT * FROM classes ORDER BY class_name");
$subjects_all = $conn->query("SELECT * FROM subjects ORDER BY subject_name");
$terms        = $conn->query("SELECT * FROM terms ORDER BY id DESC");
$notifications= $conn->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 20");

// newsletters table may not exist — guard it
$newsletter = null;
$nl_check = $conn->query("SHOW TABLES LIKE 'newsletters'");
if ($nl_check && $nl_check->num_rows > 0) {
    $nl_res = $conn->query("SELECT * FROM newsletters ORDER BY id DESC LIMIT 1");
    if ($nl_res) $newsletter = $nl_res->fetch_assoc();
}

// --- POST VARS ---
$class_id  = $_POST['class_id']  ?? null;
$term_id   = $_POST['term_id']   ?? null;
$exam_type = $_POST['exam_type'] ?? null;

// --- STUDENTS ---
$students = [];
if ($class_id) {
    $stmt = $conn->prepare(
        "SELECT st.id, u.name, c.class_name
         FROM students st
         JOIN users u ON st.user_id = u.id
         JOIN classes c ON st.class_id = c.id
         WHERE st.class_id = ?
         ORDER BY u.name"
    );
    if ($stmt) {
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $students[] = $row;
        $stmt->close();
    }
}

// --- GRADE (CBE) ---
function grade($score) {
    if ($score >= 80) return "A";
    if ($score >= 65) return "B";
    if ($score >= 50) return "C";
    if ($score >= 35) return "D";
    return "E";
}
function gradeColor($grade) {
    return ['A'=>'#10b981','B'=>'#3b82f6','C'=>'#f59e0b','D'=>'#f97316','E'=>'#ef4444'][$grade] ?? '#ef4444';
}
function remark($avg) {
    if ($avg >= 75) return ["Excellent performance!", "🏆"];
    if ($avg >= 60) return ["Very good work.",        "⭐"];
    if ($avg >= 50) return ["Good effort.",            "👍"];
    if ($avg >= 40) return ["Fair — improve more.",    "📈"];
    return ["Needs serious improvement.", "📚"];
}

// --- SAVE MARKS ---
$message = "";
if (isset($_POST['save_marks'])) {

    $exam_type = trim($exam_type ?? '');
    $class_id  = trim($class_id ?? '');
    $term_id   = trim($term_id ?? '');

    if (!in_array($exam_type, ['CAT', 'End-term'], true)) {
        $message = "⚠️ Could not save: exam_type was empty or invalid ('" . htmlspecialchars($exam_type) . "'). Please re-select the exam type on Step 1 and try again.";
    } elseif (!$class_id || !$term_id) {
        $message = "⚠️ Could not save: class or term was missing on submit.";
    } elseif (empty($_POST['marks'])) {
        $message = "⚠️ No marks were submitted.";
    } else {
        $saved = 0;
        foreach ($_POST['marks'] as $student_id => $subs) {
            foreach ($subs as $subject_id => $score) {
                if ($score === "" || $score === null) continue;
                $score = max(0, min(100, (int)$score));
                $g     = grade($score);
                $stmt  = $conn->prepare(
                    "INSERT INTO marks (student_id, subject_id, teacher_id, score, grade, exam_type, term_id)
                     VALUES (?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        score=VALUES(score),
                        grade=VALUES(grade),
                        exam_type=VALUES(exam_type),
                        teacher_id=VALUES(teacher_id)"
                );
                if ($stmt) {
                    $stmt->bind_param(
                        "iiiissi",
                        $student_id, $subject_id, $teacher_id,
                        $score, $g, $exam_type, $term_id
                    );
                    if ($stmt->execute()) {
                        $saved++;
                    } else {
                        $message .= " ⚠️ Insert failed (student {$student_id}, subject {$subject_id}): " . htmlspecialchars($stmt->error) . "<br>";
                    }
                    $stmt->close();
                }
            }
        }
        $message = "✅ Saved {$saved} mark(s) successfully! (exam_type=" . htmlspecialchars($exam_type) . ", term_id=" . htmlspecialchars($term_id) . ")" . $message;
    }
}

// --- GENERATE REPORT CARDS ---
$report_cards = [];
if (isset($_POST['generate_reports']) && $class_id && $term_id && $exam_type && !empty($students)) {
    foreach ($students as $s) {
        $stmt = $conn->prepare(
            "SELECT m.*, sub.subject_name
             FROM marks m
             JOIN subjects sub ON m.subject_id = sub.id
             WHERE m.student_id=? AND m.exam_type=? AND m.term_id=?"
        );
        if ($stmt) {
            $stmt->bind_param("isi", $s['id'], $exam_type, $term_id);
            $stmt->execute();
            $mres  = $stmt->get_result();
            $marks = [];
            $total = 0;
            $count = 0;
            while ($m = $mres->fetch_assoc()) {
                $marks[] = $m;
                $total  += $m['score'];
                $count++;
            }
            $stmt->close();
        }
        $avg = $count ? $total / $count : 0;
        $rem = remark($avg);
        $report_cards[] = [
            'student' => $s,
            'marks'   => $marks,
            'total'   => $total,
            'avg'     => $avg,
            'remark'  => $rem[0],
            'emoji'   => $rem[1],
            'count'   => $count,
        ];
    }
}

// --- DEBUG: raw marks table dump (remove once fixed) ---
if (isset($_GET['debug_marks'])) {
    echo '<pre style="background:#111;color:#0f0;padding:16px;margin:16px;border:2px solid red;font-size:12px;white-space:pre-wrap;">';
    echo "Current filter values -> class_id: " . var_export($class_id, true)
        . " | term_id: " . var_export($term_id, true)
        . " | exam_type: " . var_export($exam_type, true) . "\n\n";
    $dbg = $conn->query("SELECT * FROM marks ORDER BY id DESC LIMIT 15");
    if ($dbg) {
        while ($row = $dbg->fetch_assoc()) {
            print_r($row);
        }
    } else {
        echo "Query failed: " . $conn->error;
    }
    echo '</pre>';
}

// pull subjects into array for JS / template use
$subjects_arr = [];
if ($subjects_all) {
    $subjects_all->data_seek(0);
    while ($s = $subjects_all->fetch_assoc()) $subjects_arr[] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard — Little Friends School</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ─── RESET ─────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:          #080d18;
    --surface:     #0e1520;
    --surface2:    #141e2e;
    --surface3:    #1a2640;
    --surface4:    #1f2e4a;
    --border:      #1e2d45;
    --border2:     #243450;
    --accent:      #4f8ef7;
    --accent2:     #7c5cfc;
    --accent-dim:  rgba(79,142,247,.15);
    --accent-glow: rgba(79,142,247,.22);
    --green:       #10b981;
    --green-dim:   rgba(16,185,129,.15);
    --amber:       #f59e0b;
    --red:         #ef4444;
    --gold:        #f5c842;
    --text:        #e2e8f0;
    --text2:       #94a3b8;
    --text3:       #526070;
    --radius:      16px;
    --radius-sm:   10px;
    --radius-xs:   6px;
    --shadow:      0 8px 32px rgba(0,0,0,.5);
    --shadow-sm:   0 2px 12px rgba(0,0,0,.35);
}

html { scroll-behavior: smooth; }
body {
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    height: 64px;
    padding: 0 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 200;
}
.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: 1.05rem;
    text-decoration: none;
    color: var(--text);
}
.brand-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem; color: #fff; flex-shrink: 0;
}
.topbar-right { display: flex; align-items: center; gap: 12px; }

.icon-btn {
    position: relative;
    width: 40px; height: 40px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 1.05rem; color: var(--text2);
    transition: all .2s; flex-shrink: 0;
}
.icon-btn:hover { background: var(--surface3); color: var(--accent); border-color: var(--accent); }
.badge {
    position: absolute; top: -4px; right: -4px;
    width: 16px; height: 16px;
    background: var(--red); border-radius: 50%;
    font-size: 9px; color: #fff;
    display: flex; align-items: center; justify-content: center; font-weight: 700;
}
.teacher-chip {
    display: flex; align-items: center; gap: 8px;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 10px; padding: 6px 14px;
    font-size: .85rem; font-weight: 500;
}
.avatar {
    width: 28px; height: 28px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 700; color: #fff;
}
.hamburger {
    display: none;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 8px; color: var(--text2);
    width: 40px; height: 40px;
    align-items: center; justify-content: center;
    cursor: pointer; font-size: 1.1rem;
}

/* ─── LAYOUT ─────────────────────────────────────────────── */
.layout { display: flex; flex: 1; overflow: hidden; }

/* ─── SIDEBAR ────────────────────────────────────────────── */
.sidebar {
    width: 240px;
    background: var(--surface);
    border-right: 1px solid var(--border);
    padding: 20px 14px;
    display: flex; flex-direction: column; gap: 4px;
    overflow-y: auto; flex-shrink: 0;
    transition: transform .3s ease;
}
.sidebar-section {
    font-size: .65rem; letter-spacing: .14em; text-transform: uppercase;
    color: var(--text3); font-weight: 700;
    padding: 14px 10px 6px;
    font-family: 'Syne', sans-serif;
}
.sidebar-link {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: var(--radius-sm);
    color: var(--text2); text-decoration: none;
    font-size: .88rem; font-weight: 500;
    transition: all .15s; cursor: pointer; border: 1px solid transparent;
}
.sidebar-link:hover { background: var(--surface2); color: var(--text); }
.sidebar-link.active { background: var(--accent-dim); color: var(--accent); border-color: rgba(79,142,247,.2); }
.sidebar-link i { font-size: .95rem; flex-shrink: 0; }

/* ─── MAIN ───────────────────────────────────────────────── */
.main { flex: 1; overflow-y: auto; padding: 28px 32px; }

.page-header { margin-bottom: 28px; }
.page-header h1 {
    font-family: 'Syne', sans-serif;
    font-size: 1.55rem; font-weight: 800; color: var(--text); line-height: 1.2;
}
.page-header p { color: var(--text2); font-size: .9rem; margin-top: 4px; }

/* ─── ALERTS ─────────────────────────────────────────────── */
.alert-success {
    background: var(--green-dim); border: 1px solid rgba(16,185,129,.3);
    color: var(--green); border-radius: var(--radius-sm);
    padding: 13px 18px; font-size: .9rem;
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 22px;
    animation: slideDown .3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ─── CARD ───────────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 24px;
}
.card-title {
    font-family: 'Syne', sans-serif;
    font-size: .95rem; font-weight: 700; color: var(--text);
    margin-bottom: 20px;
    display: flex; align-items: center; gap: 8px;
}
.card-title i { color: var(--accent); }

/* ─── FORM GRID ──────────────────────────────────────────── */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px; margin-bottom: 18px;
}
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-label {
    font-size: .73rem; font-weight: 700; color: var(--text3);
    text-transform: uppercase; letter-spacing: .08em;
}
.form-select {
    background: var(--surface2); border: 1px solid var(--border);
    color: var(--text); border-radius: var(--radius-sm);
    padding: 10px 36px 10px 14px;
    font-size: .88rem; font-family: 'DM Sans', sans-serif;
    cursor: pointer; transition: all .2s; appearance: none; width: 100%;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center;
}
.form-select:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
    background-color: var(--surface3);
}
.form-select option { background: var(--surface2); }

/* ─── BUTTONS ────────────────────────────────────────────── */
.btn-group { display: flex; gap: 10px; flex-wrap: wrap; }
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 22px; border-radius: var(--radius-sm);
    font-size: .87rem; font-weight: 600;
    font-family: 'DM Sans', sans-serif;
    border: none; cursor: pointer; transition: all .2s;
    text-decoration: none; white-space: nowrap;
}
.btn-primary {
    background: var(--accent); color: #fff;
    box-shadow: 0 4px 14px rgba(79,142,247,.35);
}
.btn-primary:hover { filter: brightness(1.1); transform: translateY(-1px); }
.btn-success {
    background: var(--green); color: #fff;
    box-shadow: 0 4px 14px rgba(16,185,129,.3);
}
.btn-success:hover { filter: brightness(1.1); transform: translateY(-1px); }
.btn-ghost {
    background: var(--surface2); border: 1px solid var(--border);
    color: var(--text2);
}
.btn-ghost:hover { background: var(--surface3); color: var(--text); }
.btn-print {
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #fff;
    box-shadow: 0 4px 14px rgba(124,92,252,.35);
}
.btn-print:hover { filter: brightness(1.1); transform: translateY(-1px); }
.btn-sm { padding: 7px 16px; font-size: .82rem; }

/* ══ MARK ENTRY ══════════════════════════════════════════ */
.steps-bar {
    display: flex; align-items: center; gap: 0;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 6px;
    margin-bottom: 24px; overflow-x: auto;
}
.step-item {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 16px; border-radius: var(--radius-sm);
    font-size: .84rem; font-weight: 600; color: var(--text3);
    transition: all .2s; white-space: nowrap; flex-shrink: 0;
}
.step-item.active { background: var(--surface3); color: var(--accent); }
.step-item.done   { color: var(--green); }
.step-num {
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--surface3);
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 700; flex-shrink: 0;
}
.step-item.active .step-num { background: var(--accent); color: #fff; }
.step-item.done   .step-num { background: var(--green); color: #fff; }
.step-arrow { color: var(--text3); margin: 0 2px; font-size: .7rem; flex-shrink: 0; }

.student-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 4px;
}
.student-card {
    background: var(--surface2);
    border: 2px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px 14px;
    cursor: pointer; transition: all .2s;
    display: flex; align-items: center; gap: 12px;
    position: relative;
}
.student-card:hover { border-color: var(--accent); background: var(--surface3); }
.student-card.selected {
    border-color: var(--accent);
    background: var(--accent-dim);
    box-shadow: 0 0 0 1px var(--accent);
}
.student-card.has-marks { border-color: rgba(16,185,129,.4); }
.student-card.has-marks.selected { border-color: var(--green); background: var(--green-dim); box-shadow: 0 0 0 1px var(--green); }
.student-avatar {
    width: 38px; height: 38px; border-radius: 10px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.student-info { min-width: 0; }
.student-name {
    font-size: .85rem; font-weight: 600; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.student-sub-info { font-size: .72rem; color: var(--text3); margin-top: 2px; }
.student-check {
    position: absolute; top: 8px; right: 8px;
    width: 18px; height: 18px; border-radius: 50%;
    background: var(--accent); color: #fff;
    display: none; align-items: center; justify-content: center;
    font-size: .65rem;
}
.student-card.selected .student-check { display: flex; }

.entry-panel {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.entry-panel-header {
    background: var(--surface3);
    border-bottom: 1px solid var(--border);
    padding: 16px 20px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
}
.entry-panel-title {
    font-family: 'Syne', sans-serif;
    font-size: .95rem; font-weight: 700; color: var(--text);
    display: flex; align-items: center; gap: 10px;
}
.selected-student-badge {
    background: var(--accent-dim); border: 1px solid rgba(79,142,247,.3);
    color: var(--accent); border-radius: 20px;
    padding: 4px 12px; font-size: .8rem; font-weight: 600;
}

.subjects-list { padding: 8px 0; }
.subject-row {
    display: flex; align-items: center; gap: 16px;
    padding: 14px 20px;
    border-bottom: 1px solid rgba(30,45,69,.5);
    transition: background .15s;
}
.subject-row:last-child { border-bottom: none; }
.subject-row:hover { background: rgba(79,142,247,.04); }

.subj-icon {
    width: 36px; height: 36px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; flex-shrink: 0; font-weight: 700;
}
.subj-name {
    flex: 1; min-width: 0;
    font-size: .9rem; font-weight: 500; color: var(--text);
}
.subj-name small { display: block; font-size: .72rem; color: var(--text3); font-weight: 400; }

.score-input-wrap {
    display: flex; align-items: center; gap: 8px; flex-shrink: 0;
}
.score-input {
    width: 76px;
    background: var(--surface);
    border: 2px solid var(--border);
    color: var(--text);
    border-radius: var(--radius-xs);
    padding: 8px 10px;
    font-size: .95rem; font-weight: 600;
    font-family: 'DM Sans', sans-serif;
    text-align: center;
    transition: all .2s;
}
.score-input:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
    background: var(--surface3);
}
.score-input.scored { border-color: rgba(16,185,129,.5); }
.score-max { font-size: .78rem; color: var(--text3); white-space: nowrap; }

.grade-badge {
    width: 34px; height: 34px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; font-weight: 800; color: #fff; flex-shrink: 0;
    transition: all .2s;
    background: var(--surface3); color: var(--text3);
}

.score-bar-mini {
    width: 60px; height: 4px; background: var(--surface3);
    border-radius: 2px; overflow: hidden; flex-shrink: 0;
}
.score-bar-fill {
    height: 100%; border-radius: 2px; transition: width .3s ease;
}

.no-student-placeholder {
    text-align: center; padding: 50px 20px; color: var(--text3);
}
.no-student-placeholder .icon {
    font-size: 2.5rem; margin-bottom: 14px; display: block; opacity: .4;
}
.no-student-placeholder p { font-size: .9rem; line-height: 1.7; }

.progress-summary {
    display: flex; gap: 8px; flex-wrap: wrap;
    margin-top: 12px; padding-top: 12px;
    border-top: 1px solid var(--border);
}
.progress-chip {
    display: flex; align-items: center; gap: 6px;
    background: var(--surface3); border: 1px solid var(--border);
    border-radius: 20px; padding: 4px 10px;
    font-size: .76rem; color: var(--text2);
}
.progress-chip.complete { border-color: rgba(16,185,129,.3); color: var(--green); background: var(--green-dim); }
.progress-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

/* ─── REPORT CARD ────────────────────────────────────────── */
.report-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 28px;
    box-shadow: var(--shadow);
}
.report-header {
    background: linear-gradient(135deg, #08152e 0%, #122050 50%, #08152e 100%);
    padding: 28px 28px 22px;
    text-align: center;
    border-bottom: 2px solid var(--accent);
    position: relative; overflow: hidden;
}
.report-header::before {
    content: '';
    position: absolute; top: -40px; right: -40px;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(79,142,247,.18) 0%, transparent 70%);
}
.report-header::after {
    content: '';
    position: absolute; bottom: -30px; left: -30px;
    width: 160px; height: 160px;
    background: radial-gradient(circle, rgba(124,92,252,.12) 0%, transparent 70%);
}
.school-name { font-family: 'Syne', sans-serif; font-size: 1.5rem; font-weight: 800; color: #fff; }
.school-motto { font-size: .82rem; color: rgba(255,255,255,.6); font-style: italic; margin: 5px 0 8px; }
.school-contact { font-size: .76rem; color: rgba(255,255,255,.45); }

.report-body { padding: 24px; }
.report-meta {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 12px; margin-bottom: 22px;
}
.meta-chip {
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 11px 14px;
}
.meta-chip .lbl { font-size: .68rem; color: var(--text3); text-transform: uppercase; letter-spacing: .07em; font-weight: 700; }
.meta-chip .val { font-size: .9rem; font-weight: 600; color: var(--text); margin-top: 3px; }

.report-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
.report-table th {
    background: var(--surface2); color: var(--text2);
    font-size: .73rem; text-transform: uppercase; letter-spacing: .07em; font-weight: 700;
    padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border);
}
.report-table td {
    padding: 12px 14px; border-bottom: 1px solid rgba(30,45,69,.4);
    font-size: .88rem;
}
.report-table tr:last-child td { border-bottom: none; }
.report-table tr:hover td { background: rgba(79,142,247,.03); }
.grade-pill {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 7px;
    font-size: .8rem; font-weight: 800; color: #fff;
}
.totals-row td { font-weight: 700; background: var(--surface2); }
.remark-box {
    background: linear-gradient(135deg, rgba(79,142,247,.07), rgba(124,92,252,.07));
    border: 1px solid rgba(79,142,247,.2);
    border-radius: var(--radius-sm);
    padding: 15px 18px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 14px;
}
.newsletter-box {
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 13px 16px;
    font-size: .84rem; color: var(--text2); margin-bottom: 16px;
}

/* ─── NOTIFICATIONS ──────────────────────────────────────── */
.notif-panel {
    position: fixed; top: 64px; right: 0;
    width: 320px; height: calc(100vh - 64px);
    background: var(--surface); border-left: 1px solid var(--border);
    transform: translateX(100%); transition: transform .3s ease;
    z-index: 180; overflow-y: auto; padding: 20px;
}
.notif-panel.open { transform: translateX(0); }
.notif-header {
    font-family: 'Syne', sans-serif; font-size: .95rem; font-weight: 700;
    margin-bottom: 16px;
    display: flex; align-items: center; justify-content: space-between;
}
.notif-item {
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 12px 14px; margin-bottom: 10px;
    transition: border-color .2s;
}
.notif-item:hover { border-color: var(--accent); }
.notif-item .nt { font-weight: 600; font-size: .87rem; color: var(--text); margin-bottom: 3px; }
.notif-item .nm { font-size: .81rem; color: var(--text2); margin-bottom: 4px; }
.notif-item .ns { font-size: .71rem; color: var(--text3); }

/* ─── SIDEBAR OVERLAY ────────────────────────────────────── */
.sidebar-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); z-index: 150;
}

/* ─── EMPTY STATE ────────────────────────────────────────── */
.empty-state {
    text-align: center; padding: 48px 20px; color: var(--text3);
}
.empty-state i { font-size: 2.4rem; margin-bottom: 12px; display: block; opacity: .4; }
.empty-state p { font-size: .88rem; }

/* ─── PRINT ──────────────────────────────────────────────── */
@media print {
    .topbar, .sidebar, .no-print, .notif-panel,
    .sidebar-overlay, .steps-bar, .student-grid, .entry-panel { display: none !important; }
    .layout, .main { display: block !important; }
    body, html { background: #fff !important; color: #000 !important; }
    .report-card { border: 1px solid #ccc !important; box-shadow: none !important; page-break-after: always; }
    .report-header { background: #1a237e !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
    .report-table th, .report-table td { border: 1px solid #ddd !important; }
}

/* ─── RESPONSIVE ─────────────────────────────────────────── */
@media (max-width: 960px) {
    .sidebar {
        position: fixed; top: 64px; left: 0;
        height: calc(100vh - 64px); z-index: 160;
        transform: translateX(-100%); box-shadow: var(--shadow);
    }
    .sidebar.open { transform: translateX(0); }
    .sidebar-overlay { display: block; opacity: 0; pointer-events: none; transition: opacity .3s; }
    .sidebar-overlay.open { opacity: 1; pointer-events: all; }
    .hamburger { display: flex; }
    .teacher-chip span { display: none; }
    .main { padding: 18px 16px; }
}
@media (max-width: 600px) {
    .form-grid { grid-template-columns: 1fr; }
    .btn-group { flex-direction: column; }
    .student-grid { grid-template-columns: 1fr 1fr; }
    .subject-row { flex-wrap: wrap; gap: 10px; }
    .score-bar-mini { display: none; }
}
</style>
</head>
<body>

<!-- ══ TOPBAR ══════════════════════════════════════════════ -->
<header class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
        <button class="hamburger" id="hamburgerBtn"><i class="bi bi-list"></i></button>
        <a href="#" class="brand">
            <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <span>Little Friends</span>
        </a>
    </div>
    <div class="topbar-right">
        <div class="icon-btn" id="notifToggle" title="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($notifications && $notifications->num_rows > 0): ?>
            <span class="badge"><?= min($notifications->num_rows, 9) ?></span>
            <?php endif; ?>
        </div>
        <div class="teacher-chip">
            <div class="avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'T', 0, 1)) ?></div>
            <span><?= htmlspecialchars($_SESSION['name'] ?? 'Teacher') ?></span>
        </div>
    </div>
</header>

<div class="layout">

<!-- ══ SIDEBAR OVERLAY ════════════════════════════════════ -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ══ SIDEBAR ════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-section">Main</div>
    <a class="sidebar-link active" href="#marks-section" onclick="closeSidebar()">
        <i class="bi bi-pencil-square"></i> Mark Entry
    </a>
    <a class="sidebar-link" href="#reports-section" onclick="closeSidebar()">
        <i class="bi bi-file-earmark-bar-graph"></i> Report Cards
    </a>
    <div class="sidebar-section" style="margin-top:8px;">Account</div>
    <a class="sidebar-link" href="?logout=1" onclick="return confirm('Log out?')">        <i class="bi bi-box-arrow-right"></i> Logout
    </a>
</aside>

<!-- ══ MAIN ═══════════════════════════════════════════════ -->
<main class="main">

    <div class="page-header">
        <h1>Teacher Dashboard</h1>
        <p>Good day, <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Teacher') ?></strong> — select a class to load students and enter marks.</p>
    </div>

    <?php if (!empty($message)): ?>
    <div class="alert-success no-print">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- ── STEP 1: FILTER ─────────────────────────────── -->
    <div class="card no-print" id="marks-section">
        <div class="card-title"><i class="bi bi-sliders"></i> Step 1 — Choose Class, Term &amp; Exam</div>
        <form method="POST" id="filterForm">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Class</label>
                    <select name="class_id" class="form-select" required>
                        <option value="">— Select Class —</option>
                        <?php if ($classes): $classes->data_seek(0); while ($c = $classes->fetch_assoc()): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $class_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['class_name']) ?>
                        </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Term</label>
                    <select name="term_id" class="form-select" required>
                        <option value="">— Select Term —</option>
                        <?php if ($terms): $terms->data_seek(0); while ($t = $terms->fetch_assoc()): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $term_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['term_name']) ?>
                        </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Exam Type</label>
                    <select name="exam_type" class="form-select" required>
                        <option value="">— Select Type —</option>
                        <option value="CAT"      <?= $exam_type == 'CAT'      ? 'selected' : '' ?>>CAT</option>
                        <option value="End-term" <?= $exam_type == 'End-term' ? 'selected' : '' ?>>End-term</option>
                    </select>
                </div>
            </div>
            <div class="btn-group">
                <button type="submit" name="load_students" class="btn btn-primary">
                    <i class="bi bi-people-fill"></i> Load Students
                </button>
                <button type="submit" name="generate_reports" class="btn btn-success">
                    <i class="bi bi-file-earmark-bar-graph"></i> Generate Report Cards
                </button>
            </div>
        </form>
    </div>

    <!-- ── STEP 2+3: STUDENT SELECTOR + MARK ENTRY ──── -->
    <?php if (!empty($students) && $exam_type && !isset($_POST['generate_reports'])): ?>

    <!-- Step bar -->
    <div class="steps-bar no-print">
        <div class="step-item done">
            <div class="step-num"><i class="bi bi-check"></i></div>
            Filter Set
        </div>
        <i class="bi bi-chevron-right step-arrow"></i>
        <div class="step-item active">
            <div class="step-num">2</div>
            Select Student
        </div>
        <i class="bi bi-chevron-right step-arrow"></i>
        <div class="step-item" id="step3Label">
            <div class="step-num">3</div>
            Enter Marks
        </div>
        <i class="bi bi-chevron-right step-arrow"></i>
        <div class="step-item" id="step4Label">
            <div class="step-num">4</div>
            Save
        </div>
    </div>

    <form method="POST" id="marksForm">
        <input type="hidden" name="class_id"  value="<?= htmlspecialchars($class_id) ?>">
        <input type="hidden" name="term_id"   value="<?= htmlspecialchars($term_id) ?>">
        <input type="hidden" name="exam_type" value="<?= htmlspecialchars($exam_type) ?>">

        <!-- Step 2: Student Cards -->
        <div class="card no-print">
            <div class="card-title">
                <i class="bi bi-people"></i>
                Step 2 — Select a Student
                <span style="margin-left:auto;font-size:.78rem;color:var(--text3);font-weight:400;">
                    <?= count($students) ?> student<?= count($students) != 1 ? 's' : '' ?> in class
                </span>
            </div>

            <div class="student-grid" id="studentGrid">
                <?php foreach ($students as $idx => $s):
                    $chk = $conn->prepare("SELECT COUNT(*) as cnt FROM marks WHERE student_id=? AND exam_type=? AND term_id=?");
                    $hasMarks = false;
                    if ($chk) {
                        $chk->bind_param("isi", $s['id'], $exam_type, $term_id);
                        $chk->execute();
                        $cres = $chk->get_result()->fetch_assoc();
                        $hasMarks = ($cres['cnt'] > 0);
                        $chk->close();
                    }
                ?>
                <div class="student-card <?= $hasMarks ? 'has-marks' : '' ?>"
                     id="sc_<?= $s['id'] ?>"
                     onclick="selectStudent(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['name'])) ?>')">
                    <div class="student-avatar">
                        <?= strtoupper(substr($s['name'], 0, 1)) ?>
                    </div>
                    <div class="student-info">
                        <div class="student-name"><?= htmlspecialchars($s['name']) ?></div>
                        <div class="student-sub-info">
                            <?= $hasMarks
                                ? '<i class="bi bi-check-circle-fill" style="color:var(--green);font-size:.7rem;"></i> Has marks'
                                : '<i class="bi bi-circle" style="font-size:.7rem;"></i> No marks yet' ?>
                        </div>
                    </div>
                    <div class="student-check"><i class="bi bi-check"></i></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php
            $withMarks = 0;
            foreach ($students as $s) {
                $chk2 = $conn->prepare("SELECT COUNT(*) as cnt FROM marks WHERE student_id=? AND exam_type=? AND term_id=?");
                if ($chk2) {
                    $chk2->bind_param("isi", $s['id'], $exam_type, $term_id);
                    $chk2->execute();
                    $c2 = $chk2->get_result()->fetch_assoc();
                    if ($c2['cnt'] > 0) $withMarks++;
                    $chk2->close();
                }
            }
            ?>
            <div class="progress-summary">
                <div class="progress-chip <?= $withMarks == count($students) ? 'complete' : '' ?>">
                    <div class="progress-dot"></div>
                    <?= $withMarks ?>/<?= count($students) ?> students have marks entered
                </div>
                <div class="progress-chip">
                    <div class="progress-dot"></div>
                    <?= htmlspecialchars($exam_type) ?> — Term <?= htmlspecialchars($term_id) ?>
                </div>
            </div>
        </div>

        <!-- Step 3: Subject Mark Entry Panel -->
        <div class="card no-print">
            <div class="card-title"><i class="bi bi-journal-text"></i> Step 3 — Enter Subject Marks</div>

            <div class="entry-panel">
                <div class="entry-panel-header">
                    <div class="entry-panel-title">
                        <i class="bi bi-person-fill" style="color:var(--accent);"></i>
                        <span id="panelStudentName">No student selected</span>
                    </div>
                    <div id="panelBadge" style="display:none;" class="selected-student-badge">
                        Entering marks
                    </div>
                </div>

                <div id="noStudentMsg" class="no-student-placeholder">
                    <span class="icon"><i class="bi bi-cursor-fill"></i></span>
                    <p>Click any student card above<br>to start entering their marks.</p>
                </div>

                <div id="subjectsList" style="display:none;" class="subjects-list">
                    <?php
                    $subj_colors = [
                        ['bg'=>'rgba(79,142,247,.15)','color'=>'#4f8ef7'],
                        ['bg'=>'rgba(16,185,129,.15)','color'=>'#10b981'],
                        ['bg'=>'rgba(245,158,11,.15)','color'=>'#f59e0b'],
                        ['bg'=>'rgba(124,92,252,.15)','color'=>'#7c5cfc'],
                        ['bg'=>'rgba(239,68,68,.15)', 'color'=>'#ef4444'],
                        ['bg'=>'rgba(245,200,66,.15)','color'=>'#f5c842'],
                        ['bg'=>'rgba(20,184,166,.15)','color'=>'#14b8a6'],
                        ['bg'=>'rgba(249,115,22,.15)','color'=>'#f97316'],
                    ];
                    foreach ($subjects_arr as $si => $sub):
                        $sc = $subj_colors[$si % count($subj_colors)];
                        $initial = strtoupper(substr($sub['subject_name'], 0, 1));
                    ?>
                    <div class="subject-row" id="row_<?= $sub['id'] ?>">
                        <div class="subj-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;">
                            <?= $initial ?>
                        </div>
                        <div class="subj-name">
                            <?= htmlspecialchars($sub['subject_name']) ?>
                            <small>Max 100 marks &bull; CBE grading</small>
                        </div>
                        <div class="score-bar-mini">
                            <div class="score-bar-fill" id="bar_<?= $sub['id'] ?>" style="width:0%;background:#4f8ef7;"></div>
                        </div>
                        <div class="score-input-wrap">
                            <input type="number"
                                   class="score-input"
                                   id="inp_<?= $sub['id'] ?>"
                                   min="0" max="100"
                                   placeholder="—"
                                   oninput="onScoreInput(this, <?= $sub['id'] ?>)"
                                   onchange="onScoreInput(this, <?= $sub['id'] ?>)"
                                   readonly>
                        </div>
                        <div class="score-max" style="min-width:28px;">/100</div>
                        <div class="grade-badge" id="grade_<?= $sub['id'] ?>">—</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="hiddenInputs"></div>

            <div style="margin-top:18px;" id="saveArea" style="display:none;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <button type="submit" name="save_marks" class="btn btn-success" id="saveBtn">
                        <i class="bi bi-floppy-fill"></i> Save Marks
                    </button>
                    <span id="saveHint" style="font-size:.82rem;color:var(--text3);">
                        Select a student and enter marks to save.
                    </span>
                </div>
            </div>
        </div>

    </form>

    <?php elseif (isset($_POST['load_students']) && $class_id && empty($students)): ?>
    <div class="card no-print">
        <div class="empty-state">
            <i class="bi bi-people"></i>
            <p>No students found in the selected class.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── REPORT CARDS ──────────────────────────────────── -->
    <?php if (!empty($report_cards)): ?>
    <div id="reports-section">
        <div style="font-family:'Syne',sans-serif;font-size:1.15rem;font-weight:700;margin-bottom:22px;display:flex;align-items:center;gap:10px;" class="no-print">
            <i class="bi bi-file-earmark-text" style="color:var(--accent);"></i>
            Report Cards
            <span style="background:var(--accent);color:#fff;font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600;font-family:'DM Sans',sans-serif;">
                <?= count($report_cards) ?> student<?= count($report_cards) != 1 ? 's' : '' ?>
            </span>
        </div>

        <?php foreach ($report_cards as $r): ?>
        <div class="report-card">
            <div class="report-header">
                <div class="school-name">🎓 LITTLE FRIENDS SCHOOL</div>
                <div class="school-motto">"Strong Foundations, Brighter Futures"</div>
                <div class="school-contact">
                    <i class="bi bi-telephone"></i> 0797583976
                    &nbsp;|&nbsp;
                    <i class="bi bi-geo-alt"></i> Bomet, Sotik
                </div>
            </div>
            <div class="report-body">
                <div class="report-meta">
                    <div class="meta-chip">
                        <div class="lbl">Student</div>
                        <div class="val"><?= htmlspecialchars($r['student']['name']) ?></div>
                    </div>
                    <div class="meta-chip">
                        <div class="lbl">Class</div>
                        <div class="val"><?= htmlspecialchars($r['student']['class_name']) ?></div>
                    </div>
                    <div class="meta-chip">
                        <div class="lbl">Term</div>
                        <div class="val">Term <?= htmlspecialchars($term_id) ?></div>
                    </div>
                    <div class="meta-chip">
                        <div class="lbl">Exam</div>
                        <div class="val"><?= htmlspecialchars($exam_type) ?></div>
                    </div>
                    <div class="meta-chip">
                        <div class="lbl">Average</div>
                        <div class="val" style="color:var(--accent);"><?= number_format($r['avg'], 1) ?>%</div>
                    </div>
                </div>

                <?php if (empty($r['marks'])): ?>
                <div class="empty-state">
                    <i class="bi bi-journal-x"></i>
                    <p>No marks recorded for this student yet.</p>
                </div>
                <?php else: ?>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th style="text-align:center;">Score</th>
                            <th style="text-align:center;">Grade</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($r['marks'] as $m):
                            $gc  = gradeColor($m['grade']);
                            $pct = min(100, max(0, (int)$m['score']));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($m['subject_name']) ?></td>
                            <td style="text-align:center;">
                                <strong style="font-size:.95rem;"><?= $m['score'] ?></strong>
                                <span style="color:var(--text3);font-size:.73rem;">/100</span>
                            </td>
                            <td style="text-align:center;">
                                <span class="grade-pill" style="background:<?= $gc ?>;"><?= $m['grade'] ?></span>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="flex:1;height:5px;background:var(--surface3);border-radius:3px;overflow:hidden;max-width:100px;">
                                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $gc ?>;border-radius:3px;"></div>
                                    </div>
                                    <span style="font-size:.73rem;color:var(--text3);min-width:28px;"><?= $pct ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="totals-row">
                            <td>Total</td>
                            <td style="text-align:center;" colspan="3">
                                <?= $r['total'] ?> / <?= $r['count'] * 100 ?>
                            </td>
                        </tr>
                        <tr class="totals-row">
                            <td>Average</td>
                            <td style="text-align:center;" colspan="3">
                                <span style="color:var(--accent);font-size:.95rem;"><?= number_format($r['avg'], 1) ?>%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>

                <div class="remark-box">
                    <span style="font-size:1.5rem;"><?= $r['emoji'] ?></span>
                    <div>
                        <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:2px;">Teacher's Remark</div>
                        <div style="font-size:.9rem;font-weight:500;color:var(--text);"><?= htmlspecialchars($r['remark']) ?></div>
                    </div>
                </div>

                <?php if (!empty($newsletter['content'])): ?>
                <div class="newsletter-box">
                    <strong><i class="bi bi-newspaper"></i> School Newsletter:</strong>
                    <span style="margin-left:8px;"><?= htmlspecialchars($newsletter['content']) ?></span>
                </div>
                <?php endif; ?>

                <div class="no-print">
                    <button class="btn btn-print" onclick="window.print()">
                        <i class="bi bi-printer-fill"></i> Print This Report
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>
</div><!-- /layout -->

<!-- ══ NOTIFICATIONS PANEL ════════════════════════════════ -->
<div class="notif-panel" id="notifPanel">
    <div class="notif-header">
        <span><i class="bi bi-bell-fill" style="color:var(--accent);margin-right:6px;"></i> Notifications</span>
        <button onclick="document.getElementById('notifPanel').classList.remove('open')"
            style="background:none;border:none;color:var(--text2);cursor:pointer;font-size:1.1rem;">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <?php if ($notifications && $notifications->num_rows > 0):
        $notifications->data_seek(0);
        while ($n = $notifications->fetch_assoc()): ?>
    <div class="notif-item">
        <div class="nt"><i class="bi bi-megaphone" style="color:var(--accent);margin-right:4px;font-size:.8rem;"></i>
            <?= htmlspecialchars($n['title'] ?? 'Notification') ?>
        </div>
        <div class="nm"><?= htmlspecialchars($n['message'] ?? '') ?></div>
        <div class="ns"><i class="bi bi-clock"></i> <?= htmlspecialchars($n['created_at'] ?? '') ?></div>
    </div>
    <?php endwhile; else: ?>
    <div class="empty-state">
        <i class="bi bi-bell-slash"></i>
        <p>No notifications yet.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ══ JS ══════════════════════════════════════════════════ -->
<script>
const existingMarks = {};

<?php
if ($class_id && $term_id && $exam_type && !empty($students)) {
    foreach ($students as $s) {
        $stmt2 = $conn->prepare("SELECT subject_id, score FROM marks WHERE student_id=? AND exam_type=? AND term_id=?");
        if ($stmt2) {
            $stmt2->bind_param("isi", $s['id'], $exam_type, $term_id);
            $stmt2->execute();
            $mres2 = $stmt2->get_result();
            $studentMarks = [];
            while ($row2 = $mres2->fetch_assoc()) {
                $studentMarks[$row2['subject_id']] = $row2['score'];
            }
            $stmt2->close();
            if (!empty($studentMarks)) {
                echo "existingMarks[" . (int)$s['id'] . "] = " . json_encode($studentMarks) . ";\n";
            }
        }
    }
}
?>

let currentStudentId   = null;
let currentStudentName = null;
const marksData = {};

function calcGrade(score) {
    if (score === '' || score === null || isNaN(score)) return '—';
    score = parseInt(score);
    if (score >= 80) return 'A';
    if (score >= 65) return 'B';
    if (score >= 50) return 'C';
    if (score >= 35) return 'D';
    return 'E';
}
function gradeColor(g) {
    return {A:'#10b981',B:'#3b82f6',C:'#f59e0b',D:'#f97316',E:'#ef4444'}[g] || '#526070';
}

function selectStudent(id, name) {
    if (currentStudentId) saveCurrentInputs();
    currentStudentId   = id;
    currentStudentName = name;

    document.querySelectorAll('.student-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById('sc_' + id);
    if (card) card.classList.add('selected');

    document.getElementById('panelStudentName').textContent = name;
    document.getElementById('panelBadge').style.display = '';
    document.getElementById('noStudentMsg').style.display = 'none';
    document.getElementById('subjectsList').style.display = '';
    document.getElementById('saveArea').style.display = '';
    document.getElementById('saveHint').textContent = 'Marks will be saved for: ' + name;
    document.getElementById('step3Label').classList.add('active');

    const existing = existingMarks[id] || {};
    const entered  = marksData[id]     || {};

    document.querySelectorAll('.score-input').forEach(inp => {
        const sid = parseInt(inp.id.replace('inp_', ''));
        inp.removeAttribute('readonly');
        const val = (entered[sid] !== undefined) ? entered[sid]
                  : (existing[sid] !== undefined) ? existing[sid]
                  : '';
        inp.value = val;
        inp.classList.toggle('scored', val !== '');
        updateGrade(sid, val);
    });

    rebuildHiddenInputs();
    document.querySelector('.entry-panel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function saveCurrentInputs() {
    if (!currentStudentId) return;
    if (!marksData[currentStudentId]) marksData[currentStudentId] = {};
    document.querySelectorAll('.score-input').forEach(inp => {
        const sid = parseInt(inp.id.replace('inp_', ''));
        marksData[currentStudentId][sid] = inp.value;
    });
}

function onScoreInput(inp, subjectId) {
    let val = inp.value;
    if (val !== '') {
        val = Math.max(0, Math.min(100, parseInt(val) || 0));
        inp.value = val;
    }
    inp.classList.toggle('scored', val !== '');
    updateGrade(subjectId, val);
    if (currentStudentId) {
        if (!marksData[currentStudentId]) marksData[currentStudentId] = {};
        marksData[currentStudentId][subjectId] = val;
    }
    rebuildHiddenInputs();
    updateStep4();
}

function updateGrade(subjectId, score) {
    const gEl  = document.getElementById('grade_' + subjectId);
    const barEl = document.getElementById('bar_' + subjectId);
    if (!gEl) return;
    const g = calcGrade(score);
    const c = gradeColor(g);
    gEl.textContent = g === '—' ? '—' : g;
    gEl.style.background = g === '—' ? 'var(--surface3)' : c;
    gEl.style.color = g === '—' ? 'var(--text3)' : '#fff';
    if (barEl) {
        const pct = (score !== '' && !isNaN(score)) ? Math.min(100, parseInt(score)) : 0;
        barEl.style.width = pct + '%';
        barEl.style.background = c;
    }
}

function rebuildHiddenInputs() {
    const container = document.getElementById('hiddenInputs');
    container.innerHTML = '';
    for (const [studentId, subjects] of Object.entries(marksData)) {
        for (const [subjectId, score] of Object.entries(subjects)) {
            if (score === '' || score === null) continue;
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = `marks[${studentId}][${subjectId}]`;
            inp.value = score;
            container.appendChild(inp);
        }
    }
    if (currentStudentId) {
        document.querySelectorAll('.score-input').forEach(inp => {
            if (inp.value === '') return;
            const sid = inp.id.replace('inp_', '');
            const existing = container.querySelector(`input[name="marks[${currentStudentId}][${sid}]"]`);
            if (existing) existing.remove();
            const h = document.createElement('input');
            h.type  = 'hidden';
            h.name  = `marks[${currentStudentId}][${sid}]`;
            h.value = inp.value;
            container.appendChild(h);
        });
    }
}

function updateStep4() {
    const step4 = document.getElementById('step4Label');
    if (!step4) return;
    let total = 0;
    document.querySelectorAll('.score-input').forEach(i => { if (i.value !== '') total++; });
    if (total > 0) step4.classList.add('active');
}

// Sidebar
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
document.getElementById('hamburgerBtn').addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
});
overlay.addEventListener('click', closeSidebar);
function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
}

// Notifications
document.getElementById('notifToggle').addEventListener('click', () => {
    document.getElementById('notifPanel').classList.toggle('open');
});
document.addEventListener('click', e => {
    const panel = document.getElementById('notifPanel');
    if (!panel.contains(e.target) && !document.getElementById('notifToggle').contains(e.target))
        panel.classList.remove('open');
});

document.querySelectorAll('.sidebar-link').forEach(link => {
    link.addEventListener('click', function () {
        document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
        this.classList.add('active');
    });
});

document.getElementById('marksForm')?.addEventListener('submit', function () {
    saveCurrentInputs();
    rebuildHiddenInputs();
});
</script>
</body>
</html>