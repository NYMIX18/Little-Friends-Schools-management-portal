<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include("../config/db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

function esc($conn, $val) {
    return mysqli_real_escape_string($conn, trim($val));
}

/* ======================
   GENERATE USERNAME
   Format: Initials + last 4 digits of phone
   e.g. Jane Mwangi + 0712345678 → JM5678
====================== */
function generateUsername($conn, $name, $phone) {
    $parts    = array_filter(explode(' ', trim($name)));
    $initials = '';
    foreach ($parts as $p) {
        if (!empty($p)) $initials .= strtoupper($p[0]);
    }
    $digits = preg_replace('/\D/', '', $phone);
    $last4  = strlen($digits) >= 4 ? substr($digits, -4) : str_pad($digits, 4, '0', STR_PAD_LEFT);
    $base   = $initials . $last4;
    $username = $base;
    $suffix   = 1;
    while (true) {
        $u_esc = mysqli_real_escape_string($conn, $username);
        $chk   = mysqli_query($conn, "SELECT id FROM users WHERE username='$u_esc' LIMIT 1");
        if (!$chk || mysqli_num_rows($chk) === 0) break;
        $username = $base . $suffix++;
    }
    return $username;
}

/* ======================
   ADD TEACHER
====================== */
if (isset($_POST['save_teacher'])) {
    $name          = esc($conn, $_POST['name']);
    $email         = esc($conn, $_POST['email']);
    $raw_password  = trim($_POST['password']);
    $password      = password_hash($raw_password, PASSWORD_DEFAULT);
    $certification = esc($conn, $_POST['certification']);
    $salary        = floatval($_POST['salary']);
    $phone         = esc($conn, $_POST['phone']);

    // Generate username
    $username   = generateUsername($conn, $_POST['name'], $_POST['phone']);
    $username_e = mysqli_real_escape_string($conn, $username);

    // Check if email already exists
    $check = mysqli_query($conn, "SELECT id FROM users WHERE email='$email'");
    if (mysqli_num_rows($check) > 0) {
        $_SESSION['message']      = "Email already exists. Use a different email.";
        $_SESSION['message_type'] = "error";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Insert user with username
    mysqli_query($conn, "INSERT INTO users(name, username, email, password, role)
        VALUES('$name','$username_e','$email','$password','teacher')");
    $user_id = mysqli_insert_id($conn);

    // Insert teacher record
    mysqli_query($conn, "INSERT INTO teachers(user_id, certification, basic_salary, phone)
        VALUES('$user_id','$certification','$salary','$phone')");

    $_SESSION['message']      = "Teacher <strong>$name</strong> added successfully! Username: <strong>$username</strong>";
    $_SESSION['message_type'] = "success";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ======================
   CHANGE TEACHER PASSWORD
====================== */
if (isset($_POST['change_password'])) {
    $teacher_user_id = intval($_POST['teacher_user_id']);
    $new_password    = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if (empty($new_password) || strlen($new_password) < 6) {
        $_SESSION['message']      = "Password must be at least 6 characters.";
        $_SESSION['message_type'] = "error";
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['message']      = "Passwords do not match.";
        $_SESSION['message_type'] = "error";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE users SET password='$hashed' WHERE id=$teacher_user_id AND role='teacher'");
        $_SESSION['message']      = "Password updated successfully.";
        $_SESSION['message_type'] = "success";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "#pwd-section");
    exit();
}

/* ======================
   RECORD MONTHLY SALARY PAYMENT
====================== */
if (isset($_POST['pay_salary'])) {
    $teacher_id = intval($_POST['teacher_id']);
    $amount     = floatval($_POST['amount']);
    $method     = esc($conn, $_POST['method']);
    $month_year = esc($conn, $_POST['month_year']);

    $check = mysqli_query($conn, "SELECT id FROM teacher_salary_payments
        WHERE teacher_id='$teacher_id' AND month_year='$month_year'");

    if (mysqli_num_rows($check) > 0) {
        $_SESSION['message']      = "Payment for $month_year already recorded for this teacher.";
        $_SESSION['message_type'] = "error";
    } else {
        mysqli_query($conn, "INSERT INTO teacher_salary_payments
            (teacher_id, amount_paid, payment_date, method, month_year)
            VALUES('$teacher_id','$amount',CURDATE(),'$method','$month_year')");
        $_SESSION['message']      = "Payment of KES " . number_format($amount) . " recorded for $month_year.";
        $_SESSION['message_type'] = "success";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ======================
   DELETE TEACHER
====================== */
if (isset($_POST['delete_teacher'])) {
    $tid = intval($_POST['delete_teacher_id']);
    // Get user_id first
    $res = mysqli_query($conn, "SELECT user_id FROM teachers WHERE id=$tid LIMIT 1");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $uid = intval($row['user_id']);
        mysqli_query($conn, "DELETE FROM teacher_salary_payments WHERE teacher_id=$tid");
        mysqli_query($conn, "DELETE FROM teachers WHERE id=$tid");
        mysqli_query($conn, "DELETE FROM users WHERE id=$uid AND role='teacher'");
        $_SESSION['message']      = "Teacher removed successfully.";
        $_SESSION['message_type'] = "success";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/* ======================
   FETCH TEACHERS
====================== */
$current_month       = date('Y-m');
$current_month_label = date('F Y');

$sql = "
SELECT
    u.name, u.email, u.username, u.id AS user_id,
    t.id, t.phone, t.certification, t.basic_salary,
    IFNULL(SUM(p.amount_paid),0) AS total_paid,
    COUNT(p.id) AS months_paid
FROM teachers t
JOIN users u ON u.id = t.user_id
LEFT JOIN teacher_salary_payments p ON p.teacher_id = t.id
GROUP BY t.id
ORDER BY t.id DESC
";

$result   = mysqli_query($conn, $sql);
$teachers = [];
$total_salary = 0;
$total_paid   = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $mid = $row['id'];
    $cm  = mysqli_real_escape_string($conn, $current_month);
    $chk = mysqli_query($conn, "SELECT id, amount_paid, method FROM teacher_salary_payments
        WHERE teacher_id='$mid' AND month_year='$cm' LIMIT 1");
    $row['this_month_paid'] = (mysqli_num_rows($chk) > 0);
    if ($row['this_month_paid']) {
        $mp = mysqli_fetch_assoc($chk);
        $row['this_month_amount'] = $mp['amount_paid'];
        $row['this_month_method'] = $mp['method'];
    } else {
        $row['this_month_amount'] = 0;
        $row['this_month_method'] = '';
    }

    $txns = mysqli_query($conn, "SELECT amount_paid, payment_date, method, month_year
        FROM teacher_salary_payments WHERE teacher_id='$mid'
        ORDER BY payment_date DESC LIMIT 6");
    $row['transactions'] = [];
    while ($t = mysqli_fetch_assoc($txns)) $row['transactions'][] = $t;

    $teachers[]    = $row;
    $total_salary += $row['basic_salary'];
    $total_paid   += $row['total_paid'];
}

$unpaid_count = 0;
foreach ($teachers as $t) { if (!$t['this_month_paid']) $unpaid_count++; }

function initials($name) {
    $parts = explode(' ', trim($name));
    $ini   = '';
    foreach ($parts as $p) { if (!empty($p)) $ini .= strtoupper($p[0]); }
    return substr($ini, 0, 2);
}

$avatar_palette = ['#1A6B4A','#1A4580','#6B2F8A','#9B2335','#B56A00','#1A5C8A','#3D6B1A'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teachers · SchoolDesk Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ─── RESET & TOKENS ─── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    /* Brand */
    --ink:        #0B1523;
    --ink-2:      #2C3E52;
    --ink-3:      #7A8FA0;
    --bg:         #EEF1F5;
    --surface:    #FFFFFF;
    --surface-2:  #F6F8FB;
    --border:     rgba(0,0,0,.07);
    --border-md:  rgba(0,0,0,.12);

    /* Accent — deep teal */
    --teal:       #0D9B72;
    --teal-dark:  #087A5A;
    --teal-glow:  rgba(13,155,114,.15);
    --teal-light: #E3F7F1;

    /* States */
    --amber:      #E09B10;
    --amber-lt:   #FFF4D4;
    --red:        #D94F4F;
    --red-lt:     #FDEAEA;
    --blue:       #2E6FD9;
    --blue-lt:    #EAF0FD;

    /* Shape */
    --r-xs: 6px;
    --r-sm: 10px;
    --r-md: 16px;
    --r-lg: 22px;
    --r-xl: 30px;

    /* Shadow */
    --sh-sm: 0 2px 8px rgba(0,0,0,.06);
    --sh-md: 0 8px 30px rgba(0,0,0,.10);
    --sh-teal: 0 6px 24px rgba(13,155,114,.28);
}

html { font-size: 15px; scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--ink);
    min-height: 100vh;
    line-height: 1.6;
}

/* ─── SCROLLBAR ─── */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--teal); border-radius: 99px; }

/* ══════════════════════════════════
   TOPNAV
══════════════════════════════════ */
.topnav {
    position: sticky;
    top: 0;
    z-index: 300;
    height: 60px;
    background: var(--ink);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1.8rem;
    box-shadow: 0 2px 20px rgba(0,0,0,.25);
}

.nav-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
}

.nav-logo-icon {
    width: 34px;
    height: 34px;
    background: var(--teal);
    border-radius: var(--r-xs);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: 14px;
    color: #fff;
    letter-spacing: -.5px;
    flex-shrink: 0;
}

.nav-logo-text {
    font-family: 'Syne', sans-serif;
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -.3px;
}

.nav-logo-text span { color: var(--teal); }

.nav-right { display: flex; align-items: center; gap: 12px; }

.nav-pill {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 5px 14px;
    border-radius: 99px;
    background: rgba(13,155,114,.15);
    color: var(--teal);
    border: 1px solid rgba(13,155,114,.3);
}

.nav-user {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--teal);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Syne', sans-serif;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
}

/* ══════════════════════════════════
   HERO BANNER
══════════════════════════════════ */
.hero {
    background: var(--ink);
    padding: 2.2rem 1.8rem 2rem;
    position: relative;
    overflow: hidden;
}

.hero::after {
    content: 'TEACHERS';
    position: absolute;
    right: -10px;
    top: 50%;
    transform: translateY(-50%);
    font-family: 'Syne', sans-serif;
    font-size: 7rem;
    font-weight: 800;
    color: rgba(255,255,255,.03);
    letter-spacing: -4px;
    pointer-events: none;
    user-select: none;
}

.hero-glow {
    position: absolute;
    width: 300px;
    height: 300px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(13,155,114,.2) 0%, transparent 70%);
    top: -80px;
    right: 15%;
    pointer-events: none;
}

.hero-inner {
    max-width: 820px;
    margin: 0 auto;
    position: relative;
}

.hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .15em;
    text-transform: uppercase;
    color: var(--teal);
    margin-bottom: 10px;
}

.hero-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--teal);
    animation: blink 1.8s infinite;
}

@keyframes blink {
    0%,100% { opacity: 1; transform: scale(1); }
    50%      { opacity: .4; transform: scale(1.5); }
}

.hero h1 {
    font-family: 'Syne', sans-serif;
    font-size: clamp(1.8rem, 4vw, 2.6rem);
    font-weight: 800;
    color: #fff;
    line-height: 1.15;
    letter-spacing: -.5px;
    margin-bottom: 6px;
}

.hero h1 em {
    font-style: normal;
    color: var(--teal);
}

.hero-sub {
    font-size: 14px;
    color: rgba(255,255,255,.45);
    margin-bottom: 1.4rem;
}

.hero-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.hero-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12.5px;
    font-weight: 500;
    padding: 6px 14px;
    border-radius: 99px;
    border: 1px solid;
}

.chip-teal   { background: rgba(13,155,114,.12); color: var(--teal);   border-color: rgba(13,155,114,.25); }
.chip-amber  { background: rgba(224,155,16,.12); color: var(--amber);  border-color: rgba(224,155,16,.25); }
.chip-red    { background: rgba(217,79,79,.12);  color: var(--red);    border-color: rgba(217,79,79,.25); }
.chip-white  { background: rgba(255,255,255,.07); color: rgba(255,255,255,.6); border-color: rgba(255,255,255,.12); }

/* ══════════════════════════════════
   ALERT STRIP
══════════════════════════════════ */
.alert-strip {
    padding: 12px 1.8rem;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 600;
}

.alert-strip.danger {
    background: linear-gradient(90deg, #6B1414, #A83232);
    color: #fff;
    border-left: 4px solid #FF8080;
}

.alert-strip.ok {
    background: linear-gradient(90deg, #0A4A34, #0D7A56);
    color: #fff;
    border-left: 4px solid var(--teal);
}

.alert-strip-icon { font-size: 18px; flex-shrink: 0; }
.alert-strip-body {}
.alert-strip-title { font-size: 13.5px; }
.alert-strip-desc  { font-size: 12px; opacity: .75; font-weight: 400; margin-top: 1px; }

/* ══════════════════════════════════
   PAGE LAYOUT
══════════════════════════════════ */
.page {
    max-width: 820px;
    margin: 0 auto;
    padding: 1.6rem 1rem 4rem;
}

/* ══════════════════════════════════
   FLASH
══════════════════════════════════ */
.flash {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px 16px;
    border-radius: var(--r-md);
    font-size: 13.5px;
    font-weight: 500;
    margin-bottom: 1.4rem;
    animation: slideIn .25s ease;
    line-height: 1.5;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

.flash.success { background: var(--teal-light); color: var(--teal-dark); border: 1px solid rgba(13,155,114,.25); }
.flash.error   { background: var(--red-lt);     color: var(--red);       border: 1px solid rgba(217,79,79,.2); }
.flash-icon    { font-size: 16px; flex-shrink: 0; margin-top: 1px; }

/* ══════════════════════════════════
   STATS ROW
══════════════════════════════════ */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 1.6rem;
}

.stat-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    padding: 1.1rem 1.2rem;
    position: relative;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s;
    cursor: default;
}

.stat-box:hover { transform: translateY(-3px); box-shadow: var(--sh-md); }

.stat-box::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: var(--r-lg) var(--r-lg) 0 0;
}

.sb-ink::before    { background: var(--ink-2); }
.sb-teal::before   { background: var(--teal); }
.sb-amber::before  { background: var(--amber); }
.sb-red::before    { background: var(--red); }

.stat-lbl {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-bottom: 8px;
}

.stat-val {
    font-family: 'Syne', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1;
    color: var(--ink);
}

.stat-val.c-teal  { color: var(--teal-dark); }
.stat-val.c-amber { color: var(--amber); }
.stat-val.c-red   { color: var(--red); }

.stat-hint {
    font-size: 11px;
    color: var(--ink-3);
    margin-top: 5px;
}

/* ══════════════════════════════════
   SECTION HEADER
══════════════════════════════════ */
.sec-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.1rem;
    flex-wrap: wrap;
    gap: 8px;
}

.sec-title {
    font-family: 'Syne', sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 8px;
}

.badge-count {
    font-family: 'DM Sans', sans-serif;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 9px;
    background: var(--surface);
    border: 1px solid var(--border-md);
    border-radius: 99px;
    color: var(--ink-2);
}

/* ══════════════════════════════════
   BUTTONS
══════════════════════════════════ */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: var(--r-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all .15s ease;
    text-decoration: none;
    white-space: nowrap;
    line-height: 1;
}

.btn svg { width: 14px; height: 14px; flex-shrink: 0; }

.btn-primary   { background: var(--teal);   color: #fff; box-shadow: var(--sh-teal); }
.btn-primary:hover { background: var(--teal-dark); transform: translateY(-1px); }

.btn-outline   { background: var(--surface); color: var(--ink-2); border: 1px solid var(--border-md); }
.btn-outline:hover { background: var(--bg); }

.btn-ghost     { background: transparent; color: var(--ink-3); border: 1px solid var(--border); padding: 7px 12px; }
.btn-ghost:hover { background: var(--surface); color: var(--ink-2); }

.btn-danger    { background: var(--red-lt); color: var(--red); border: 1px solid rgba(217,79,79,.2); }
.btn-danger:hover { background: #fbd5d5; }

.btn-pay {
    background: var(--ink);
    color: #fff;
    padding: 9px 16px;
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 600;
    border-radius: var(--r-sm);
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s;
    white-space: nowrap;
    flex-shrink: 0;
}
.btn-pay:hover { background: #1A2E40; }

.btn-sms {
    background: var(--blue-lt);
    color: var(--blue);
    border: 1px solid rgba(46,111,217,.2);
    padding: 9px 14px;
    font-size: 13px;
    font-weight: 600;
    border-radius: var(--r-sm);
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s;
    text-decoration: none;
    flex-shrink: 0;
}
.btn-sms:hover { background: #d5e4fb; }

/* ══════════════════════════════════
   ADD TEACHER FORM CARD
══════════════════════════════════ */
.form-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    padding: 1.5rem 1.6rem;
    margin-bottom: 1.4rem;
    display: none;
    box-shadow: var(--sh-md);
    animation: slideIn .22s ease;
}

.form-card.open { display: block; }

.form-card-title {
    font-family: 'Syne', sans-serif;
    font-size: 16px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 1.2rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-card-title .accent-bar {
    width: 4px;
    height: 20px;
    background: var(--teal);
    border-radius: 2px;
    flex-shrink: 0;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.field { display: flex; flex-direction: column; gap: 5px; }
.field.full { grid-column: 1 / -1; }

.field label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.field input,
.field textarea,
.field select {
    padding: 10px 13px;
    border: 1.5px solid var(--border-md);
    border-radius: var(--r-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--ink);
    background: var(--surface-2);
    transition: border-color .15s, background .15s, box-shadow .15s;
    width: 100%;
}

.field input:focus,
.field textarea:focus,
.field select:focus {
    outline: none;
    border-color: var(--teal);
    background: #fff;
    box-shadow: 0 0 0 3px var(--teal-glow);
}

.field textarea { resize: vertical; min-height: 72px; }

.form-actions { display: flex; gap: 8px; margin-top: 1.3rem; flex-wrap: wrap; }

/* ══════════════════════════════════
   PASSWORD CHANGE SECTION
══════════════════════════════════ */
.pwd-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    overflow: hidden;
    margin-bottom: 1.6rem;
    box-shadow: var(--sh-sm);
}

.pwd-section-header {
    padding: 1.1rem 1.4rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--surface-2);
    cursor: pointer;
    user-select: none;
    transition: background .15s;
}

.pwd-section-header:hover { background: #EDEFEF; }

.pwd-section-title {
    font-family: 'Syne', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 9px;
}

.pwd-section-icon {
    width: 30px;
    height: 30px;
    border-radius: var(--r-xs);
    background: var(--amber-lt);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.pwd-chevron {
    color: var(--ink-3);
    transition: transform .25s;
}

.pwd-chevron.open { transform: rotate(180deg); }

.pwd-body {
    display: none;
    padding: 1.2rem 1.4rem;
    animation: slideIn .2s ease;
}

.pwd-body.open { display: block; }

.pwd-teacher-select {
    width: 100%;
    padding: 10px 13px;
    border: 1.5px solid var(--border-md);
    border-radius: var(--r-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--ink);
    background: var(--surface-2);
    margin-bottom: 14px;
    transition: border-color .15s, background .15s, box-shadow .15s;
}

.pwd-teacher-select:focus {
    outline: none;
    border-color: var(--teal);
    background: #fff;
    box-shadow: 0 0 0 3px var(--teal-glow);
}

.pwd-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}

.pwd-grid .field label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--ink-3);
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 5px;
}

.input-wrap { position: relative; }
.input-wrap input { padding-right: 42px; }

.toggle-pw {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--ink-3);
    font-size: 13px;
    padding: 0;
    display: flex;
    align-items: center;
    transition: color .15s;
}
.toggle-pw:hover { color: var(--teal); }

.pwd-strength {
    height: 4px;
    border-radius: 2px;
    background: var(--border-md);
    margin-top: 6px;
    overflow: hidden;
}

.pwd-strength-fill {
    height: 100%;
    border-radius: 2px;
    transition: width .3s, background .3s;
}

.pwd-hint {
    font-size: 11px;
    color: var(--ink-3);
    margin-top: 4px;
}

/* ══════════════════════════════════
   TEACHER CARDS
══════════════════════════════════ */
.teacher-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    margin-bottom: 1rem;
    overflow: hidden;
    transition: box-shadow .2s, transform .2s;
}

.teacher-card:hover { box-shadow: var(--sh-md); transform: translateY(-2px); }

/* Card Header */
.tc-head {
    padding: 1.1rem 1.35rem;
    display: flex;
    align-items: center;
    gap: 14px;
    border-bottom: 1px solid var(--border);
    position: relative;
}

.tc-head::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 4px;
    border-radius: 0 2px 2px 0;
}

.tc-head.paid::before   { background: var(--teal); }
.tc-head.unpaid::before { background: var(--red); }

.avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Syne', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,.2);
}

.tc-info { flex: 1; min-width: 0; }

.tc-name {
    font-family: 'Syne', sans-serif;
    font-size: 15px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -.2px;
}

.tc-meta-line {
    font-size: 12px;
    color: var(--ink-3);
    margin-top: 3px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.tc-meta-line .sep { opacity: .35; }

.username-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 99px;
    background: rgba(13,155,114,.1);
    color: var(--teal-dark);
    border: 1px solid rgba(13,155,114,.2);
    letter-spacing: .03em;
    font-family: 'DM Mono', monospace, 'DM Sans', sans-serif;
}

.month-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    padding: 5px 12px;
    border-radius: 99px;
    flex-shrink: 0;
}

.month-badge.paid   { background: var(--teal-light); color: var(--teal-dark); border: 1px solid rgba(13,155,114,.2); }
.month-badge.unpaid { background: var(--red-lt);     color: var(--red);       border: 1px solid rgba(217,79,79,.2); }

/* Card Body */
.tc-body { padding: 1.1rem 1.35rem; }

.tc-certs {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 12px;
}

.cert-chip {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 10px;
    background: var(--blue-lt);
    color: var(--blue);
    border-radius: 99px;
    border: 1px solid rgba(46,111,217,.15);
}

.tc-stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 14px;
}

.ts-item {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--r-sm);
    padding: 9px 11px;
}

.ts-lbl {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-bottom: 4px;
}

.ts-val {
    font-family: 'Syne', sans-serif;
    font-size: 14px;
    font-weight: 700;
    color: var(--ink);
}

.ts-val.c-teal  { color: var(--teal-dark); }
.ts-val.c-red   { color: var(--red); }
.ts-val.c-amber { color: var(--amber); }

/* Progress */
.prog-wrap { margin-bottom: 14px; }

.prog-header {
    display: flex;
    justify-content: space-between;
    font-size: 11.5px;
    color: var(--ink-3);
    margin-bottom: 6px;
}

.prog-pct { font-weight: 700; color: var(--ink-2); }

.prog-track {
    height: 6px;
    background: var(--bg);
    border-radius: 3px;
    overflow: hidden;
}

.prog-fill {
    height: 100%;
    border-radius: 3px;
    background: linear-gradient(90deg, var(--teal-dark), var(--teal));
    transition: width .7s cubic-bezier(.4,0,.2,1);
}

/* Transactions */
.txn-toggle {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ink-2);
    cursor: pointer;
    padding: 6px 12px;
    border-radius: var(--r-xs);
    background: var(--surface-2);
    border: 1px solid var(--border-md);
    transition: all .15s;
    user-select: none;
    margin-bottom: 10px;
}

.txn-toggle:hover { background: var(--bg); }
.txn-toggle svg   { width: 13px; height: 13px; transition: transform .2s; }
.txn-toggle.open svg { transform: rotate(90deg); }

.txn-table {
    width: 100%;
    border-collapse: collapse;
    display: none;
    margin-bottom: 14px;
    animation: fadeIn .2s ease;
    border-radius: var(--r-sm);
    overflow: hidden;
    border: 1px solid var(--border);
}

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.txn-table.open { display: table; }

.txn-table th {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--ink-3);
    padding: 8px 12px;
    text-align: left;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
}

.txn-table td {
    font-size: 13px;
    padding: 9px 12px;
    border-bottom: 1px solid var(--border);
    color: var(--ink-2);
}

.txn-table tr:last-child td { border-bottom: none; }
.txn-table tr:hover td { background: var(--surface-2); }

.txn-amt { font-weight: 700; color: var(--teal-dark); font-family: 'Syne', sans-serif; font-size: 13px; }
.txn-mon { font-weight: 600; color: var(--ink); }

.method-tag {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 99px;
}

.mt-cash  { background: var(--teal-light); color: var(--teal-dark); }
.mt-mpesa { background: var(--amber-lt);   color: #7A5200; }
.mt-bank  { background: var(--blue-lt);    color: var(--blue); }

.no-txns { font-size: 13px; color: var(--ink-3); font-style: italic; padding: 10px 0; }

/* Divider */
.divider { height: 1px; background: var(--border); margin: 14px 0; }

/* Pay row */
.pay-lbl {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-bottom: 8px;
}

.pay-row {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    align-items: center;
}

.pay-row input,
.pay-row select {
    padding: 9px 12px;
    border: 1.5px solid var(--border-md);
    border-radius: var(--r-sm);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    color: var(--ink);
    background: var(--surface-2);
    transition: border-color .15s, background .15s, box-shadow .15s;
}

.pay-row input[type="month"] { min-width: 140px; flex-shrink: 0; }
.pay-row input[type="number"] { flex: 1; min-width: 90px; }
.pay-row select { flex-shrink: 0; }

.pay-row input:focus,
.pay-row select:focus {
    outline: none;
    border-color: var(--teal);
    background: #fff;
    box-shadow: 0 0 0 3px var(--teal-glow);
}

/* Delete confirm */
.delete-row {
    display: flex;
    justify-content: flex-end;
    margin-top: 10px;
}

/* ══════════════════════════════════
   EMPTY STATE
══════════════════════════════════ */
.empty-state {
    text-align: center;
    padding: 4rem 1rem;
    color: var(--ink-3);
}

.empty-state svg { opacity: .3; margin-bottom: 1rem; }
.empty-state p   { font-size: 14px; }

/* ══════════════════════════════════
   RESPONSIVE
══════════════════════════════════ */
@media (max-width: 640px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .form-grid  { grid-template-columns: 1fr; }
    .tc-stats-row { grid-template-columns: 1fr 1fr; }
    .pwd-grid   { grid-template-columns: 1fr; }
    .pay-row    { flex-direction: column; align-items: stretch; }
    .pay-row input,
    .pay-row select,
    .btn-pay,
    .btn-sms    { width: 100%; justify-content: center; }
    .stat-val   { font-size: 1.25rem; }
    .hero h1    { font-size: 1.7rem; }
}

@media (max-width: 400px) {
    .tc-stats-row { grid-template-columns: 1fr; }
    .stats-row .stat-box:last-child { grid-column: 1 / -1; }
}
</style>
</head>
<body>

<!-- ══ TOPNAV ══ -->
<nav class="topnav">
    <a href="../dashboards/admin.php" class="nav-logo">
        <div class="nav-logo-icon">SD</div>
        <span class="nav-logo-text">School<span>Desk</span></span>
    </a>
    <div class="nav-right">
        <span class="nav-pill">Admin Panel</span>
        <div class="nav-user" title="<?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?>">
            <?= strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1)) ?>
        </div>
    </div>
</nav>

<!-- ══ HERO ══ -->
<div class="hero">
    <div class="hero-glow"></div>
    <div class="hero-inner">
        <div class="hero-eyebrow">
            <div class="hero-dot"></div>
            Teacher Management
        </div>
        <h1>Staff &amp; <em>Payroll</em> Panel</h1>
        <p class="hero-sub">Add teachers, manage salaries, reset passwords — all in one place.</p>
        <div class="hero-chips">
            <span class="hero-chip chip-white">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <?= count($teachers) ?> Teachers
            </span>
            <span class="hero-chip chip-teal">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                <?= count($teachers) - $unpaid_count ?> Paid — <?= $current_month_label ?>
            </span>
            <?php if ($unpaid_count > 0): ?>
            <span class="hero-chip chip-red">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= $unpaid_count ?> Unpaid
            </span>
            <?php endif; ?>
            <span class="hero-chip chip-amber">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                KES <?= number_format($total_salary) ?> Monthly
            </span>
        </div>
    </div>
</div>

<!-- ══ ALERT STRIP ══ -->
<?php if ($unpaid_count > 0): ?>
<div class="alert-strip danger">
    <span class="alert-strip-icon">🔔</span>
    <div class="alert-strip-body">
        <div class="alert-strip-title">⚠️ Salary Clearance Required — <?= $current_month_label ?></div>
        <div class="alert-strip-desc"><?= $unpaid_count ?> teacher<?= $unpaid_count > 1 ? 's have' : ' has' ?> not been paid this month. Process their payments below.</div>
    </div>
</div>
<?php else: ?>
<div class="alert-strip ok">
    <span class="alert-strip-icon">✅</span>
    <div class="alert-strip-body">
        <div class="alert-strip-title">All salaries cleared for <?= $current_month_label ?></div>
        <div class="alert-strip-desc">Every teacher on payroll has been paid this month. Excellent!</div>
    </div>
</div>
<?php endif; ?>

<!-- ══ PAGE ══ -->
<div class="page">

    <!-- Flash -->
    <?php if (!empty($_SESSION['message'])): ?>
    <div class="flash <?= htmlspecialchars($_SESSION['message_type'] ?? 'success') ?>">
        <span class="flash-icon"><?= ($_SESSION['message_type'] ?? '') === 'error' ? '❌' : '✅' ?></span>
        <span><?= $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?></span>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-box sb-ink">
            <div class="stat-lbl">Total Teachers</div>
            <div class="stat-val"><?= count($teachers) ?></div>
            <div class="stat-hint">On payroll</div>
        </div>
        <div class="stat-box sb-amber">
            <div class="stat-lbl">Monthly Payroll</div>
            <div class="stat-val c-amber">KES <?= number_format($total_salary) ?></div>
            <div class="stat-hint">Total obligation</div>
        </div>
        <div class="stat-box sb-teal">
            <div class="stat-lbl">Total Disbursed</div>
            <div class="stat-val c-teal">KES <?= number_format($total_paid) ?></div>
            <div class="stat-hint">All time</div>
        </div>
        <div class="stat-box sb-red">
            <div class="stat-lbl">Unpaid This Month</div>
            <div class="stat-val <?= $unpaid_count > 0 ? 'c-red' : 'c-teal' ?>"><?= $unpaid_count ?></div>
            <div class="stat-hint"><?= $current_month_label ?></div>
        </div>
    </div>

    <!-- ══ ADD TEACHER ══ -->
    <div class="sec-head">
        <div class="sec-title">
            Teachers
            <span class="badge-count"><?= count($teachers) ?></span>
        </div>
        <button class="btn btn-primary" onclick="toggleAddForm()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Teacher
        </button>
    </div>

    <div class="form-card" id="add-form">
        <div class="form-card-title"><span class="accent-bar"></span> New Teacher Record</div>
        <form method="POST" autocomplete="off">
            <div class="form-grid">
                <div class="field">
                    <label>Full Name</label>
                    <input type="text" name="name" placeholder="e.g. Jane Mwangi" required>
                </div>
                <div class="field">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="jane@school.ac.ke" required>
                </div>
                <div class="field">
                    <label>Password</label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="newTeacherPwd" placeholder="Min. 6 characters" required style="padding-right:42px">
                        <button type="button" class="toggle-pw" onclick="toggleVis('newTeacherPwd', this)">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>
                <div class="field">
                    <label>Phone (2547XXXXXXXX)</label>
                    <input type="text" name="phone" placeholder="2547XXXXXXXX" required>
                </div>
                <div class="field full">
                    <label>Certifications / Qualifications</label>
                    <textarea name="certification" placeholder="e.g. B.Ed Mathematics, KNUT Member, P1 Certificate"></textarea>
                </div>
                <div class="field">
                    <label>Monthly Salary (KES)</label>
                    <input type="number" name="salary" placeholder="e.g. 45000" min="0" required>
                </div>
                <div class="field" style="justify-content:flex-end;padding-bottom:2px">
                    <label style="opacity:0">spacer</label>
                    <div style="font-size:12px;color:var(--ink-3);background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-xs);padding:10px 13px;line-height:1.5">
                        🔑 Username is auto-generated:<br>
                        <strong style="color:var(--teal-dark)">Initials + last 4 digits of phone</strong><br>
                        <em>e.g. Jane Mwangi + 0712345678 → JM5678</em>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="save_teacher" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save Teacher
                </button>
                <button type="button" class="btn btn-outline" onclick="toggleAddForm()">Cancel</button>
            </div>
        </form>
    </div>

    <!-- ══ PASSWORD CHANGE SECTION ══ -->
    <?php if (!empty($teachers)): ?>
    <div class="pwd-section" id="pwd-section">
        <div class="pwd-section-header" onclick="togglePwdSection()">
            <div class="pwd-section-title">
                <span class="pwd-section-icon">🔑</span>
                Change Teacher Password
            </div>
            <svg class="pwd-chevron" id="pwd-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="pwd-body" id="pwd-body">
            <p style="font-size:13px;color:var(--ink-3);margin-bottom:14px">Select a teacher and set a new password. Passwords are stored securely using bcrypt hashing.</p>
            <form method="POST" autocomplete="off">
                <select name="teacher_user_id" class="pwd-teacher-select" required>
                    <option value="" disabled selected>— Select a teacher —</option>
                    <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['user_id'] ?>">
                        <?= htmlspecialchars($t['name']) ?>
                        <?php if (!empty($t['username'])): ?>
                            (@<?= htmlspecialchars($t['username']) ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="pwd-grid">
                    <div class="field">
                        <label>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            New Password
                        </label>
                        <div class="input-wrap">
                            <input type="password" name="new_password" id="pwdNew" placeholder="Min. 6 characters" required oninput="checkStrength(this.value)">
                            <button type="button" class="toggle-pw" onclick="toggleVis('pwdNew', this)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <div class="pwd-strength"><div class="pwd-strength-fill" id="strengthBar" style="width:0%;background:var(--red)"></div></div>
                        <div class="pwd-hint" id="strengthLabel">Enter a password</div>
                    </div>
                    <div class="field">
                        <label>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Confirm Password
                        </label>
                        <div class="input-wrap">
                            <input type="password" name="confirm_password" id="pwdConfirm" placeholder="Repeat password" required oninput="checkMatch()">
                            <button type="button" class="toggle-pw" onclick="toggleVis('pwdConfirm', this)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <div class="pwd-hint" id="matchLabel" style="margin-top:12px">&nbsp;</div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" name="change_password" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ TEACHER CARDS ══ -->
    <?php if (empty($teachers)): ?>
    <div class="empty-state">
        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        <p>No teachers yet. Click <strong>Add Teacher</strong> to get started.</p>
    </div>
    <?php else: foreach ($teachers as $idx => $row):
        $color      = $avatar_palette[$idx % count($avatar_palette)];
        $month_pct  = ($row['basic_salary'] > 0 && $row['this_month_paid'])
                    ? min(100, round(($row['this_month_amount'] / $row['basic_salary']) * 100))
                    : 0;
        $certs      = array_filter(array_map('trim', explode(',', $row['certification'])));
        $stmt_id    = 'stmt-'  . $row['id'];
        $tog_id     = 'tog-'   . $row['id'];
        $del_id     = 'del-'   . $row['id'];

        // SMS body
        $sms_body = $row['this_month_paid']
            ? "Dear {$row['name']}, your salary of KES " . number_format($row['this_month_amount']) . " via {$row['this_month_method']} for $current_month_label has been processed. Total disbursed: KES " . number_format($row['total_paid']) . ". - SchoolDesk"
            : "Dear {$row['name']}, your salary of KES " . number_format($row['basic_salary']) . " for $current_month_label has NOT been processed yet. Please contact the finance office. - SchoolDesk";
        $sms_href = "sms:" . htmlspecialchars($row['phone']) . "?body=" . urlencode($sms_body);
    ?>

    <div class="teacher-card">
        <!-- Header -->
        <div class="tc-head <?= $row['this_month_paid'] ? 'paid' : 'unpaid' ?>">
            <div class="avatar" style="background:<?= $color ?>">
                <?= htmlspecialchars(initials($row['name'])) ?>
            </div>
            <div class="tc-info">
                <div class="tc-name"><?= htmlspecialchars($row['name']) ?></div>
                <div class="tc-meta-line">
                    📞 <?= htmlspecialchars($row['phone']) ?>
                    <span class="sep">·</span>
                    <?php if (!empty($row['username'])): ?>
                    <span class="username-tag">@<?= htmlspecialchars($row['username']) ?></span>
                    <?php else: ?>
                    <span style="color:var(--red);font-size:11px">No username</span>
                    <?php endif; ?>
                    <span class="sep">·</span>
                    <?= $row['months_paid'] ?> mo. paid
                </div>
            </div>
            <div class="month-badge <?= $row['this_month_paid'] ? 'paid' : 'unpaid' ?>">
                <?php if ($row['this_month_paid']): ?>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                <?= $current_month_label ?>
                <?php else: ?>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Unpaid
                <?php endif; ?>
            </div>
        </div>

        <!-- Body -->
        <div class="tc-body">
            <!-- Certs -->
            <?php if (!empty($certs)): ?>
            <div class="tc-certs">
                <?php foreach ($certs as $c): ?>
                <span class="cert-chip"><?= htmlspecialchars($c) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="tc-stats-row">
                <div class="ts-item">
                    <div class="ts-lbl">Monthly Salary</div>
                    <div class="ts-val">KES <?= number_format($row['basic_salary']) ?></div>
                </div>
                <div class="ts-item">
                    <div class="ts-lbl">Total Paid</div>
                    <div class="ts-val c-teal">KES <?= number_format($row['total_paid']) ?></div>
                </div>
                <div class="ts-item">
                    <div class="ts-lbl">This Month</div>
                    <?php if ($row['this_month_paid']): ?>
                    <div class="ts-val c-teal">KES <?= number_format($row['this_month_amount']) ?></div>
                    <?php else: ?>
                    <div class="ts-val c-red">Not Paid</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Progress -->
            <div class="prog-wrap">
                <div class="prog-header">
                    <span><?= $current_month_label ?> payment progress</span>
                    <span class="prog-pct"><?= $month_pct ?>%</span>
                </div>
                <div class="prog-track">
                    <div class="prog-fill" style="width:<?= $month_pct ?>%"></div>
                </div>
            </div>

            <!-- Transactions toggle -->
            <div class="txn-toggle" id="<?= $tog_id ?>" onclick="toggleTxn('<?= $stmt_id ?>', '<?= $tog_id ?>')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
                Recent Transactions (<?= count($row['transactions']) ?>)
            </div>

            <?php if (!empty($row['transactions'])): ?>
            <table class="txn-table" id="<?= $stmt_id ?>">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($row['transactions'] as $txn):
                        $mc = match(strtolower($txn['method'])) {
                            'm-pesa', 'mpesa' => 'mpesa',
                            'bank'            => 'bank',
                            default           => 'cash'
                        };
                    ?>
                    <tr>
                        <td class="txn-mon"><?= htmlspecialchars($txn['month_year'] ?? '—') ?></td>
                        <td class="txn-amt">KES <?= number_format($txn['amount_paid']) ?></td>
                        <td><span class="method-tag mt-<?= $mc ?>"><?= htmlspecialchars($txn['method']) ?></span></td>
                        <td><?= date('d M Y', strtotime($txn['payment_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-txns" id="<?= $stmt_id ?>" style="display:none">No transactions recorded yet.</div>
            <?php endif; ?>

            <div class="divider"></div>

            <!-- Pay form -->
            <div class="pay-lbl">Record Monthly Salary Instalment</div>
            <form method="POST">
                <input type="hidden" name="teacher_id" value="<?= $row['id'] ?>">
                <div class="pay-row">
                    <input type="month" name="month_year" value="<?= $current_month ?>" required>
                    <input type="number" name="amount" placeholder="Amount (KES)" value="<?= $row['basic_salary'] ?>" min="1" required>
                    <select name="method">
                        <option>Cash</option>
                        <option>M-Pesa</option>
                        <option>Bank</option>
                    </select>
                    <button type="submit" name="pay_salary" class="btn-pay">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                        Pay
                    </button>
                    <a href="<?= $sms_href ?>" class="btn-sms">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        SMS
                    </a>
                </div>
            </form>

            <!-- Delete -->
            <div class="delete-row">
                <button type="button" class="btn btn-ghost" style="font-size:12px;color:var(--red);border-color:rgba(217,79,79,.2)" onclick="toggleDel('<?= $del_id ?>')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Remove Teacher
                </button>
            </div>

            <!-- Confirm delete (hidden) -->
            <div id="<?= $del_id ?>" style="display:none;margin-top:8px;padding:12px;background:var(--red-lt);border:1px solid rgba(217,79,79,.2);border-radius:var(--r-sm);">
                <p style="font-size:13px;color:var(--red);font-weight:600;margin-bottom:10px">
                    ⚠️ This will permanently delete <strong><?= htmlspecialchars($row['name']) ?></strong> and all their payment records. Are you sure?
                </p>
                <form method="POST" style="display:flex;gap:8px">
                    <input type="hidden" name="delete_teacher_id" value="<?= $row['id'] ?>">
                    <button type="submit" name="delete_teacher" class="btn btn-danger" style="font-size:13px">Yes, Delete</button>
                    <button type="button" class="btn btn-outline" style="font-size:13px" onclick="toggleDel('<?= $del_id ?>')">Cancel</button>
                </form>
            </div>

        </div>
    </div>

    <?php endforeach; endif; ?>

</div><!-- /page -->

<script>
/* ── TOGGLE ADD FORM ── */
function toggleAddForm() {
    const f = document.getElementById('add-form');
    f.classList.toggle('open');
    if (f.classList.contains('open')) {
        f.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(() => f.querySelector('input[name="name"]')?.focus(), 220);
    }
}

/* ── TOGGLE TRANSACTION TABLE ── */
function toggleTxn(stmtId, togId) {
    const t = document.getElementById(stmtId);
    const g = document.getElementById(togId);
    t.classList.toggle('open');
    g.classList.toggle('open');
}

/* ── TOGGLE DELETE CONFIRM ── */
function toggleDel(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

/* ── TOGGLE PASSWORD SECTION ── */
function togglePwdSection() {
    const body    = document.getElementById('pwd-body');
    const chevron = document.getElementById('pwd-chevron');
    body.classList.toggle('open');
    chevron.classList.toggle('open');
}

/* ── SHOW / HIDE PASSWORD ── */
function toggleVis(inputId, btn) {
    const inp = document.getElementById(inputId);
    const showing = inp.type === 'text';
    inp.type = showing ? 'password' : 'text';
    // Swap eye icon
    btn.innerHTML = showing
        ? `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`
        : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
}

/* ── PASSWORD STRENGTH ── */
function checkStrength(val) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    if (!bar) return;

    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '0%',   bg: '#ccc',           text: 'Enter a password' },
        { pct: '20%',  bg: 'var(--red)',      text: 'Too weak' },
        { pct: '40%',  bg: 'var(--amber)',    text: 'Weak' },
        { pct: '60%',  bg: 'var(--amber)',    text: 'Fair' },
        { pct: '80%',  bg: 'var(--teal)',     text: 'Strong' },
        { pct: '100%', bg: 'var(--teal-dark)',text: 'Very strong ✓' },
    ];

    const lvl   = val.length === 0 ? levels[0] : levels[Math.min(score, 5)];
    bar.style.width      = lvl.pct;
    bar.style.background = lvl.bg;
    label.textContent    = lvl.text;
    label.style.color    = lvl.bg;

    checkMatch();
}

/* ── CONFIRM MATCH ── */
function checkMatch() {
    const p1  = document.getElementById('pwdNew')?.value;
    const p2  = document.getElementById('pwdConfirm')?.value;
    const lbl = document.getElementById('matchLabel');
    if (!lbl || !p2) return;

    if (p2.length === 0) {
        lbl.textContent = '\u00A0';
        lbl.style.color = 'var(--ink-3)';
    } else if (p1 === p2) {
        lbl.textContent = '✓ Passwords match';
        lbl.style.color = 'var(--teal-dark)';
    } else {
        lbl.textContent = '✗ Passwords do not match';
        lbl.style.color = 'var(--red)';
    }
}

/* ── AUTO-OPEN PASSWORD SECTION IF ANCHORED ── */
if (window.location.hash === '#pwd-section') {
    const body = document.getElementById('pwd-body');
    const chev = document.getElementById('pwd-chevron');
    if (body) { body.classList.add('open'); chev?.classList.add('open'); }
}
</script>
</body>
</html>