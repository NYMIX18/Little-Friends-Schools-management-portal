<?php
session_start();
include("../config/db.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    die("Invalid request");
}

// ─────────────────────────────
// 1. CLEAN INPUT
// ─────────────────────────────
$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($email) || empty($password)) {
    die("Please enter email and password");
}

// ─────────────────────────────
// 2. FIND USER (SAFE QUERY)
// ─────────────────────────────
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");

if (!$stmt) {
    die("DB error: " . $conn->error);
}

$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

// ─────────────────────────────
// 3. CHECK USER EXISTS
// ─────────────────────────────
if ($result->num_rows !== 1) {
    die("Invalid email or password");
}

$user = $result->fetch_assoc();

// ─────────────────────────────
// 4. VERIFY HASHED PASSWORD
// ─────────────────────────────
if (!password_verify($password, $user['password'])) {
    die("Invalid email or password");
}

// ─────────────────────────────
// 5. SET SESSION SAFELY
// ─────────────────────────────
session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = strtolower(trim($user['role']));
$_SESSION['name'] = $user['name'];

// ─────────────────────────────
// 6. IF TEACHER → GET EXTRA DATA
// ─────────────────────────────
if ($_SESSION['role'] === 'teacher') {

    $stmt2 = $conn->prepare("SELECT is_class_teacher FROM teachers WHERE user_id = ?");
    $stmt2->bind_param("i", $user['id']);
    $stmt2->execute();
    $res2 = $stmt2->get_result();

    if ($row = $res2->fetch_assoc()) {
        $_SESSION['is_class_teacher'] = (int)$row['is_class_teacher'];
    } else {
        $_SESSION['is_class_teacher'] = 0;
    }
}

// ─────────────────────────────
// 7. REDIRECT BY ROLE
// ─────────────────────────────
switch ($_SESSION['role']) {

    case 'admin':
        header("Location: ../dashboards/admin.php");
        exit;

    case 'teacher':
        header("Location: ../dashboards/teacher_dashboard.php");
        exit;

    case 'student':
        header("Location: ../dashboards/student.php");
        exit;

    case 'parent':
        header("Location: ../dashboards/parent.php");
        exit;

    default:
        die("Unknown role");
}