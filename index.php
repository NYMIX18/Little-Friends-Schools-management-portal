<?php
/* ══════════════════════════════════════════════════════════════
   SERVE MANIFEST & SERVICE WORKER FROM A SINGLE FILE
══════════════════════════════════════════════════════════════ */
$request = $_SERVER['REQUEST_URI'] ?? '/';
$path    = parse_url($request, PHP_URL_PATH);

/* ── /manifest.json ── */
if ($path === '/manifest.json') {
    header('Content-Type: application/manifest+json');
    echo json_encode([
        'name'             => 'Little Friends Schools',
        'short_name'       => 'LF Schools',
        'description'      => 'Strong Foundations, Brighter Futures — School Portal',
        'start_url'        => '/',
        'display'          => 'standalone',
        'background_color' => '#060E1F',
        'theme_color'      => '#C9A84C',
        'orientation'      => 'portrait',
        'icons'            => [
            ['src' => 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44"><circle cx="22" cy="22" r="21" fill="#060E1F" stroke="#C9A84C" stroke-width="1.4"/><path d="M12 14C12 14 16 12 22 14C28 12 32 14 32 14L32 32C32 32 28 30 22 32C16 30 12 32 12 32Z" fill="none" stroke="#C9A84C" stroke-width="1.4" stroke-linejoin="round"/><line x1="22" y1="14" x2="22" y2="32" stroke="#C9A84C" stroke-width="1.1"/><path d="M22 9L22.9 11.7L25.8 11.7L23.4 13.4L24.4 16.1L22 14.4L19.6 16.1L20.6 13.4L18.2 11.7L21.1 11.7Z" fill="#C9A84C"/></svg>'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

/* ── /sw.js ── */
if ($path === '/sw.js') {
    header('Content-Type: application/javascript');
    header('Service-Worker-Allowed: /');
    echo <<<'JS'
const CACHE = 'lf-schools-v2';
const SHELL = ['/', '/manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys =>
    Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
  ));
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    fetch(e.request)
      .then(res => {
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, clone));
        return res;
      })
      .catch(() => caches.match(e.request).then(r => r || new Response('Offline', {status: 503})))
  );
});
JS;
    exit;
}

/* ══════════════════════════════════════════════════════════════
   DB + SESSION
══════════════════════════════════════════════════════════════ */
ini_set('session.cookie_samesite', 'Lax');
define('IN_APP', true);
session_start();
include("config/db.php");

$show_panel = 'login';
$reg_type   = 'student';
$login_role = 'student';

/* ══════════════════════════════════════════════════════════════
   REGISTRATION
══════════════════════════════════════════════════════════════ */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'register') {

    $reg_role  = trim($_POST['reg_role']      ?? '');
    $reg_pass  = trim($_POST['reg_password']  ?? '');
    $reg_pass2 = trim($_POST['reg_password2'] ?? '');

    /* ══ STUDENT ══ */
    if ($reg_role === 'student') {
        $lookup   = trim($_POST['student_lookup'] ?? '');
        $lookup_e = mysqli_real_escape_string($conn, $lookup);

        if (empty($lookup) || empty($reg_pass)) {
            $reg_error = "Please fill in all fields.";
        } elseif ($reg_pass !== $reg_pass2) {
            $reg_error = "Passwords do not match.";
        } elseif (strlen($reg_pass) < 6) {
            $reg_error = "Password must be at least 6 characters.";
        } else {
            $row = null;
            $res_adm = mysqli_query($conn,
                "SELECT s.id AS student_id, s.user_id, s.admission_number, u.name
                 FROM students s LEFT JOIN users u ON s.user_id = u.id
                 WHERE s.admission_number = '{$lookup_e}' LIMIT 1");
            if ($res_adm && mysqli_num_rows($res_adm) === 1) $row = mysqli_fetch_assoc($res_adm);

            if (!$row) {
                $res_name = mysqli_query($conn,
                    "SELECT s.id AS student_id, s.user_id, s.admission_number, u.name
                     FROM students s LEFT JOIN users u ON s.user_id = u.id
                     WHERE u.name LIKE '%{$lookup_e}%'");
                if ($res_name && mysqli_num_rows($res_name) === 1) $row = mysqli_fetch_assoc($res_name);
                elseif ($res_name && mysqli_num_rows($res_name) > 1)
                    $reg_error = "Multiple students found. Please use your <strong>admission number</strong>.";
            }

            if (!isset($reg_error)) {
                if (!$row) {
                    $reg_error = "No student found. Contact the school office.";
                } else {
                    $hashed       = password_hash($reg_pass, PASSWORD_DEFAULT);
                    $student_name = $row['name'] ?? $row['admission_number'] ?? $lookup;
                    if ($row['user_id']) {
                        mysqli_query($conn, "UPDATE users SET password='{$hashed}' WHERE id=" . (int)$row['user_id']);
                    } else {
                        $sname_e   = mysqli_real_escape_string($conn, $student_name);
                        $admno_e   = mysqli_real_escape_string($conn, $row['admission_number'] ?? $lookup);
                        $uname_base = strtolower(preg_replace('/\s+/', '', explode(' ', $student_name)[0]));
                        $uname_base = preg_replace('/[^a-z0-9]/', '', $uname_base);
                        $adm_digits = preg_replace('/\D/', '', $admno_e);
                        $gen_uname  = $uname_base . ($adm_digits ?: rand(1000, 9999));
                        $gen_uname_e = mysqli_real_escape_string($conn, substr($gen_uname, 0, 50));
                        mysqli_query($conn,
                            "INSERT INTO users (name,username,phone,phone_number,email,password,role,profile_pic)
                             VALUES ('{$sname_e}','{$gen_uname_e}','','','{$admno_e}@student.local','{$hashed}','student','')");
                        $new_uid = mysqli_insert_id($conn);
                        mysqli_query($conn, "UPDATE students SET user_id={$new_uid} WHERE id=" . (int)$row['student_id']);
                    }
                    $reg_success = "Account activated! Welcome, <strong>" . htmlspecialchars($student_name) . "</strong>.";
                }
            }
        }
        $show_panel = isset($reg_success) ? 'login' : 'register';
        $reg_type   = 'student';
        $login_role = 'student';
    }

    /* ══ TEACHER ══ */
    elseif ($reg_role === 'teacher') {
        $phone   = trim($_POST['teacher_phone'] ?? '');
        $phone_e = mysqli_real_escape_string($conn, $phone);

        if (empty($phone) || empty($reg_pass)) {
            $reg_error = "Please fill in all fields.";
        } elseif ($reg_pass !== $reg_pass2) {
            $reg_error = "Passwords do not match.";
        } elseif (strlen($reg_pass) < 6) {
            $reg_error = "Password must be at least 6 characters.";
        } else {
            $res = mysqli_query($conn,
                "SELECT t.id AS teacher_id, t.user_id, t.phone, u.name AS uname, u.username
                 FROM teachers t LEFT JOIN users u ON u.id = t.user_id
                 WHERE t.phone='{$phone_e}' LIMIT 1");
            if (!$res) {
                $reg_error = "Database error: " . mysqli_error($conn);
            } elseif (mysqli_num_rows($res) === 0) {
                $reg_error = "No teacher found with that phone. Contact the school office.";
            } else {
                $row    = mysqli_fetch_assoc($res);
                $hashed = password_hash($reg_pass, PASSWORD_DEFAULT);
                if (!empty($row['user_id'])) {
                    $update = mysqli_query($conn, "UPDATE users SET password='{$hashed}' WHERE id=" . (int)$row['user_id']);
                    if (!$update) { $reg_error = "Update error: " . mysqli_error($conn); }
                    else {
                        $teacher_name     = $row['uname'] ?? $row['tname'] ?? 'Teacher';
                        $teacher_username = $row['username'] ?? '';
                        $reg_success = "Account activated! Welcome, <strong>" . htmlspecialchars($teacher_name) . "</strong>."
                            . ($teacher_username ? " Username: <strong>@{$teacher_username}</strong>" : "");
                    }
                } else {
                    $teacher_name = 'Teacher_' . $phone;
                    $parts   = array_filter(explode(' ', trim($teacher_name)));
                    $initials = '';
                    foreach ($parts as $p) $initials .= strtoupper($p[0]);
                    $digits  = preg_replace('/\D/', '', $phone);
                    $last4   = substr(str_pad($digits, 4, '0', STR_PAD_LEFT), -4);
                    $base_u  = $initials . $last4;
                    $teacher_username = $base_u;
                    $suffix = 1;
                    while (true) {
                        $ue  = mysqli_real_escape_string($conn, $teacher_username);
                        $chk = mysqli_query($conn, "SELECT id FROM users WHERE username='{$ue}' LIMIT 1");
                        if (!$chk || mysqli_num_rows($chk) === 0) break;
                        $teacher_username = $base_u . $suffix++;
                    }
                    $tname_e = mysqli_real_escape_string($conn, $teacher_name);
                    $uname_e = mysqli_real_escape_string($conn, $teacher_username);
                    $email_e = mysqli_real_escape_string($conn, $phone . '@teacher.local');
                    $insert  = mysqli_query($conn,
                        "INSERT INTO users (name,username,phone,phone_number,email,password,role,profile_pic)
                         VALUES ('{$tname_e}','{$uname_e}','{$phone_e}','{$phone_e}','{$email_e}','{$hashed}','teacher','')");
                    if (!$insert) { $reg_error = "Insert error: " . mysqli_error($conn); }
                    else {
                        $new_uid = mysqli_insert_id($conn);
                        $upT = mysqli_query($conn, "UPDATE teachers SET user_id={$new_uid} WHERE id=" . (int)$row['teacher_id']);
                        if (!$upT) { $reg_error = "Link error: " . mysqli_error($conn); }
                        else {
                            $reg_success = "Account activated! Welcome, <strong>" . htmlspecialchars($teacher_name)
                                . "</strong>. Username: <strong>@{$teacher_username}</strong>";
                        }
                    }
                }
            }
        }
        $show_panel = isset($reg_success) ? 'login' : 'register';
        $reg_type   = 'teacher';
        $login_role = 'teacher';
    }
}

/* ══════════════════════════════════════════════════════════════
   LOGIN
══════════════════════════════════════════════════════════════ */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['login_role'])) {
    $login_role = trim($_POST['login_role']);

    if ($login_role === 'student') {
        $name_input = trim($_POST['student_name'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $name_esc   = mysqli_real_escape_string($conn, $name_input);
        if (empty($name_input) || empty($password)) {
            $error = "Please enter your name/admission number and password.";
        } else {
            $q   = "SELECT u.* FROM users u JOIN students s ON s.user_id = u.id
                    WHERE u.role='student' AND (u.name LIKE '%{$name_esc}%' OR s.admission_number='{$name_esc}') LIMIT 1";
            $res = mysqli_query($conn, $q);
            if ($res && mysqli_num_rows($res) === 1) {
                $user = mysqli_fetch_assoc($res);
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['role']        = 'student';
                    $_SESSION['name']        = $user['name'];
                    $_SESSION['username']    = $user['username'] ?? '';
                    $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
                    header("Location: dashboards/student.php");
                    exit();
                } else { $error = "Incorrect password. Please try again."; }
            } else { $error = "No student account found. Try registering first."; }
        }
        $show_panel = 'login'; $login_role = 'student';
    }

    elseif ($login_role === 'teacher') {
        $identifier = trim($_POST['teacher_identifier'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $id_esc     = mysqli_real_escape_string($conn, ltrim($identifier, '@'));
        if (empty($identifier) || empty($password)) {
            $error = "Please enter your phone/username and password.";
        } else {
            $user = null;
            foreach ([
                "SELECT u.* FROM users u JOIN teachers t ON t.user_id=u.id WHERE u.role='teacher' AND t.phone='{$id_esc}' LIMIT 1",
                "SELECT * FROM users WHERE role='teacher' AND (phone='{$id_esc}' OR phone_number='{$id_esc}') LIMIT 1",
                "SELECT * FROM users WHERE role='teacher' AND username='{$id_esc}' LIMIT 1",
                "SELECT u.* FROM users u JOIN teachers t ON t.user_id=u.id WHERE u.role='teacher' AND u.name LIKE '%{$id_esc}%' LIMIT 1",
            ] as $q) {
                $res = mysqli_query($conn, $q);
                if ($res && mysqli_num_rows($res) === 1) { $user = mysqli_fetch_assoc($res); break; }
            }
            if ($user) {
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['role']        = 'teacher';
                    $_SESSION['name']        = $user['name'];
                    $_SESSION['username']    = $user['username'] ?? '';
                    $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
                    $ct = mysqli_query($conn, "SELECT is_class_teacher FROM teachers WHERE user_id=" . (int)$user['id']);
                    $_SESSION['is_class_teacher'] = ($ct && mysqli_num_rows($ct) === 1)
                        ? (int)mysqli_fetch_assoc($ct)['is_class_teacher'] : 0;
                    header("Location: dashboards/teacher_dashboard.php");
                    exit();
                } else { $error = "Incorrect password. Please try again."; }
            } else { $error = "No teacher account found. Try registering first or contact admin."; }
        }
        $show_panel = 'login'; $login_role = 'teacher';
    }

    elseif ($login_role === 'admin') {
        $email    = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $em_esc   = mysqli_real_escape_string($conn, $email);
        if (empty($email) || empty($password)) {
            $error = "Please enter your email and password.";
        } else {
            $q   = "SELECT * FROM users WHERE email='{$em_esc}' AND role='admin' LIMIT 1";
            $res = mysqli_query($conn, $q);
            if ($res && mysqli_num_rows($res) === 1) {
                $user = mysqli_fetch_assoc($res);
                $ok   = password_verify($password, $user['password']);
                if (!$ok && $password === $user['password']) {
                    $ok     = true;
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    mysqli_query($conn, "UPDATE users SET password='{$hashed}' WHERE id=" . (int)$user['id']);
                }
                if ($ok) {
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['role']        = 'admin';
                    $_SESSION['name']        = $user['name'];
                    $_SESSION['username']    = $user['username'] ?? '';
                    $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
                    header("Location: dashboards/admin.php");
                    exit();
                } else { $error = "Incorrect email or password."; }
            } else { $error = "No admin account found with that email."; }
        }
        $show_panel = 'login'; $login_role = 'admin';
    }
}

/* ══════════════════════════════════════════════════════════════
   INLINE SVG ICON — used everywhere as a PHP constant
   No /icons/ folder needed at all.
══════════════════════════════════════════════════════════════ */
function lf_logo(int $size = 44, string $extra_class = ''): string {
    return <<<SVG
<svg width="{$size}" height="{$size}" viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg" class="{$extra_class}" aria-label="Little Friends Schools logo">
  <circle cx="22" cy="22" r="21" fill="#060E1F" stroke="#C9A84C" stroke-width="1.4"/>
  <path d="M12 14C12 14 16 12 22 14C28 12 32 14 32 14L32 32C32 32 28 30 22 32C16 30 12 32 12 32Z"
        fill="none" stroke="#C9A84C" stroke-width="1.4" stroke-linejoin="round"/>
  <line x1="22" y1="14" x2="22" y2="32" stroke="#C9A84C" stroke-width="1.1"/>
  <path d="M22 9L22.9 11.7L25.8 11.7L23.4 13.4L24.4 16.1L22 14.4L19.6 16.1L20.6 13.4L18.2 11.7L21.1 11.7Z"
        fill="#C9A84C"/>
</svg>
SVG;
}

/* Inline favicon as data URI — zero external files */
$favicon_svg = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44"><circle cx="22" cy="22" r="21" fill="#060E1F" stroke="#C9A84C" stroke-width="1.4"/><path d="M12 14C12 14 16 12 22 14C28 12 32 14 32 14L32 32C32 32 28 30 22 32C16 30 12 32 12 32Z" fill="none" stroke="#C9A84C" stroke-width="1.4" stroke-linejoin="round"/><line x1="22" y1="14" x2="22" y2="32" stroke="#C9A84C" stroke-width="1.1"/><path d="M22 9L22.9 11.7L25.8 11.7L23.4 13.4L24.4 16.1L22 14.4L19.6 16.1L20.6 13.4L18.2 11.7L21.1 11.7Z" fill="#C9A84C"/></svg>');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Little Friends Schools — Strong Foundations, Brighter Futures</title>

<!-- PWA META -->
<meta name="application-name" content="Little Friends Schools">
<meta name="description" content="Strong Foundations, Brighter Futures — School Portal & Information">
<meta name="theme-color" content="#C9A84C">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="LF Schools">
<meta name="msapplication-TileColor" content="#060E1F">

<!-- PWA MANIFEST -->
<link rel="manifest" href="/manifest.json">

<!-- APPLE TOUCH ICON — inline SVG data URI, no file needed -->
<link rel="apple-touch-icon" href="data:image/svg+xml,<?= $favicon_svg ?>">

<!-- FAVICON — inline SVG data URI, no file needed -->
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<?= $favicon_svg ?>">

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600;1,700&family=Jost:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ══ TOKENS ══ */
:root {
    --gold:      #C9A84C;
    --gold-lt:   #E8C97A;
    --gold-dk:   #8C6A1E;
    --gold-dim:  rgba(201,168,76,.15);
    --navy:      #060E1F;
    --navy-mid:  #0D1B35;
    --navy-lt:   #132240;
    --cream:     #FAF6ED;
    --cream-dk:  #EDE5CE;
    --white:     #FFFFFF;
    --muted:     #7A8AA0;
    --border:    rgba(201,168,76,.18);
    --border-lt: rgba(201,168,76,.09);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; font-size: 15px; }
body { font-family: 'Jost', sans-serif; background: var(--cream); color: var(--navy); overflow-x: hidden; }
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: var(--navy); }
::-webkit-scrollbar-thumb { background: var(--gold); border-radius: 3px; }

/* ══════════════════════════════════════
   SPLASH SCREEN
══════════════════════════════════════ */
#app-splash {
    position: fixed; inset: 0; z-index: 99999;
    background: #060E1F;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    transition: opacity .65s cubic-bezier(.4,0,.2,1), transform .65s cubic-bezier(.4,0,.2,1);
}
#app-splash.sp-hide { opacity: 0; pointer-events: none; transform: scale(1.07); }
.sp-rings { position: relative; width: 230px; height: 230px; display: flex; align-items: center; justify-content: center; }
.sp-ring { position: absolute; border-radius: 50%; border: 1.5px solid rgba(201,168,76,.18); animation: spRing 2.2s ease-out forwards; }
.sp-r1 { width: 110px; height: 110px; }
.sp-r2 { width: 165px; height: 165px; animation-delay: .35s; }
.sp-r3 { width: 220px; height: 220px; animation-delay: .7s; }
.sp-logo-wrap { opacity: 0; transform: scale(.45); animation: spPop .85s .25s cubic-bezier(.34,1.56,.64,1) forwards; }
.sp-name  { font-family: 'Cormorant Garamond', serif; font-size: 22px; font-weight: 700; color: var(--gold); margin-top: 22px; letter-spacing: .5px; opacity: 0; animation: spUp .65s .95s ease forwards; }
.sp-motto { font-family: 'Cormorant Garamond', serif; font-style: italic; font-size: 13.5px; color: rgba(201,168,76,.5); margin-top: 7px; letter-spacing: .3px; opacity: 0; animation: spUp .65s 1.15s ease forwards; }
.sp-bar   { width: 80px; height: 2px; background: rgba(201,168,76,.12); border-radius: 2px; margin-top: 34px; overflow: hidden; opacity: 0; animation: spFadeIn .4s 1.35s ease forwards; }
.sp-bar-fill { height: 100%; width: 0; background: var(--gold); border-radius: 2px; animation: spBarFill 1.9s 1.45s ease forwards; }
@keyframes spRing    { 0%{opacity:0;transform:scale(.35)} 40%{opacity:.55} 100%{opacity:0;transform:scale(1.55)} }
@keyframes spPop     { to{opacity:1;transform:scale(1)} }
@keyframes spUp      { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
@keyframes spFadeIn  { to{opacity:1} }
@keyframes spBarFill { to{width:100%} }

/* ══════════════════════════════════════
   INSTALL FAB
══════════════════════════════════════ */
#install-fab {
    display: none; position: fixed; bottom: 24px; right: 20px; z-index: 8888;
    background: linear-gradient(135deg, var(--navy-mid), var(--navy-lt));
    border: 1px solid rgba(201,168,76,.35); border-radius: 50px; padding: 11px 20px 11px 14px;
    box-shadow: 0 8px 30px rgba(0,0,0,.45); align-items: center; gap: 10px;
    cursor: pointer; animation: fabIn .5s ease both; text-decoration: none;
}
#install-fab.show { display: flex; }
@keyframes fabIn { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
.fab-icon  { width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0; background: rgba(201,168,76,.12); border: 1px solid rgba(201,168,76,.22); display: flex; align-items: center; justify-content: center; }
.fab-text-top { font-size: 12.5px; font-weight: 700; color: #fff; line-height: 1; }
.fab-text-sub { font-size: 10px; color: rgba(255,255,255,.42); margin-top: 2px; }
.fab-arrow { width: 28px; height: 28px; border-radius: 50%; background: var(--gold); display: flex; align-items: center; justify-content: center; color: var(--navy); font-size: 12px; flex-shrink: 0; }
#install-fab-close { position: absolute; top: -8px; right: -8px; width: 20px; height: 20px; border-radius: 50%; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.15); color: rgba(255,255,255,.5); font-size: 10px; display: flex; align-items: center; justify-content: center; cursor: pointer; }

/* ══ PWA BANNER ══ */
#pwa-banner {
    display: none; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 9999;
    background: linear-gradient(135deg, #0D1B35, #132240); border: 1px solid rgba(201,168,76,.35); border-radius: 16px;
    padding: 14px 20px; box-shadow: 0 12px 40px rgba(0,0,0,.45); width: calc(100% - 32px); max-width: 420px;
    animation: slideUp .4s ease; backdrop-filter: blur(20px);
}
#pwa-banner.show { display: flex; align-items: center; gap: 14px; }
@keyframes slideUp { from{opacity:0;transform:translateX(-50%) translateY(20px)} to{opacity:1;transform:translateX(-50%) translateY(0)} }
.pwa-banner-icon  { width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0; background: rgba(201,168,76,.12); border: 1px solid rgba(201,168,76,.25); display: flex; align-items: center; justify-content: center; }
.pwa-banner-body  { flex: 1; min-width: 0; }
.pwa-banner-title { font-size: 13.5px; font-weight: 700; color: #fff; margin-bottom: 2px; }
.pwa-banner-sub   { font-size: 11.5px; color: rgba(255,255,255,.42); line-height: 1.4; }
.pwa-banner-btns  { display: flex; flex-direction: column; gap: 6px; flex-shrink: 0; }
.pwa-install-btn  { background: var(--gold); color: var(--navy); border: none; padding: 7px 16px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: 'Jost',sans-serif; white-space: nowrap; transition: all .2s; }
.pwa-install-btn:hover { background: var(--gold-lt); }
.pwa-dismiss-btn  { background: rgba(255,255,255,.07); color: rgba(255,255,255,.4); border: none; padding: 7px 16px; border-radius: 8px; font-size: 11px; font-weight: 500; cursor: pointer; font-family: 'Jost',sans-serif; white-space: nowrap; transition: all .2s; }
.pwa-dismiss-btn:hover { background: rgba(255,255,255,.12); color: rgba(255,255,255,.7); }

/* iOS banner */
#ios-banner {
    display: none; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 9999;
    background: linear-gradient(135deg, #0D1B35, #132240); border: 1px solid rgba(201,168,76,.35); border-radius: 16px;
    padding: 16px 20px; box-shadow: 0 12px 40px rgba(0,0,0,.45); width: calc(100% - 32px); max-width: 380px;
    text-align: center; animation: slideUp .4s ease;
}
#ios-banner.show { display: block; }
.ios-banner-title { font-size: 13.5px; font-weight: 700; color: #fff; margin-bottom: 8px; }
.ios-banner-steps { font-size: 12px; color: rgba(255,255,255,.55); line-height: 1.8; margin-bottom: 12px; }
.ios-banner-steps strong { color: var(--gold); }
.ios-dismiss { background: rgba(255,255,255,.07); color: rgba(255,255,255,.5); border: none; padding: 7px 22px; border-radius: 8px; font-size: 11.5px; cursor: pointer; font-family: 'Jost',sans-serif; }

/* ══ NAV ══ */
nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 900;
    height: 70px; padding: 0 44px;
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(6,14,31,.94); backdrop-filter: blur(24px);
    border-bottom: 1px solid var(--border-lt); transition: border-color .3s;
}
.nav-brand      { display: flex; align-items: center; gap: 13px; text-decoration: none; }
.nav-brand-wrap { display: flex; flex-direction: column; line-height: 1; }
.nav-brand-name { font-family: 'Cormorant Garamond',serif; font-size: 14.5px; font-weight: 700; color: var(--gold); letter-spacing: .4px; }
.nav-brand-sub  { font-size: 9.5px; color: rgba(201,168,76,.5); letter-spacing: 2.5px; text-transform: uppercase; margin-top: 3px; }
.nav-links { list-style: none; display: flex; gap: 32px; align-items: center; }
.nav-links a { text-decoration: none; font-size: 13px; font-weight: 500; color: rgba(255,255,255,.65); letter-spacing: .4px; position: relative; transition: color .25s; }
.nav-links a::after { content:''; position:absolute; bottom:-4px; left:0; right:100%; height:1px; background:var(--gold); transition:right .25s; }
.nav-links a:hover { color: var(--gold); }
.nav-links a:hover::after { right: 0; }
.nav-portal { background: var(--gold) !important; color: var(--navy) !important; padding: 8px 22px !important; border-radius: 5px; font-weight: 700 !important; font-size: 12.5px !important; letter-spacing: .6px; }
.nav-portal::after { display: none !important; }
.nav-portal:hover { background: var(--gold-lt) !important; box-shadow: 0 4px 18px rgba(201,168,76,.35) !important; }
.nav-install-btn { display: none; align-items: center; gap: 7px; background: rgba(201,168,76,.1); border: 1px solid rgba(201,168,76,.28); border-radius: 5px; padding: 7px 16px; color: var(--gold); font-size: 12px; font-weight: 700; cursor: pointer; font-family: 'Jost',sans-serif; transition: all .22s; white-space: nowrap; }
.nav-install-btn:hover { background: rgba(201,168,76,.18); }
.nav-install-btn.show { display: flex; }
.hamburger { display: none; flex-direction: column; gap: 5px; cursor: pointer; background: none; border: none; padding: 4px; }
.hamburger span { width: 22px; height: 2px; background: var(--gold); border-radius: 2px; transition: all .3s; }
.mob-menu { display: none; position: fixed; inset: 0; z-index: 980; background: var(--navy); flex-direction: column; align-items: center; justify-content: center; gap: 28px; }
.mob-menu.open { display: flex; }
.mob-menu a { font-family: 'Cormorant Garamond',serif; font-size: 2.2rem; font-weight: 700; color: var(--white); text-decoration: none; transition: color .25s; }
.mob-menu a:hover { color: var(--gold); }
.mob-close { position:absolute; top:22px; right:22px; background:none; border:none; color:var(--gold); font-size:1.5rem; cursor:pointer; }

/* ══════════════════════════════════════
   HERO — VIDEO BACKGROUND
══════════════════════════════════════ */
.hero {
    position: relative; height: 100vh; min-height: 680px;
    display: flex; align-items: center;
    overflow: hidden; background: var(--navy);
}

/* The <video> fills the hero like object-fit: cover */
.hero-video {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    object-fit: cover; object-position: center 30%;
    z-index: 0;
    /* slight desaturation so the gold palette pops */
    filter: saturate(0.55) brightness(0.6);
    transition: opacity 1.5s ease;
    opacity: 0;
}
.hero-video.loaded { opacity: 1; }

/* Multi-layer overlay:
   1. deep navy vignette on the right (keeps text readable)
   2. subtle gold-tinted gradient on the left (brand warmth)
   3. diagonal lines texture (same as original) */
.hero-overlay {
    position: absolute; inset: 0; z-index: 1;
    background:
        linear-gradient(100deg,
            rgba(6,14,31,.82) 0%,
            rgba(6,14,31,.65) 45%,
            rgba(6,14,31,.30) 100%),
        linear-gradient(to bottom,
            rgba(6,14,31,.35) 0%,
            transparent 30%,
            transparent 70%,
            rgba(6,14,31,.55) 100%);
}
.hero-lines {
    position: absolute; inset: 0; z-index: 2; pointer-events: none;
    background-image: repeating-linear-gradient(-48deg, transparent, transparent 44px, rgba(201,168,76,.018) 44px, rgba(201,168,76,.018) 88px);
}
/* Decorative arc — kept from original */
.hero-arc {
    position: absolute; right: -100px; top: 50%; transform: translateY(-50%);
    width: 600px; height: 600px; border-radius: 50%;
    border: 1px solid rgba(201,168,76,.10); z-index: 2;
}
.hero-arc::before { content:''; position:absolute; inset:40px; border-radius:50%; border:1px solid rgba(201,168,76,.07); }
.hero-arc::after  { content:''; position:absolute; inset:80px; border-radius:50%; border:1px solid rgba(201,168,76,.05); }

/* Content sits above all overlays */
.hero-content {
    position: relative; z-index: 3;
    max-width: 1220px; margin: 0 auto; padding: 0 44px; padding-top: 70px;
}
.hero-pill { display:inline-flex; align-items:center; gap:10px; background:rgba(201,168,76,.12); border:1px solid rgba(201,168,76,.30); border-radius:30px; padding:6px 18px; margin-bottom:28px; animation:riseUp .9s ease both; backdrop-filter:blur(8px); }
.hero-pill-dot { width:6px; height:6px; border-radius:50%; background:var(--gold); animation:throb 2s infinite; }
.hero-pill span { font-size:11px; letter-spacing:2.2px; text-transform:uppercase; color:var(--gold); font-weight:600; }
@keyframes throb { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(1.6)} }
.hero h1 { font-family:'Cormorant Garamond',serif; font-size:clamp(3rem,6vw,5.8rem); font-weight:700; line-height:1.04; color:var(--white); margin-bottom:10px; animation:riseUp .9s .1s ease both; text-shadow:0 2px 20px rgba(0,0,0,.4); }
.hero h1 em { font-style:italic; color:var(--gold); }
.hero-motto { font-family:'Cormorant Garamond',serif; font-size:clamp(1.1rem,2.2vw,1.65rem); color:var(--gold-lt); font-weight:600; letter-spacing:.6px; margin-bottom:22px; animation:riseUp .9s .2s ease both; }
.hero-desc { max-width:520px; font-size:15.5px; line-height:1.75; color:rgba(255,255,255,.68); margin-bottom:40px; animation:riseUp .9s .3s ease both; }
.hero-btns { display:flex; gap:14px; flex-wrap:wrap; animation:riseUp .9s .4s ease both; }
.btn-gold { display:inline-flex; align-items:center; gap:9px; background:var(--gold); color:var(--navy); padding:14px 30px; border-radius:6px; font-weight:700; font-size:14px; text-decoration:none; border:none; cursor:pointer; transition:all .25s; }
.btn-gold:hover { background:var(--gold-lt); transform:translateY(-2px); box-shadow:0 8px 28px rgba(201,168,76,.38); }
.btn-ghost-white { display:inline-flex; align-items:center; gap:9px; background:rgba(255,255,255,.08); color:var(--white); padding:14px 30px; border-radius:6px; font-weight:500; font-size:14px; text-decoration:none; border:1.5px solid rgba(255,255,255,.28); transition:all .25s; backdrop-filter:blur(6px); }
.btn-ghost-white:hover { border-color:var(--gold); color:var(--gold); transform:translateY(-2px); background:rgba(201,168,76,.08); }

/* Stats strip */
.hero-strip {
    position:absolute; bottom:42px; left:50%; transform:translateX(-50%);
    display:flex; background:rgba(6,14,31,.55); backdrop-filter:blur(20px);
    border:1px solid rgba(201,168,76,.20); border-radius:14px; overflow:hidden;
    animation:riseUp .9s .65s ease both; z-index:3;
}
.h-stat { padding:18px 38px; text-align:center; border-right:1px solid rgba(201,168,76,.12); }
.h-stat:last-child { border-right:none; }
.h-stat-num { font-family:'Cormorant Garamond',serif; font-size:2rem; font-weight:700; color:var(--gold); line-height:1; margin-bottom:3px; }
.h-stat-lbl { font-size:10.5px; color:rgba(255,255,255,.45); letter-spacing:1.2px; text-transform:uppercase; }
@keyframes riseUp { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }

/* Video mute/unmute floating button */
.hero-vid-ctrl {
    position: absolute; bottom: 48px; right: 44px; z-index: 4;
    width: 38px; height: 38px; border-radius: 50%;
    background: rgba(6,14,31,.55); border: 1px solid rgba(201,168,76,.28);
    backdrop-filter: blur(10px);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: rgba(255,255,255,.6); font-size: 13px;
    transition: all .25s; animation: riseUp .9s .8s ease both;
}
.hero-vid-ctrl:hover { background: rgba(201,168,76,.18); color: var(--gold); border-color: var(--gold); }

/* ══ SHARED SECTION ══ */
.section-tag { display:inline-block; font-size:10px; letter-spacing:3px; text-transform:uppercase; font-weight:700; color:var(--gold-dk); background:rgba(201,168,76,.1); border-left:3px solid var(--gold); padding:4px 13px; margin-bottom:18px; border-radius:0 3px 3px 0; }
.section-h   { font-family:'Cormorant Garamond',serif; font-size:clamp(1.9rem,4vw,2.9rem); font-weight:700; color:var(--navy); line-height:1.15; margin-bottom:14px; }
.section-h em { font-style:italic; color:var(--gold-dk); }
.section-sub { font-size:15px; color:var(--muted); line-height:1.75; max-width:580px; }
.reveal { opacity:0; transform:translateY(36px); transition:all .7s ease; }
.reveal.in { opacity:1; transform:translateY(0); }

/* ══ WHY ══ */
.why { background:var(--navy); padding:96px 44px; }
.why-inner { max-width:1220px; margin:0 auto; }
.why .section-h { color:#fff; }
.why .section-tag { color:var(--gold-lt); background:rgba(201,168,76,.07); }
.why .section-sub { color:rgba(255,255,255,.5); }
.pillars { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:22px; margin-top:56px; }
.pillar { background:rgba(255,255,255,.035); border:1px solid rgba(201,168,76,.1); border-radius:16px; padding:34px 26px; transition:all .38s; position:relative; overflow:hidden; }
.pillar::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--gold),var(--gold-lt)); transform:scaleX(0); transition:transform .38s; transform-origin:left; }
.pillar:hover { background:rgba(255,255,255,.06); transform:translateY(-6px); box-shadow:0 20px 55px rgba(0,0,0,.28); }
.pillar:hover::before { transform:scaleX(1); }
.pillar-ico   { width:50px; height:50px; border-radius:13px; background:rgba(201,168,76,.1); border:1px solid rgba(201,168,76,.18); display:flex; align-items:center; justify-content:center; font-size:20px; color:var(--gold); margin-bottom:20px; }
.pillar-title { font-family:'Cormorant Garamond',serif; font-size:1.2rem; font-weight:700; color:#fff; margin-bottom:11px; }
.pillar-text  { font-size:13.5px; color:rgba(255,255,255,.46); line-height:1.8; }

/* ══ PROGRAMS ══ */
.programs-wrap  { background:var(--cream); padding:96px 44px; }
.programs-inner { max-width:1220px; margin:0 auto; }
.programs-grid  { display:grid; grid-template-columns:repeat(3,1fr); gap:0; margin-top:56px; border-radius:18px; overflow:hidden; border:1px solid rgba(6,14,31,.09); }
.prog-card { background:var(--white); padding:40px 30px; border-right:1px solid rgba(6,14,31,.07); border-bottom:1px solid rgba(6,14,31,.07); transition:all .3s; }
.prog-card:hover { background:var(--navy); transform:scale(1.02); z-index:1; box-shadow:0 20px 55px rgba(6,14,31,.28); }
.prog-card:hover .prog-title,.prog-card:hover .prog-age,.prog-card:hover .prog-text { color:var(--gold); }
.prog-card:hover .prog-age,.prog-card:hover .prog-text { color:rgba(255,255,255,.55); }
.prog-card:hover .prog-feat { color:rgba(255,255,255,.65); border-color:rgba(255,255,255,.08); }
.prog-lvl   { font-size:10px; letter-spacing:2.2px; text-transform:uppercase; font-weight:700; color:var(--gold-dk); margin-bottom:9px; }
.prog-title { font-family:'Cormorant Garamond',serif; font-size:1.4rem; font-weight:700; color:var(--navy); margin-bottom:5px; transition:color .3s; }
.prog-age   { font-size:12.5px; color:var(--muted); margin-bottom:16px; transition:color .3s; }
.prog-text  { font-size:13.5px; color:var(--muted); line-height:1.75; margin-bottom:20px; transition:color .3s; }
.prog-feats { list-style:none; display:flex; flex-direction:column; gap:7px; }
.prog-feat  { font-size:12.5px; color:var(--muted); border-top:1px solid rgba(6,14,31,.06); padding-top:7px; display:flex; align-items:center; gap:8px; transition:all .3s; }
.prog-feat i { color:var(--gold); font-size:10px; }

/* ══ TESTIMONIALS ══ */
.tests-wrap  { background:var(--cream); padding:96px 44px; }
.tests-inner { max-width:1220px; margin:0 auto; }
.tests-grid  { display:grid; grid-template-columns:repeat(3,1fr); gap:22px; margin-top:56px; }
.tcard { background:var(--white); border:1px solid rgba(6,14,31,.07); border-radius:16px; padding:30px; position:relative; transition:all .3s; }
.tcard::before { content:'\201C'; position:absolute; top:18px; right:24px; font-size:4.5rem; font-family:'Cormorant Garamond',serif; color:rgba(201,168,76,.1); line-height:1; }
.tcard:hover { box-shadow:0 12px 38px rgba(6,14,31,.1); transform:translateY(-4px); }
.tcard-stars  { color:var(--gold); font-size:12px; margin-bottom:12px; letter-spacing:2px; }
.tcard-text   { font-size:13.5px; color:var(--muted); line-height:1.82; margin-bottom:22px; font-style:italic; }
.tcard-author { display:flex; align-items:center; gap:11px; }
.tcard-avi    { width:40px; height:40px; border-radius:50%; background:var(--navy); display:flex; align-items:center; justify-content:center; font-family:'Cormorant Garamond',serif; font-size:15px; font-weight:700; color:var(--gold); }
.tcard-name   { font-weight:600; font-size:13.5px; color:var(--navy); }
.tcard-role   { font-size:11.5px; color:var(--muted); }

/* ══ CTA ══ */
.cta-band  { background:linear-gradient(130deg,var(--gold-dk) 0%,var(--gold) 50%,var(--gold-lt) 100%); padding:76px 44px; text-align:center; }
.cta-inner { max-width:680px; margin:0 auto; }
.cta-h    { font-family:'Cormorant Garamond',serif; font-size:clamp(1.9rem,4vw,2.9rem); font-weight:700; color:var(--navy); margin-bottom:12px; }
.cta-text { font-size:15px; color:rgba(6,14,31,.68); line-height:1.75; margin-bottom:34px; }
.wa-btn   { display:inline-flex; align-items:center; gap:11px; background:var(--navy); color:var(--white); padding:16px 40px; border-radius:50px; font-weight:700; font-size:15px; text-decoration:none; transition:all .3s; box-shadow:0 8px 28px rgba(6,14,31,.28); }
.wa-btn:hover { transform:translateY(-3px); box-shadow:0 14px 38px rgba(6,14,31,.38); background:#0A1E3C; }
.wa-btn i { font-size:20px; color:#25D366; }

/* ══ PORTAL / AUTH ══ */
.portal       { background:var(--navy); padding:96px 44px; }
.portal-inner { max-width:1220px; margin:0 auto; display:grid; grid-template-columns:1fr 480px; gap:80px; align-items:center; }
.portal .section-h   { color:#fff; }
.portal .section-tag { color:var(--gold-lt); background:rgba(201,168,76,.07); }
.portal .section-sub { color:rgba(255,255,255,.5); margin-bottom:34px; }
.portal-feats { list-style:none; display:flex; flex-direction:column; gap:13px; }
.portal-feat  { display:flex; align-items:center; gap:12px; font-size:13.5px; color:rgba(255,255,255,.62); }
.portal-feat i { width:27px; height:27px; border-radius:50%; background:rgba(201,168,76,.1); display:flex; align-items:center; justify-content:center; font-size:11px; color:var(--gold); flex-shrink:0; }
.auth-card { background:rgba(255,255,255,.045); border:1px solid rgba(201,168,76,.14); border-radius:20px; overflow:hidden; backdrop-filter:blur(18px); }
.auth-tabs { display:flex; border-bottom:1px solid rgba(201,168,76,.1); }
.auth-tab  { flex:1; padding:15px 10px; text-align:center; font-size:12.5px; font-weight:600; color:rgba(255,255,255,.32); cursor:pointer; background:none; border:none; font-family:'Jost',sans-serif; transition:all .22s; border-bottom:2px solid transparent; margin-bottom:-1px; }
.auth-tab:hover { color:rgba(255,255,255,.6); }
.auth-tab.on { color:var(--gold); border-bottom-color:var(--gold); }
.auth-panel    { display:none; padding:26px 30px 32px; }
.auth-panel.on { display:block; }
.ap-title { font-family:'Cormorant Garamond',serif; font-size:1.3rem; font-weight:700; color:#fff; margin-bottom:4px; }
.ap-sub   { font-size:12.5px; color:rgba(255,255,255,.32); margin-bottom:20px; }
.role-row { display:flex; background:rgba(255,255,255,.045); border:1px solid rgba(255,255,255,.07); border-radius:9px; padding:4px; margin-bottom:18px; gap:4px; }
.role-btn { flex:1; padding:8px 6px; border:none; border-radius:7px; background:transparent; color:rgba(255,255,255,.38); font-size:12px; font-weight:600; font-family:'Jost',sans-serif; cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:6px; }
.role-btn i { font-size:11px; }
.role-btn.on { background:rgba(201,168,76,.16); color:var(--gold); border:1px solid rgba(201,168,76,.28); }
.fg { margin-bottom:13px; }
.fl { display:block; font-size:10.5px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:rgba(255,255,255,.38); margin-bottom:5px; }
.iw { position:relative; }
.iw .ico { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:rgba(255,255,255,.25); font-size:12px; pointer-events:none; }
.fc { width:100%; padding:11px 14px 11px 40px; background:rgba(255,255,255,.055); border:1px solid rgba(255,255,255,.09); border-radius:9px; color:#fff; font-size:13.5px; font-family:'Jost',sans-serif; outline:none; transition:all .25s; }
.fc:focus { border-color:var(--gold); background:rgba(255,255,255,.08); box-shadow:0 0 0 3px rgba(201,168,76,.1); }
.fc::placeholder { color:rgba(255,255,255,.18); }
.hint { background:rgba(201,168,76,.065); border:1px solid rgba(201,168,76,.16); border-radius:8px; padding:9px 12px; margin-bottom:13px; font-size:12px; color:rgba(201,168,76,.78); line-height:1.6; display:flex; gap:8px; align-items:flex-start; }
.hint i { margin-top:2px; flex-shrink:0; color:var(--gold); }
.username-hint-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(201,168,76,.1); border:1px solid rgba(201,168,76,.22); border-radius:20px; padding:4px 11px; font-size:11px; font-weight:600; color:var(--gold); letter-spacing:.04em; margin-bottom:12px; }
.btn-login,.btn-reg { width:100%; padding:12px; border:none; border-radius:9px; font-weight:700; font-size:14.5px; cursor:pointer; font-family:'Jost',sans-serif; letter-spacing:.4px; transition:all .28s; margin-top:4px; }
.btn-login { background:var(--gold); color:var(--navy); }
.btn-login:hover { background:var(--gold-lt); transform:translateY(-1px); box-shadow:0 6px 22px rgba(201,168,76,.38); }
.btn-reg { background:rgba(201,168,76,.12); color:var(--gold); border:1px solid rgba(201,168,76,.26); }
.btn-reg:hover { background:rgba(201,168,76,.2); }
.err-box { background:rgba(180,40,40,.18); border:1px solid rgba(180,40,40,.38); color:#FF9090; padding:10px 13px; border-radius:8px; margin-bottom:14px; font-size:12.5px; display:flex; align-items:center; gap:8px; }
.ok-box  { background:rgba(20,120,60,.2); border:1px solid rgba(20,120,60,.4); color:#70E0A0; padding:10px 13px; border-radius:8px; margin-bottom:14px; font-size:12.5px; display:flex; align-items:center; gap:8px; }
.sw { margin-top:15px; text-align:center; font-size:12px; color:rgba(255,255,255,.24); }
.sw a { color:var(--gold); text-decoration:none; font-weight:600; cursor:pointer; }

/* ══ CONTACTS ══ */
.contacts       { background:var(--cream-dk); padding:96px 44px; }
.contacts-inner { max-width:1220px; margin:0 auto; }
.contacts-grid  { display:grid; grid-template-columns:repeat(3,1fr); gap:22px; margin-top:56px; }
.c-card { background:var(--white); border-radius:15px; padding:34px; border-top:4px solid var(--gold); transition:all .3s; }
.c-card:hover { box-shadow:0 12px 38px rgba(6,14,31,.1); transform:translateY(-4px); }
.c-ico  { font-size:1.5rem; color:var(--gold-dk); margin-bottom:14px; }
.c-lbl  { font-size:10.5px; letter-spacing:2px; text-transform:uppercase; font-weight:700; color:var(--muted); margin-bottom:7px; }
.c-val  { font-size:15.5px; font-weight:600; color:var(--navy); text-decoration:none; transition:color .25s; }
.c-val:hover { color:var(--gold-dk); }
.c-note { font-size:12.5px; color:var(--muted); margin-top:7px; }

/* ══ FOOTER ══ */
footer { background:var(--navy); border-top:1px solid rgba(201,168,76,.08); padding:48px 44px 28px; }
.footer-inner      { max-width:1220px; margin:0 auto; display:flex; justify-content:space-between; align-items:flex-start; gap:40px; flex-wrap:wrap; }
.footer-brand-name { font-family:'Cormorant Garamond',serif; font-size:17px; font-weight:700; color:var(--gold); }
.footer-tagline    { font-size:12.5px; color:rgba(255,255,255,.35); font-style:italic; margin-top:6px; }
.footer-note       { font-size:12.5px; color:rgba(255,255,255,.25); margin-top:10px; max-width:260px; line-height:1.65; }
.fl-group h4       { font-size:10.5px; letter-spacing:2.2px; text-transform:uppercase; font-weight:700; color:var(--gold); margin-bottom:14px; }
.fl-group ul       { list-style:none; display:flex; flex-direction:column; gap:9px; }
.fl-group a        { font-size:12.5px; color:rgba(255,255,255,.4); text-decoration:none; transition:color .25s; }
.fl-group a:hover  { color:var(--gold); }
.footer-bottom { max-width:1220px; margin:36px auto 0; padding-top:22px; border-top:1px solid rgba(255,255,255,.05); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.footer-copy   { font-size:11.5px; color:rgba(255,255,255,.22); }
.socials       { display:flex; gap:12px; }
.social-btn    { width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.09); display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,.36); text-decoration:none; font-size:12px; transition:all .25s; }
.social-btn:hover { background:rgba(201,168,76,.13); border-color:var(--gold); color:var(--gold); }

/* ══ RESPONSIVE ══ */
@media(max-width:1100px) { .portal-inner { grid-template-columns:1fr; gap:44px; } }
@media(max-width:1024px) { .programs-grid { grid-template-columns:repeat(2,1fr); } .tests-grid { grid-template-columns:repeat(2,1fr); } .contacts-grid { grid-template-columns:1fr; } }
@media(max-width:768px) {
    nav { padding:0 20px; }
    .nav-links { display:none; }
    .hamburger { display:flex; }
    .nav-install-btn { font-size:11px; padding:6px 12px; }
    .hero-content { padding:0 20px; padding-top:70px; }
    .hero-strip { display:none; }
    .hero h1 { font-size:2.6rem; }
    .hero-vid-ctrl { bottom:16px; right:16px; }
    .why,.programs-wrap,.tests-wrap,.cta-band,.portal,.contacts { padding:68px 20px; }
    .programs-grid,.tests-grid { grid-template-columns:1fr; }
    .hero-btns { flex-direction:column; align-items:flex-start; }
    .auth-panel { padding:18px 16px 24px; }
    footer { padding:38px 20px 20px; }
    #install-fab { bottom:16px; right:14px; padding:10px 16px 10px 12px; }
}
</style>
</head>
<body>

<!-- ══ SPLASH ══ -->
<div id="app-splash">
  <div class="sp-rings">
    <div class="sp-ring sp-r1"></div>
    <div class="sp-ring sp-r2"></div>
    <div class="sp-ring sp-r3"></div>
    <div class="sp-logo-wrap"><?= lf_logo(84) ?></div>
  </div>
  <div class="sp-name">Little Friends Schools</div>
  <div class="sp-motto">Strong Foundations, Brighter Futures</div>
  <div class="sp-bar"><div class="sp-bar-fill"></div></div>
</div>

<!-- ══ INSTALL FAB ══ -->
<div id="install-fab">
  <div id="install-fab-close" onclick="closeFab()"><i class="fa fa-times"></i></div>
  <div class="fab-icon"><?= lf_logo(22) ?></div>
  <div>
    <div class="fab-text-top">Install App</div>
    <div class="fab-text-sub">Add to home screen</div>
  </div>
  <div class="fab-arrow"><i class="fa fa-download"></i></div>
</div>

<!-- ══ PWA BANNER ══ -->
<div id="pwa-banner">
  <div class="pwa-banner-icon"><?= lf_logo(28) ?></div>
  <div class="pwa-banner-body">
    <div class="pwa-banner-title">Install LF Schools App</div>
    <div class="pwa-banner-sub">Add to home screen — works offline too!</div>
  </div>
  <div class="pwa-banner-btns">
    <button class="pwa-install-btn" id="pwa-install-btn">Install</button>
    <button class="pwa-dismiss-btn" id="pwa-dismiss-btn">Not now</button>
  </div>
</div>

<!-- ══ iOS BANNER ══ -->
<div id="ios-banner">
  <div class="ios-banner-title">📱 Install LF Schools App</div>
  <div class="ios-banner-steps">
    Tap the <strong>Share</strong> button at the bottom of your browser,<br>
    then choose <strong>"Add to Home Screen"</strong>.
  </div>
  <button class="ios-dismiss" onclick="dismissIOS()">Got it, thanks!</button>
</div>

<!-- ══ NAV ══ -->
<nav id="mainNav">
  <a href="#" class="nav-brand">
    <?= lf_logo(42, 'nav-logo-svg') ?>
    <div class="nav-brand-wrap">
      <span class="nav-brand-name">Little Friends Schools</span>
      <span class="nav-brand-sub">Est. 2010 — Bomet, Sotik</span>
    </div>
  </a>
  <ul class="nav-links">
    <li><a href="#about">About</a></li>
    <li><a href="#programs">Programs</a></li>
    <li><a href="#testimonials">Parents</a></li>
    <li><a href="#contact">Contact</a></li>
    <li><a href="#portal" class="nav-portal">Portal</a></li>
    <li><button class="nav-install-btn" id="nav-install-btn"><i class="fa fa-download"></i> Install App</button></li>
  </ul>
  <button class="hamburger" onclick="document.getElementById('mobMenu').classList.add('open')">
    <span></span><span></span><span></span>
  </button>
</nav>

<div class="mob-menu" id="mobMenu">
  <button class="mob-close" onclick="document.getElementById('mobMenu').classList.remove('open')"><i class="fa fa-times"></i></button>
  <a href="#about"        onclick="closeMob()">About</a>
  <a href="#programs"     onclick="closeMob()">Programs</a>
  <a href="#testimonials" onclick="closeMob()">Parents</a>
  <a href="#contact"      onclick="closeMob()">Contact</a>
  <a href="#portal"       onclick="closeMob()" style="color:var(--gold)">Portal</a>
</div>

<!-- ══════════════════════════════════════
     HERO — VIDEO BACKGROUND
     
     Video sources (royalty-free, no attribution required):
     Primary  : Pexels CDN — children in school / classroom
     Fallback : a second Pexels clip so there's always video
     
     NOTE: If you want to host your own video instead, replace
     the <source> src values with your own file paths, e.g.:
         <source src="/assets/hero.mp4" type="video/mp4">
══════════════════════════════════════ -->
<section class="hero">

  <!--
    Royalty-free school/kids videos from Pexels (no sign-in needed, free for commercial use).
    We use two sources so one loads fast; the browser picks the first it can decode.

    Video 1 (primary)  — "Children learning in classroom" — Pexels #6210932
    Video 2 (fallback) — "Kids studying together" — Pexels #8923994
    
    If the Pexels direct link ever rotates, swap in any other royalty-free MP4.
    Recommended free sources: pexels.com, pixabay.com, coverr.co
  -->
  <video
    class="hero-video"
    id="heroVideo"
    autoplay
    muted
    loop
    playsinline
    preload="metadata"
    poster=""
  >
    <!-- Primary clip — children in a bright classroom, reading & playing -->
    <source src="https://videos.pexels.com/video-files/6210932/6210932-uhd_2560_1440_25fps.mp4" type="video/mp4">
    <!-- Fallback clip — kids studying at desks -->
    <source src="https://videos.pexels.com/video-files/8923994/8923994-uhd_2560_1440_25fps.mp4" type="video/mp4">
  </video>

  <!-- Layered overlay (keeps text crisp over any video frame) -->
  <div class="hero-overlay"></div>
  <div class="hero-lines"></div>
  <div class="hero-arc"></div>

  <!-- Hero content -->
  <div class="hero-content">
    <div class="hero-pill">
      <div class="hero-pill-dot"></div>
      <span>Now Enrolling &mdash; 2025/2026 Academic Year</span>
    </div>
    <h1>Where Every Child<br><em>Discovers</em> Greatness</h1>
    <div class="hero-motto">Strong Foundations, Brighter Futures</div>
    <p class="hero-desc">Little Friends Schools has been shaping confident, curious, and compassionate young leaders for over a decade in Bomet, Sotik.</p>
    <div class="hero-btns">
      <a href="https://wa.me/254797583976?text=Hello%20Little%20Friends%20Schools!%20I'd%20like%20to%20request%20admission%20information." target="_blank" class="btn-gold">
        <i class="fa-brands fa-whatsapp"></i>Apply for Admission
      </a>
      <a href="#programs" class="btn-ghost-white">
        <i class="fa fa-graduation-cap"></i>Explore Programs
      </a>
    </div>
  </div>

  <!-- Stats strip -->
  <div class="hero-strip">
    <div class="h-stat"><div class="h-stat-num" id="hn0">16+</div><div class="h-stat-lbl">Years of Excellence</div></div>
    <div class="h-stat"><div class="h-stat-num" id="hn1">300+</div><div class="h-stat-lbl">Happy Students</div></div>
    <div class="h-stat"><div class="h-stat-num" id="hn2">98%</div><div class="h-stat-lbl">Pass Rate</div></div>
    <div class="h-stat"><div class="h-stat-num" id="hn3">10+</div><div class="h-stat-lbl">Qualified Staff</div></div>
  </div>

  <!-- Mute / unmute toggle -->
  <button class="hero-vid-ctrl" id="vidMuteBtn" title="Toggle sound" aria-label="Toggle video sound">
    <i class="fa fa-volume-xmark" id="vidMuteIcon"></i>
  </button>

</section>

<!-- ══ WHY US ══ -->
<section class="why" id="about">
  <div class="why-inner">
    <div class="reveal">
      <div class="section-tag">Why Little Friends</div>
      <h2 class="section-h">Education Beyond <em>the Classroom</em></h2>
      <p class="section-sub">We believe every child is uniquely gifted. Our holistic approach blends academic rigour with character, arts, sport, and life skills.</p>
    </div>
    <div class="pillars">
      <div class="pillar reveal">
        <div class="pillar-ico"><i class="fa fa-star"></i></div>
        <div class="pillar-title">Academic Excellence</div>
        <p class="pillar-text">CBE-aligned curriculum delivered by experienced, passionate teachers who make learning engaging and meaningful for every learner.</p>
      </div>
      <div class="pillar reveal">
        <div class="pillar-ico"><i class="fa fa-shield-heart"></i></div>
        <div class="pillar-title">Safe &amp; Nurturing</div>
        <p class="pillar-text">A secure, inclusive environment where every child feels valued, respected, and supported to reach their full potential.</p>
      </div>
      <div class="pillar reveal">
        <div class="pillar-ico"><i class="fa fa-users"></i></div>
        <div class="pillar-title">Parent Partnership</div>
        <p class="pillar-text">Regular updates, open days, and our digital portal keep families actively involved in every step of their child's journey.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══ PROGRAMS ══ -->
<section class="programs-wrap" id="programs">
  <div class="programs-inner">
    <div class="reveal">
      <div class="section-tag">Academic Programs</div>
      <h2 class="section-h">A Journey from <em>Playgroup</em></h2>
      <p class="section-sub">Expertly designed pathways for every stage of childhood, built on the Kenyan CBE curriculum.</p>
    </div>
    <div class="programs-grid reveal">
      <div class="prog-card">
        <div class="prog-lvl">Foundation</div>
        <div class="prog-title">Playgroup &amp; Pre-Primary</div>
        <div class="prog-age">Ages 2 – 5 years</div>
        <p class="prog-text">Play-based start where children develop social skills, language, numeracy, and curiosity in a loving, stimulating environment.</p>
        <ul class="prog-feats">
          <li class="prog-feat"><i class="fa fa-check"></i>Certified ECD Teachers</li>
          <li class="prog-feat"><i class="fa fa-check"></i>Low learner-to-teacher ratio</li>
          <li class="prog-feat"><i class="fa fa-check"></i>Montessori resources</li>
        </ul>
      </div>
      <div class="prog-card">
        <div class="prog-lvl">Primary</div>
        <div class="prog-title">Lower Primary</div>
        <div class="prog-age">Grade 1 – 3</div>
        <p class="prog-text">Building strong literacy, numeracy, and critical thinking skills through the CBC framework in a child-centred classroom.</p>
        <ul class="prog-feats">
          <li class="prog-feat"><i class="fa fa-check"></i>CBC curriculum</li>
          <li class="prog-feat"><i class="fa fa-check"></i>Digital learning tools</li>
          <li class="prog-feat"><i class="fa fa-check"></i>Remedial support</li>
        </ul>
      </div>
      <div class="prog-card">
        <div class="prog-lvl">Primary</div>
        <div class="prog-title">Upper Primary</div>
        <div class="prog-age">Grade 4 – 6</div>
        <p class="prog-text">Deepening subject mastery and preparing confident, well-rounded learners for the next stage of their academic journey.</p>
        <ul class="prog-feats">
          <li class="prog-feat"><i class="fa fa-check"></i>STEM focus</li>
          <li class="prog-feat"><i class="fa fa-check"></i>Co-curricular activities</li>
          <li class="prog-feat"><i class="fa fa-check"></i>Leadership programs</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ══ TESTIMONIALS ══ -->
<section class="tests-wrap" id="testimonials">
  <div class="tests-inner">
    <div class="reveal">
      <div class="section-tag">Parent Voices</div>
      <h2 class="section-h">What Our <em>Families</em> Say</h2>
      <p class="section-sub">Parents trust us with their most precious gift. Here's what the Little Friends community says.</p>
    </div>
    <div class="tests-grid">
      <div class="tcard reveal">
        <div class="tcard-stars">★★★★★</div>
        <p class="tcard-text">"My daughter has blossomed since joining Little Friends. Her confidence, reading, love for school — everything improved dramatically. The teachers genuinely care."</p>
        <div class="tcard-author">
          <div class="tcard-avi">LC</div>
          <div><div class="tcard-name">LAURINE CHEPKORIR</div><div class="tcard-role">Parent — Playgroup</div></div>
        </div>
      </div>
      <div class="tcard reveal">
        <div class="tcard-stars">★★★★★</div>
        <p class="tcard-text">"The school portal keeps us updated in real time — grades, fees, attendance. As a busy parent this digital system is a total game-changer."</p>
        <div class="tcard-author">
          <div class="tcard-avi">WC</div>
          <div><div class="tcard-name">WINNY CHEBET</div><div class="tcard-role">Parent — Pre-Primary 2</div></div>
        </div>
      </div>
      <div class="tcard reveal">
        <div class="tcard-stars">★★★★★</div>
        <p class="tcard-text">"Three children, all at Little Friends. The values they're learning — integrity, discipline, kindness — will serve them long after exams."</p>
        <div class="tcard-author">
          <div class="tcard-avi">KT</div>
          <div><div class="tcard-name">KOECH TOO</div><div class="tcard-role">Parent — Pre-Primary 1</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ CTA ══ -->
<section class="cta-band">
  <div class="cta-inner reveal">
    <h2 class="cta-h">Give Your Child the Best Start in Life</h2>
    <p class="cta-text">Spaces for 2025/2026 are filling fast. Send us a WhatsApp message today to request an admission form, schedule a tour, or ask any questions.</p>
    <a href="https://wa.me/254797583976?text=Hello!%20I'd%20like%20to%20enquire%20about%20admission%20at%20Little%20Friends%20Schools." target="_blank" class="wa-btn">
      <i class="fa-brands fa-whatsapp"></i>Request Admission via WhatsApp
    </a>
    <p style="margin-top:18px;font-size:12.5px;color:rgba(6,14,31,.58)">Chat with our admissions team — <strong>+254 797 583 976</strong></p>
  </div>
</section>

<!-- ══ PORTAL / AUTH ══ -->
<section class="portal" id="portal">
  <div class="portal-inner">
    <div class="portal-left reveal">
      <div class="section-tag">Digital Portal</div>
      <h2 class="section-h">Everything in <em>One Place</em></h2>
      <p class="section-sub">Our school management system keeps students, teachers, and parents connected and informed at all times.</p>
      <ul class="portal-feats">
        <li class="portal-feat"><i class="fa fa-chart-line"></i>Real-time academic performance &amp; report cards</li>
        <li class="portal-feat"><i class="fa fa-calendar-check"></i>Attendance tracking and daily notifications</li>
        <li class="portal-feat"><i class="fa fa-credit-card"></i>Fee statements and payment history</li>
        <li class="portal-feat"><i class="fa fa-bell"></i>Announcements, timetables, and circulars</li>
        <li class="portal-feat"><i class="fa fa-message"></i>Direct messaging between teachers and parents</li>
        <li class="portal-feat"><i class="fa fa-book"></i>Homework assignments and digital resources</li>
      </ul>
    </div>

    <!-- AUTH CARD -->
    <div class="auth-card reveal">
      <div class="auth-tabs">
        <button class="auth-tab <?= $show_panel==='login'    ? 'on' : '' ?>" id="tabLogin" onclick="switchTab('login')">
          <i class="fa fa-arrow-right-to-bracket" style="margin-right:5px"></i>Sign In
        </button>
        <button class="auth-tab <?= $show_panel==='register' ? 'on' : '' ?>" id="tabReg" onclick="switchTab('register')">
          <i class="fa fa-user-plus" style="margin-right:5px"></i>First Time? Register
        </button>
      </div>

      <!-- LOGIN PANEL -->
      <div class="auth-panel <?= $show_panel==='login' ? 'on' : '' ?>" id="panelLogin">
        <div class="ap-title">Welcome Back</div>
        <div class="ap-sub">Select your role and sign in below</div>
        <?php if (isset($error)): ?>
          <div class="err-box"><i class="fa fa-circle-exclamation"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($reg_success)): ?>
          <div class="ok-box"><i class="fa fa-circle-check"></i><?= $reg_success ?> You can now sign in.</div>
        <?php endif; ?>
        <div class="role-row">
          <button type="button" class="role-btn <?= $login_role==='student' ? 'on' : '' ?>" id="lrS" onclick="switchLRole('student')"><i class="fa fa-user-graduate"></i>Student</button>
          <button type="button" class="role-btn <?= $login_role==='teacher' ? 'on' : '' ?>" id="lrT" onclick="switchLRole('teacher')"><i class="fa fa-chalkboard-user"></i>Teacher</button>
          <button type="button" class="role-btn <?= $login_role==='admin'   ? 'on' : '' ?>" id="lrA" onclick="switchLRole('admin')"><i class="fa fa-user-shield"></i>Admin</button>
        </div>
        <!-- Student Login -->
        <div id="lfS" style="<?= ($login_role!=='teacher'&&$login_role!=='admin') ? '' : 'display:none' ?>">
          <div class="hint"><i class="fa fa-circle-info"></i><span>Use your <strong>full name</strong> or <strong>admission number</strong> plus your password.</span></div>
          <form method="POST" action="">
            <input type="hidden" name="login_role" value="student">
            <div class="fg"><label class="fl">Name or Admission No.</label>
              <div class="iw"><i class="fa fa-id-card ico"></i>
                <input type="text" name="student_name" class="fc" placeholder="e.g. John Kamau or ADM-001" required value="<?= htmlspecialchars($_POST['student_name'] ?? '') ?>">
              </div></div>
            <div class="fg"><label class="fl">Password</label>
              <div class="iw"><i class="fa fa-lock ico"></i><input type="password" name="password" class="fc" placeholder="••••••••" required></div></div>
            <button type="submit" class="btn-login"><i class="fa fa-arrow-right-to-bracket" style="margin-right:7px"></i>Sign In as Student</button>
          </form>
        </div>
        <!-- Teacher Login -->
        <div id="lfT" style="<?= $login_role==='teacher' ? '' : 'display:none' ?>">
          <div class="hint"><i class="fa fa-circle-info"></i><span>Login with your <strong>phone number</strong>, <strong>@username</strong>, or <strong>full name</strong>.</span></div>
          <div class="username-hint-badge"><i class="fa fa-at" style="font-size:10px"></i>Username: <strong>Initials + last 4 digits</strong> e.g. <em>JM5678</em></div>
          <form method="POST" action="#portal">
            <input type="hidden" name="login_role" value="teacher">
            <div class="fg"><label class="fl">Phone / Username / Name</label>
              <div class="iw"><i class="fa fa-fingerprint ico"></i>
                <input type="text" name="teacher_identifier" class="fc" placeholder="e.g. 0712345678 or JM5678" required value="<?= htmlspecialchars($_POST['teacher_identifier'] ?? '') ?>">
              </div></div>
            <div class="fg"><label class="fl">Password</label>
              <div class="iw"><i class="fa fa-lock ico"></i><input type="password" name="password" class="fc" placeholder="••••••••" required></div></div>
            <button type="submit" class="btn-login"><i class="fa fa-arrow-right-to-bracket" style="margin-right:7px"></i>Sign In as Teacher</button>
          </form>
        </div>
        <!-- Admin Login -->
        <div id="lfA" style="<?= $login_role==='admin' ? '' : 'display:none' ?>">
          <div class="hint"><i class="fa fa-circle-info"></i><span>Admin uses your <strong>email address</strong> and secure password.</span></div>
          <form method="POST" action="#portal">
            <input type="hidden" name="login_role" value="admin">
            <div class="fg"><label class="fl">Admin Email</label>
              <div class="iw"><i class="fa fa-envelope ico"></i>
                <input type="email" name="email" class="fc" placeholder="admin@school.co.ke" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
              </div></div>
            <div class="fg"><label class="fl">Password</label>
              <div class="iw"><i class="fa fa-lock ico"></i><input type="password" name="password" class="fc" placeholder="••••••••" required></div></div>
            <button type="submit" class="btn-login"><i class="fa fa-user-shield" style="margin-right:7px"></i>Sign In as Admin</button>
          </form>
        </div>
        <div class="sw">New here? <a onclick="switchTab('register')">Activate your account &rarr;</a></div>
        <p style="margin-top:9px;font-size:10.5px;text-align:center;color:rgba(255,255,255,.15)">Forgot password? Contact the school office.</p>
      </div>

      <!-- REGISTER PANEL -->
      <div class="auth-panel <?= $show_panel==='register' ? 'on' : '' ?>" id="panelReg">
        <div class="ap-title">Activate Your Account</div>
        <div class="ap-sub">First time only — sets up your login credentials</div>
        <div class="role-row">
          <button type="button" class="role-btn <?= $reg_type==='student' ? 'on' : '' ?>" id="rrS" onclick="switchRRole('student')"><i class="fa fa-user-graduate"></i>I'm a Student</button>
          <button type="button" class="role-btn <?= $reg_type==='teacher' ? 'on' : '' ?>" id="rrT" onclick="switchRRole('teacher')"><i class="fa fa-chalkboard-user"></i>I'm a Teacher</button>
        </div>
        <!-- Student Register -->
        <div id="rfS" style="<?= $reg_type!=='teacher' ? '' : 'display:none' ?>">
          <?php if (isset($reg_error) && $reg_type!=='teacher'): ?>
            <div class="err-box"><i class="fa fa-circle-exclamation"></i><?= $reg_error ?></div>
          <?php endif; ?>
          <div class="hint"><i class="fa fa-circle-info"></i><span>Enter your <strong>full name</strong> or <strong>admission number</strong> as registered by the school.</span></div>
          <form method="POST" action="#portal">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="reg_role" value="student">
            <div class="fg"><label class="fl">Full Name or Admission No.</label>
              <div class="iw"><i class="fa fa-id-card ico"></i>
                <input type="text" name="student_lookup" class="fc" placeholder="e.g. John Kamau or ADM-001" required value="<?= htmlspecialchars($_POST['student_lookup'] ?? '') ?>">
              </div></div>
            <div class="fg"><label class="fl">Choose a Password</label>
              <div class="iw"><i class="fa fa-lock ico"></i><input type="password" name="reg_password" class="fc" placeholder="At least 6 characters" required></div></div>
            <div class="fg"><label class="fl">Confirm Password</label>
              <div class="iw"><i class="fa fa-lock ico"></i><input type="password" name="reg_password2" class="fc" placeholder="Repeat password" required></div></div>
            <button type="submit" class="btn-reg"><i class="fa fa-user-plus" style="margin-right:7px"></i>Verify &amp; Activate Account</button>
          </form>
        </div>
        <!-- Teacher Register -->
        <div id="rfT" style="<?= $reg_type==='teacher' ? '' : 'display:none' ?>">
          <?php if (isset($reg_error) && $reg_type==='teacher'): ?>
            <div class="err-box"><i class="fa fa-circle-exclamation"></i><?= htmlspecialchars($reg_error) ?></div>
          <?php endif; ?>
          <div class="hint"><i class="fa fa-circle-info"></i><span>Enter the <strong>phone number</strong> the school admin registered for you.</span></div>
          <form method="POST" action="#portal">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="reg_role" value="teacher">
            <div class="fg"><label class="fl">Registered Phone Number</label>
              <div class="iw"><i class="fa fa-phone ico"></i>
                <input type="text" name="teacher_phone" class="fc" placeholder="e.g. 0712345678" required value="<?= htmlspecialchars($_POST['teacher_phone'] ?? '') ?>">
              </div></div>
            <div class="fg"><label class="fl">Choose a Password</label>
              <div class="iw"><i class="fa fa-lock ico"></i><input type="password" name="reg_password" class="fc" placeholder="At least 6 characters" required></div></div>
            <div class="fg"><label class="fl">Confirm Password</label>
              <div class="iw"><i class="fa fa-lock ico"></i><input type="password" name="reg_password2" class="fc" placeholder="Repeat password" required></div></div>
            <button type="submit" class="btn-reg"><i class="fa fa-chalkboard-user" style="margin-right:7px"></i>Verify &amp; Activate Account</button>
          </form>
        </div>
        <div class="sw">Already activated? <a onclick="switchTab('login')">Sign in here &rarr;</a></div>
      </div>
    </div>
  </div>
</section>

<!-- ══ CONTACTS ══ -->
<section class="contacts" id="contact">
  <div class="contacts-inner">
    <div class="reveal">
      <div class="section-tag">Get in Touch</div>
      <h2 class="section-h">We'd Love to <em>Hear From You</em></h2>
      <p class="section-sub">Our friendly team is ready to answer questions, schedule tours, or help with any enquiry.</p>
    </div>
    <div class="contacts-grid">
      <div class="c-card reveal">
        <div class="c-ico"><i class="fa-brands fa-whatsapp"></i></div>
        <div class="c-lbl">WhatsApp / Phone</div>
        <a href="https://wa.me/254797583976" class="c-val" target="_blank">+254 797 583 976</a>
        <p class="c-note">Mon – Fri, 7am – 3pm</p>
      </div>
      <div class="c-card reveal">
        <div class="c-ico"><i class="fa fa-envelope"></i></div>
        <div class="c-lbl">Email Us</div>
        <a href="mailto:info@littlefriendsschools.ac.ke" class="c-val">info@littlefriendsschools.ac.ke</a>
        <p class="c-note">Response within 24 hours on school days</p>
      </div>
      <div class="c-card reveal">
        <div class="c-ico"><i class="fa fa-location-dot"></i></div>
        <div class="c-lbl">Find Us</div>
        <span class="c-val" style="cursor:default">Bomet-Sotik, Kenya</span>
        <p class="c-note">Main school in Bomet, Sotik-Chebole</p>
      </div>
    </div>
  </div>
</section>

<!-- ══ FOOTER ══ -->
<footer>
  <div class="footer-inner">
    <div>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
        <?= lf_logo(36) ?>
        <div class="footer-brand-name">Little Friends Schools</div>
      </div>
      <div class="footer-tagline">"Strong Foundations, Brighter Futures"</div>
      <p class="footer-note">Nurturing young minds in Bomet, Sotik-Chebole since 2010.</p>
    </div>
    <div class="fl-group">
      <h4>Quick Links</h4>
      <ul>
        <li><a href="#about">About Us</a></li>
        <li><a href="#programs">Programs</a></li>
        <li><a href="#testimonials">Parent Reviews</a></li>
        <li><a href="#contact">Contact</a></li>
      </ul>
    </div>
    <div class="fl-group">
      <h4>Portal Access</h4>
      <ul>
        <li><a href="#portal" onclick="switchTab('login');switchLRole('student')">Student Login</a></li>
        <li><a href="#portal" onclick="switchTab('login');switchLRole('teacher')">Teacher Login</a></li>
        <li><a href="#portal" onclick="switchTab('login');switchLRole('admin')">Admin Login</a></li>
        <li><a href="#portal" onclick="switchTab('register')">First Time? Register</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-copy">&copy; <?= date('Y') ?> Little Friends Schools. All rights reserved.</div>
    <div class="socials">
      <a href="#" class="social-btn"><i class="fa-brands fa-facebook-f"></i></a>
      <a href="#" class="social-btn"><i class="fa-brands fa-instagram"></i></a>
      <a href="https://wa.me/254797583976" class="social-btn" target="_blank"><i class="fa-brands fa-whatsapp"></i></a>
    </div>
  </div>
</footer>

<!-- ══════════════════════════════════════
     ALL JAVASCRIPT
══════════════════════════════════════ -->
<script>
/* ── SPLASH ── */
(function() {
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;
    var firstVisit   = !sessionStorage.getItem('sp-shown');
    var sp = document.getElementById('app-splash');
    if (isStandalone || firstVisit) {
        sessionStorage.setItem('sp-shown', '1');
        setTimeout(function() {
            sp.classList.add('sp-hide');
            setTimeout(function() { sp.style.display = 'none'; }, 700);
        }, 3400);
    } else {
        sp.style.display = 'none';
    }
})();

/* ── SERVICE WORKER ── */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then(function(r) { console.log('[PWA] SW registered:', r.scope); })
            .catch(function(e) { console.warn('[PWA] SW failed:', e); });
    });
}

/* ── HERO VIDEO ──
   Fade in when enough data is buffered; handle autoplay block gracefully */
(function() {
    var vid = document.getElementById('heroVideo');
    if (!vid) return;

    function revealVideo() { vid.classList.add('loaded'); }

    vid.addEventListener('canplay', revealVideo, { once: true });
    vid.addEventListener('loadeddata', revealVideo, { once: true });

    /* Some browsers block autoplay until user interaction — handle silently */
    var playPromise = vid.play();
    if (playPromise !== undefined) {
        playPromise.catch(function() {
            /* autoplay blocked — video stays paused, still looks fine over navy bg */
        });
    }

    /* Mute / unmute button */
    var muteBtn  = document.getElementById('vidMuteBtn');
    var muteIcon = document.getElementById('vidMuteIcon');
    if (muteBtn) {
        muteBtn.addEventListener('click', function() {
            vid.muted = !vid.muted;
            muteIcon.className = vid.muted ? 'fa fa-volume-xmark' : 'fa fa-volume-high';
        });
    }
})();

/* ── PWA INSTALL ── */
var deferredPrompt = null;
var pwaBanner    = document.getElementById('pwa-banner');
var installFab   = document.getElementById('install-fab');
var navInstBtn   = document.getElementById('nav-install-btn');

function triggerInstall() {
    if (!deferredPrompt) return;
    pwaBanner.classList.remove('show');
    installFab.classList.remove('show');
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(function(c) {
        deferredPrompt = null;
        navInstBtn.classList.remove('show');
    });
}

window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    deferredPrompt = e;
    if (!sessionStorage.getItem('pwa-dismissed')) {
        setTimeout(function() {
            pwaBanner.classList.add('show');
            installFab.classList.add('show');
            navInstBtn.classList.add('show');
        }, 4000);
    } else {
        installFab.classList.add('show');
        navInstBtn.classList.add('show');
    }
});

document.getElementById('pwa-install-btn').addEventListener('click', triggerInstall);
installFab.addEventListener('click', function(e) {
    if (e.target.closest('#install-fab-close')) return;
    triggerInstall();
});
navInstBtn.addEventListener('click', triggerInstall);
document.getElementById('pwa-dismiss-btn').addEventListener('click', function() {
    pwaBanner.classList.remove('show');
    sessionStorage.setItem('pwa-dismissed', '1');
});
function closeFab() {
    installFab.classList.remove('show');
    sessionStorage.setItem('pwa-dismissed', '1');
}
window.addEventListener('appinstalled', function() {
    pwaBanner.classList.remove('show');
    installFab.classList.remove('show');
    navInstBtn.classList.remove('show');
});

/* ── iOS INSTALL HINT ── */
(function() {
    var isIOS      = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var isSafari   = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    var isStandalone = window.navigator.standalone === true;
    if (isIOS && isSafari && !isStandalone && !sessionStorage.getItem('ios-dismissed')) {
        setTimeout(function() {
            document.getElementById('ios-banner').classList.add('show');
        }, 4500);
    }
})();
function dismissIOS() {
    document.getElementById('ios-banner').classList.remove('show');
    sessionStorage.setItem('ios-dismissed', '1');
}

/* ── NAV SCROLL ── */
function closeMob() { document.getElementById('mobMenu').classList.remove('open'); }
window.addEventListener('scroll', function() {
    document.getElementById('mainNav').style.borderBottomColor =
        window.scrollY > 40 ? 'rgba(201,168,76,.28)' : 'rgba(201,168,76,.09)';
});

/* ── AUTH TABS / ROLE SWITCHERS ── */
function switchTab(tab) {
    document.getElementById('panelLogin').classList.toggle('on', tab==='login');
    document.getElementById('panelReg').classList.toggle('on',   tab==='register');
    document.getElementById('tabLogin').classList.toggle('on',   tab==='login');
    document.getElementById('tabReg').classList.toggle('on',     tab==='register');
}
function switchLRole(role) {
    ['S','T','A'].forEach(function(r) {
        var key = {S:'student',T:'teacher',A:'admin'}[r];
        document.getElementById('lf'+r).style.display = (key===role) ? '' : 'none';
        document.getElementById('lr'+r).classList.toggle('on', key===role);
    });
}
function switchRRole(role) {
    document.getElementById('rfS').style.display = (role==='student') ? '' : 'none';
    document.getElementById('rfT').style.display = (role==='teacher') ? '' : 'none';
    document.getElementById('rrS').classList.toggle('on', role==='student');
    document.getElementById('rrT').classList.toggle('on', role==='teacher');
}

/* ── SCROLL REVEAL ── */
document.querySelectorAll('.reveal').forEach(function(el) {
    new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) entries[0].target.classList.add('in');
    }, { threshold: 0.1 }).observe(el);
});

/* ── STAT COUNTERS ── */
var counters = [
    { id:'hn0', target:16,  suffix:'+' },
    { id:'hn1', target:300, suffix:'+' },
    { id:'hn2', target:98,  suffix:'%' },
    { id:'hn3', target:10,  suffix:'+' }
];
var strip = document.querySelector('.hero-strip');
if (strip) {
    new IntersectionObserver(function(entries) {
        if (!entries[0].isIntersecting) return;
        counters.forEach(function(c) {
            var el = document.getElementById(c.id);
            if (!el) return;
            var t0 = performance.now();
            (function run(now) {
                var p = Math.min((now-t0)/1600, 1);
                el.textContent = Math.floor(p*c.target) + c.suffix;
                if (p < 1) requestAnimationFrame(run);
            })(t0);
        });
        this.disconnect();
    }).observe(strip);
}
</script>
</body>
</html>