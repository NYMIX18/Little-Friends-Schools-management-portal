<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin'){
    header("Location: ../auth/login.php");
    exit();
}

function maskPhone($phone) {
    $phone = trim($phone);
    $len = strlen($phone);
    if($len < 8) return $phone;
    return substr($phone, 0, 4) . str_repeat('*', $len - 6) . substr($phone, -2);
}

function esc($conn, $val) {
    return mysqli_real_escape_string($conn, trim($val));
}

// HANDLE DELETE
if(isset($_GET['delete_id'])){
    $delete_id = intval($_GET['delete_id']);
    $q = mysqli_query($conn, "SELECT user_id FROM students WHERE id=$delete_id");
    $row = mysqli_fetch_assoc($q);
    if($row){
        mysqli_query($conn, "DELETE FROM students WHERE id=$delete_id");
        mysqli_query($conn, "DELETE FROM users WHERE id=".$row['user_id']);
        $_SESSION['message'] = "Student deleted successfully.";
    }
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// HANDLE ADD / EDIT
if(isset($_POST['save_student'])){
    $is_edit = !empty($_POST['student_id']);
    $student_id = intval($_POST['student_id'] ?? 0);
    $student_name = esc($conn, $_POST['student_name']);
    $admission_number = esc($conn, $_POST['admission_number']);
    $parent_name = esc($conn, $_POST['parent_name']);
    $parent_phone = esc($conn, $_POST['parent_phone']);
    $class_id = !empty($_POST['class_id']) ? intval($_POST['class_id']) : NULL;

    if(empty($student_name) || empty($admission_number)){
        $_SESSION['error'] = "Student name and admission number are required.";
    } else {
        $parent_id = NULL;
        if(!empty($parent_phone)){
            $parent_q = mysqli_query($conn, "SELECT id FROM users WHERE phone_number='$parent_phone' AND role='parent'");
            if($parent_q && mysqli_num_rows($parent_q) > 0){
                $parent_row = mysqli_fetch_assoc($parent_q);
                $parent_id = $parent_row['id'];
                mysqli_query($conn, "UPDATE users SET name='$parent_name' WHERE id=$parent_id");
            } else {
                $parent_pass = password_hash($parent_phone, PASSWORD_DEFAULT);
                $parent_email = $parent_phone . '@parent.com';
                mysqli_query($conn, "INSERT INTO users (name,email,password,role,phone_number) VALUES ('$parent_name','$parent_email','$parent_pass','parent','$parent_phone')");
                $parent_id = mysqli_insert_id($conn);
            }
        }

        if($is_edit){
            $user_q = mysqli_query($conn, "SELECT user_id FROM students WHERE id=$student_id");
            if($user_q && $user_q->num_rows){
                $user_row = mysqli_fetch_assoc($user_q);
                $user_id = $user_row['user_id'];
                mysqli_query($conn, "UPDATE users SET name='$student_name' WHERE id=$user_id");
                $class_val = $class_id ? $class_id : "NULL";
                $parent_val = $parent_id ? $parent_id : "NULL";
                mysqli_query($conn, "UPDATE students SET admission_number='$admission_number', class_id=$class_val, parent_id=$parent_val WHERE id=$student_id");
                $_SESSION['message'] = "Student updated successfully.";
            }
        } else {
            $student_pass_plain = $parent_phone ?: $admission_number;
            $student_pass = password_hash($student_pass_plain, PASSWORD_DEFAULT);
            $email = strtolower(str_replace(' ','',$student_name)).$admission_number.'@school.com';
            mysqli_query($conn, "INSERT INTO users (name,email,password,role) VALUES ('$student_name','$email','$student_pass','student')");
            $user_id = mysqli_insert_id($conn);
            $class_val = $class_id ? $class_id : "NULL";
            $parent_val = $parent_id ? $parent_id : "NULL";
            mysqli_query($conn, "INSERT INTO students (user_id,class_id,parent_id,admission_number) VALUES ('$user_id',$class_val,$parent_val,'$admission_number')");
            $_SESSION['message'] = "Student added successfully.";
        }
    }
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

$search = $_GET['search'] ?? '';
$search_sql = "";
if($search){
    $search_esc = esc($conn, $search);
    $search_sql = "WHERE (u.name LIKE '%$search_esc%' OR s.admission_number LIKE '%$search_esc%' OR p.name LIKE '%$search_esc%')";
}

$classes = mysqli_query($conn, "SELECT * FROM classes ORDER BY class_name");
$classes_arr = [];
while($c = mysqli_fetch_assoc($classes)) $classes_arr[] = $c;

// Stats
$total_students = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM students"))[0];
$total_classes  = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(DISTINCT class_id) FROM students WHERE class_id IS NOT NULL"))[0];
$with_parent    = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM students WHERE parent_id IS NOT NULL"))[0];

$sql = "
SELECT s.id, s.admission_number, u.name, u.email, c.class_name, p.name AS parent_name, p.phone_number
FROM students s
JOIN users u ON s.user_id = u.id
LEFT JOIN classes c ON s.class_id = c.id
LEFT JOIN users p ON s.parent_id = p.id
$search_sql
ORDER BY s.id DESC
";
$students = mysqli_query($conn, $sql);

$edit_student = null;
if(isset($_GET['edit_id'])){
    $edit_id = intval($_GET['edit_id']);
    $edit_q = mysqli_query($conn, "
        SELECT s.id, s.admission_number, u.name AS student_name, c.id AS class_id, p.id AS parent_id, p.name AS parent_name, p.phone_number
        FROM students s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN users p ON s.parent_id = p.id
        WHERE s.id = $edit_id LIMIT 1
    ");
    if($edit_q && mysqli_num_rows($edit_q) > 0){
        $edit_student = mysqli_fetch_assoc($edit_q);
    }
}

// TERMS
$all_terms_result = mysqli_query($conn, "SELECT * FROM terms ORDER BY id ASC");
$all_terms = [];
while($t = mysqli_fetch_assoc($all_terms_result)) $all_terms[] = $t;

$current_term = !empty($all_terms) ? end($all_terms) : null;
$current_term_id = $current_term ? (int)$current_term['id'] : 0;

$all_fee_rows_by_term = [];

foreach($all_terms as $term_loop){
    $tid_loop = (int)$term_loop['id'];
    $rows_this_term = [];

    $students_fees_sql = "
        SELECT
            s.user_id,
            s.id            AS student_rec_id,
            s.admission_number,
            s.class_id,
            u.name          AS student_name,
            pu.name         AS parent_name,
            pu.phone_number AS parent_phone,
            c.class_name,
            c.termly_fees
        FROM students s
        JOIN users u        ON s.user_id   = u.id
        LEFT JOIN users pu  ON s.parent_id = pu.id
        LEFT JOIN classes c ON s.class_id  = c.id
        WHERE c.termly_fees IS NOT NULL AND c.termly_fees > 0
        ORDER BY u.name ASC
    ";
    $students_fees_result = mysqli_query($conn, $students_fees_sql);

    while($sf = mysqli_fetch_assoc($students_fees_result)){
        $uid      = (int)$sf['user_id'];
        $cid      = (int)$sf['class_id'];
        $base_fee = (float)$sf['termly_fees'];

        // Only look at THIS specific term — no cross-term arrears
        $paid_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount_paid),0) AS total
             FROM fees_payments
             WHERE student_id = '$uid' AND class_id = '$cid' AND term_id = '$tid_loop'"
        ));
        $amount_paid = (float)($paid_row['total'] ?? 0);
        $balance     = $base_fee - $amount_paid;

        if($balance > 0){
            $rows_this_term[] = [
                'user_id'          => $uid,
                'student_name'     => $sf['student_name'],
                'admission_number' => $sf['admission_number'],
                'class_name'       => $sf['class_name'] ?? '',
                'parent_name'      => $sf['parent_name'] ?? '',
                'parent_phone'     => $sf['parent_phone'] ?? '',
                'term_name'        => $term_loop['term_name'],
                'term_fee'         => $base_fee,
                'amount_paid'      => $amount_paid,
                'balance'          => max(0, $balance),
            ];
        }
    }

    usort($rows_this_term, fn($a, $b) => $b['balance'] <=> $a['balance']);
    $all_fee_rows_by_term[$tid_loop] = $rows_this_term;
}

$fee_rows = $all_fee_rows_by_term[$current_term_id] ?? [];

// ALL STUDENTS WITH PHONES (calls + broadcast)
$call_sql = "
    SELECT s.id, u.name AS student_name, s.admission_number, c.class_name,
           pu.name AS parent_name, pu.phone_number AS parent_phone
    FROM students s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN users pu ON s.parent_id = pu.id
    WHERE pu.phone_number IS NOT NULL AND pu.phone_number != ''
    ORDER BY u.name ASC
";
$call_result = mysqli_query($conn, $call_sql);
$call_rows = [];
if($call_result){
    while($cr = mysqli_fetch_assoc($call_result)) $call_rows[] = $cr;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Students — Little Friends School</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#F5F4F0;
  --surface:#FFFFFF;
  --border:#E5E4DF;
  --border-strong:#CCCBC5;
  --text:#1A1A18;
  --text-muted:#6B6A66;
  --text-hint:#9B9A96;
  --blue-bg:#E6F1FB;--blue-text:#0C447C;--blue-border:#B5D4F4;
  --green-bg:#E1F5EE;--green-text:#085041;--green-border:#9FE1CB;
  --amber-bg:#FAEEDA;--amber-text:#633806;--amber-border:#FAC775;
  --red-bg:#FCEBEB;--red-text:#791F1F;--red-border:#F7C1C1;
  --purple-bg:#EEEDFE;--purple-text:#3C3489;
  --coral-bg:#FAECE7;--coral-text:#712B13;
  --teal-bg:#E1F5EE;--teal-text:#085041;
  --brand:#185FA5;--brand-dark:#0C447C;
  --call:#0B8A4E;--call-dark:#076038;
  --msg:#7B3FA0;--msg-dark:#5C2E7A;
  --radius:10px;--radius-lg:14px;
  font-family:'DM Sans',system-ui,sans-serif;
}
body{background:var(--bg);color:var(--text);min-height:100vh}

.topbar{
  background:var(--surface);border-bottom:1px solid var(--border);
  padding:14px 20px;display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:50;gap:10px;
}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0}
.back-btn{
  width:34px;height:34px;border-radius:var(--radius);border:1px solid var(--border);
  background:transparent;cursor:pointer;font-size:16px;color:var(--text-muted);
  display:flex;align-items:center;justify-content:center;text-decoration:none;flex-shrink:0;
}
.back-btn:hover{background:var(--bg);border-color:var(--border-strong)}
.topbar-title{font-size:16px;font-weight:600}
.topbar-sub{font-size:12px;color:var(--text-hint);margin-top:1px}
.topbar-actions{display:flex;gap:7px;align-items:center;flex-shrink:0}
.btn-topbar{
  display:flex;align-items:center;gap:6px;
  border:none;border-radius:var(--radius);
  padding:9px 14px;font-size:13px;font-weight:500;
  cursor:pointer;white-space:nowrap;text-decoration:none;font-family:inherit;
}
.btn-add{background:var(--brand);color:#fff}
.btn-add:hover{background:var(--brand-dark)}
.btn-calls{background:var(--call);color:#fff}
.btn-calls:hover{background:var(--call-dark)}
.btn-msgs{background:var(--msg);color:#fff}
.btn-msgs:hover{background:var(--msg-dark)}
.btn-print{background:#374151;color:#fff}
.btn-print:hover{background:#1f2937}

.alert{margin:16px 16px 0;padding:12px 16px;border-radius:var(--radius);font-size:13px;font-weight:500;}
.alert-success{background:var(--green-bg);color:var(--green-text);border:1px solid var(--green-border)}
.alert-danger{background:var(--red-bg);color:var(--red-text);border:1px solid var(--red-border)}

.page{padding:16px;max-width:820px;margin:0 auto}

.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px 14px 12px;}
.stat-label{font-size:11px;color:var(--text-hint);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
.stat-value{font-size:24px;font-weight:600;line-height:1;font-family:'DM Mono',monospace}
.stat-sub{font-size:11px;color:var(--text-hint);margin-top:4px}
.stat-blue .stat-value{color:var(--brand)}
.stat-green .stat-value{color:#0F6E56}
.stat-amber .stat-value{color:#854F0B}

.toolbar{
  display:flex;align-items:center;gap:10px;
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius-lg);padding:10px 14px;margin-bottom:16px;
}
.toolbar form{display:flex;align-items:center;gap:8px;flex:1}
.search-wrap{
  display:flex;align-items:center;gap:8px;flex:1;
  background:var(--bg);border:1px solid var(--border);
  border-radius:var(--radius);padding:8px 12px;
}
.search-wrap svg{flex-shrink:0;color:var(--text-hint)}
.search-wrap input{border:none;background:transparent;font-size:13px;color:var(--text);outline:none;flex:1;font-family:inherit;}
.search-wrap input::placeholder{color:var(--text-hint)}
.btn-search{padding:8px 13px;border-radius:var(--radius);border:none;background:var(--brand);color:#fff;font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;}
.btn-reset{padding:8px 12px;border-radius:var(--radius);border:1px solid var(--border);background:transparent;font-size:13px;color:var(--text-muted);cursor:pointer;text-decoration:none;}
.btn-reset:hover{background:var(--bg)}
.result-count{font-size:12px;color:var(--text-hint);white-space:nowrap}

.student-list{display:flex;flex-direction:column;gap:8px}
.student-card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius-lg);padding:14px 16px;
  display:flex;align-items:center;gap:12px;
  transition:border-color .15s,box-shadow .15s;
}
.student-card:hover{border-color:var(--border-strong);box-shadow:0 1px 4px rgba(0,0,0,.06)}
.avatar{width:42px;height:42px;min-width:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;}
.av-0{background:var(--blue-bg);color:var(--blue-text)}
.av-1{background:var(--teal-bg);color:var(--teal-text)}
.av-2{background:var(--purple-bg);color:var(--purple-text)}
.av-3{background:var(--coral-bg);color:var(--coral-text)}
.av-4{background:var(--amber-bg);color:var(--amber-text)}
.student-info{flex:1;min-width:0}
.student-name{font-size:14px;font-weight:500;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.student-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.meta-adm{font-size:12px;color:var(--text-hint);font-family:'DM Mono',monospace}
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500}
.badge-class{background:var(--blue-bg);color:var(--blue-text)}
.badge-parent{background:var(--green-bg);color:var(--green-text)}
.card-right{display:flex;align-items:center;gap:12px}
.phone-text{font-size:12px;color:var(--text-hint);display:none;font-family:'DM Mono',monospace}
@media(min-width:560px){.phone-text{display:block}}
.card-actions{display:flex;gap:6px}
.action-btn{
  width:32px;height:32px;border-radius:var(--radius);
  border:1px solid var(--border);background:transparent;
  cursor:pointer;font-size:13px;color:var(--text-muted);
  display:flex;align-items:center;justify-content:center;text-decoration:none;
}
.action-btn:hover{background:var(--bg);border-color:var(--border-strong);color:var(--text)}
.action-btn.edit:hover{background:var(--blue-bg);border-color:var(--blue-border);color:var(--blue-text)}
.action-btn.del:hover{background:var(--red-bg);border-color:var(--red-border);color:var(--red-text)}
.action-btn.call-btn:hover{background:var(--green-bg);border-color:var(--green-border);color:var(--green-text)}
.action-btn.sms-btn:hover{background:var(--purple-bg);border-color:#C4B5EF;color:var(--purple-text)}
.empty-state{text-align:center;padding:48px 20px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);}
.empty-icon{font-size:32px;margin-bottom:12px}
.empty-title{font-size:15px;font-weight:500;margin-bottom:6px}
.empty-desc{font-size:13px;color:var(--text-hint)}

/* SIDE PANELS */
.panel-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:none;}
.panel-overlay.open{display:flex;align-items:stretch;justify-content:flex-end}
.side-panel{
  background:var(--surface);width:100%;max-width:440px;
  height:100%;display:flex;flex-direction:column;
  box-shadow:-4px 0 24px rgba(0,0,0,.12);
  animation:slideIn .22s ease;
}
@keyframes slideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
.panel-header{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0;}
.panel-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;}
.panel-icon-call{background:var(--green-bg)}
.panel-icon-msg{background:var(--purple-bg)}
.panel-head-text{flex:1}
.panel-title{font-size:15px;font-weight:600}
.panel-desc{font-size:12px;color:var(--text-hint);margin-top:2px}
.panel-close{width:30px;height:30px;border-radius:50%;border:1px solid var(--border);background:transparent;cursor:pointer;font-size:16px;color:var(--text-muted);display:flex;align-items:center;justify-content:center;}
.panel-close:hover{background:var(--bg)}
.panel-body{flex:1;overflow-y:auto;padding:14px}
.panel-search{display:flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:9px 12px;margin-bottom:12px;}
.panel-search svg{flex-shrink:0;color:var(--text-hint)}
.panel-search input{border:none;background:transparent;font-size:13px;color:var(--text);outline:none;flex:1;font-family:inherit;}
.panel-search input::placeholder{color:var(--text-hint)}

/* CALL CARDS */
.call-card{display:flex;align-items:center;gap:12px;padding:12px;border-radius:var(--radius);border:1px solid var(--border);margin-bottom:8px;background:var(--surface);transition:border-color .12s;}
.call-card:hover{border-color:var(--green-border)}
.call-avatar{width:38px;height:38px;min-width:38px;border-radius:50%;background:var(--green-bg);color:var(--green-text);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;}
.call-info{flex:1;min-width:0}
.call-name{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.call-sub{font-size:11px;color:var(--text-hint);margin-top:2px}
.call-phone{font-size:11px;color:var(--text-muted);font-family:'DM Mono',monospace;margin-top:1px}
.btn-call{display:flex;align-items:center;gap:5px;padding:7px 13px;border-radius:var(--radius);border:none;background:var(--call);color:#fff;font-size:12px;font-weight:500;cursor:pointer;text-decoration:none;font-family:inherit;white-space:nowrap;}
.btn-call:hover{background:var(--call-dark)}

/* MSG TABS */
.msg-tabs{display:flex;gap:0;margin-bottom:14px;background:var(--bg);border-radius:var(--radius);padding:3px}
.msg-tab{flex:1;padding:8px 6px;border-radius:8px;border:none;background:transparent;font-size:11px;font-weight:500;color:var(--text-muted);cursor:pointer;font-family:inherit;transition:background .15s,color .15s;white-space:nowrap;}
.msg-tab.active{background:var(--surface);color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.08)}

/* COMPOSE */
.compose-box{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px;margin-bottom:12px;}
.compose-label{font-size:11px;font-weight:600;color:var(--text-hint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.compose-input{width:100%;border:1px solid var(--border);border-radius:var(--radius);padding:9px 12px;font-size:13px;color:var(--text);background:var(--surface);outline:none;font-family:inherit;transition:border-color .15s;}
.compose-input:focus{border-color:var(--msg)}
.compose-textarea{width:100%;border:1px solid var(--border);border-radius:var(--radius);padding:9px 12px;font-size:13px;color:var(--text);background:var(--surface);outline:none;font-family:inherit;resize:vertical;min-height:100px;transition:border-color .15s;margin-top:8px;line-height:1.55;}
.compose-textarea:focus{border-color:var(--msg)}
.compose-footer{display:flex;align-items:center;justify-content:space-between;margin-top:10px;gap:8px;flex-wrap:wrap}
.char-count{font-size:11px;color:var(--text-hint)}
.btn-send{padding:9px 18px;border-radius:var(--radius);border:none;background:var(--msg);color:#fff;font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;}
.btn-send:hover{background:var(--msg-dark)}

/* TEMPLATE CHIPS */
.template-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.chip{padding:5px 11px;border-radius:20px;border:1px solid var(--border);background:var(--surface);font-size:11px;font-weight:500;color:var(--text-muted);cursor:pointer;transition:all .15s;}
.chip:hover{background:var(--purple-bg);border-color:#C4B5EF;color:var(--purple-text)}
.chip.active{background:var(--purple-bg);border-color:#C4B5EF;color:var(--purple-text)}

/* BROADCAST */
.broadcast-banner{background:linear-gradient(135deg,#2d1b69 0%,#5C2E7A 100%);border-radius:var(--radius-lg);padding:14px 16px;margin-bottom:14px;color:#fff;}
.broadcast-title{font-size:14px;font-weight:600;margin-bottom:3px}
.broadcast-sub{font-size:11px;opacity:.75;line-height:1.5}
.broadcast-stats{display:flex;gap:8px;margin-top:10px;}
.bcast-stat{flex:1;background:rgba(255,255,255,.12);border-radius:8px;padding:8px 10px;text-align:center;}
.bcast-stat-val{font-size:18px;font-weight:600;font-family:'DM Mono',monospace}
.bcast-stat-lbl{font-size:10px;opacity:.7;margin-top:1px}

.recipient-toggle{display:flex;gap:6px;margin-bottom:12px;}
.rtog-btn{flex:1;padding:8px 6px;border-radius:var(--radius);border:1px solid var(--border);background:transparent;font-size:11px;font-weight:500;color:var(--text-muted);cursor:pointer;font-family:inherit;transition:all .15s;text-align:center;}
.rtog-btn.active{background:var(--purple-bg);border-color:#C4B5EF;color:var(--purple-text)}

.class-filter-wrap{margin-bottom:10px;display:none}
.class-filter-wrap.visible{display:block}
.class-filter-select{width:100%;border:1px solid var(--border);border-radius:var(--radius);padding:9px 12px;font-size:13px;color:var(--text);background:var(--surface);outline:none;font-family:inherit;transition:border-color .15s;}
.class-filter-select:focus{border-color:var(--msg)}

.recipient-count-badge{display:inline-flex;align-items:center;gap:5px;background:var(--purple-bg);color:var(--purple-text);border:1px solid #C4B5EF;border-radius:20px;font-size:11px;font-weight:600;padding:3px 10px;margin-bottom:10px;}

/* FEE CARDS */
.fee-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:8px;}
.fee-header-left{font-size:13px;font-weight:500}
.fee-header-sub{font-size:11px;color:var(--text-hint);margin-top:2px}
.btn-bulk{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--radius);border:none;background:var(--msg);color:#fff;font-size:12px;font-weight:500;cursor:pointer;font-family:inherit;white-space:nowrap;}
.btn-bulk:hover{background:var(--msg-dark)}

.term-selector-wrap{display:flex;align-items:center;gap:8px;background:var(--blue-bg);border:1px solid var(--blue-border);border-radius:var(--radius);padding:10px 14px;margin-bottom:12px;}
.term-selector-label{font-size:12px;color:var(--blue-text);font-weight:500;white-space:nowrap;flex-shrink:0}
.term-selector-select{flex:1;border:1px solid var(--blue-border);border-radius:8px;padding:6px 10px;font-size:12px;color:var(--blue-text);background:var(--surface);outline:none;font-family:inherit;cursor:pointer;}
.term-selector-select:focus{border-color:var(--brand)}

.fee-card{border:1px solid var(--border);border-radius:var(--radius);padding:12px 13px;margin-bottom:8px;background:var(--surface);transition:border-color .12s;}
.fee-card:hover{border-color:var(--amber-border)}
.fee-top{display:flex;align-items:center;gap:10px;margin-bottom:7px}
.fee-avatar{width:34px;height:34px;min-width:34px;border-radius:50%;background:var(--amber-bg);color:var(--amber-text);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;}
.fee-name{font-size:13px;font-weight:500;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fee-badge{padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;background:var(--red-bg);color:var(--red-text);font-family:'DM Mono',monospace;white-space:nowrap;}
.fee-meta{display:flex;gap:10px;font-size:11px;color:var(--text-hint);flex-wrap:wrap}
.fee-meta span{display:flex;align-items:center;gap:3px}

/* Simplified fee summary row */
.fee-summary{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;}
.fee-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:500;}
.fee-pill-paid{background:var(--green-bg);color:var(--green-text)}
.fee-pill-arrears{background:var(--amber-bg);color:var(--amber-text)}
.fee-pill-balance{background:var(--red-bg);color:var(--red-text);font-weight:700;}

.fee-actions{display:flex;gap:6px;margin-top:9px}
.btn-sms-one{flex:1;padding:7px;border-radius:var(--radius);border:1px solid var(--border);background:transparent;color:var(--msg);font-size:12px;font-weight:500;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:5px;text-decoration:none;transition:all .15s;}
.btn-sms-one:hover{background:var(--purple-bg);border-color:#C4B5EF}
.btn-call-one{padding:7px 12px;border-radius:var(--radius);border:1px solid var(--border);background:transparent;color:var(--call);font-size:12px;display:flex;align-items:center;justify-content:center;gap:5px;text-decoration:none;transition:all .15s;}
.btn-call-one:hover{background:var(--green-bg);border-color:var(--green-border)}
.no-fees{text-align:center;padding:36px 16px;font-size:13px;color:var(--text-hint);}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:flex-end;justify-content:center;z-index:100;}
@media(min-width:600px){.modal-overlay{align-items:center}.modal-sheet{border-radius:var(--radius-lg) !important;max-height:92vh}}
.modal-sheet{background:var(--surface);border-radius:20px 20px 0 0;width:100%;max-width:580px;padding:20px 20px 40px;max-height:92vh;overflow-y:auto;}
.sheet-handle{width:36px;height:4px;border-radius:2px;background:var(--border-strong);margin:0 auto 20px}
.sheet-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.sheet-title{font-size:17px;font-weight:600}
.sheet-close{width:30px;height:30px;border-radius:50%;border:1px solid var(--border);background:transparent;cursor:pointer;font-size:16px;color:var(--text-muted);display:flex;align-items:center;justify-content:center;text-decoration:none;}
.sheet-close:hover{background:var(--bg)}
.form-section-label{font-size:11px;color:var(--text-hint);text-transform:uppercase;letter-spacing:.6px;margin:16px 0 10px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:480px){.form-row{grid-template-columns:1fr}}
.form-group{margin-bottom:12px}
.form-label{font-size:12px;font-weight:500;color:var(--text-muted);margin-bottom:5px;display:block}
.form-label .req{color:#E24B4A}
.form-input{width:100%;border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px;font-size:14px;background:var(--surface);color:var(--text);outline:none;transition:border-color .15s;font-family:inherit;}
.form-input:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(24,95,165,.12)}
.form-input::placeholder{color:var(--text-hint)}
.form-footer{display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border);}
.btn-primary{flex:1;padding:12px;border-radius:var(--radius);border:none;background:var(--brand);color:#fff;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;}
.btn-primary:hover{background:var(--brand-dark)}
.btn-secondary{padding:12px 18px;border-radius:var(--radius);border:1px solid var(--border);background:transparent;color:var(--text-muted);font-size:14px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;font-family:inherit;}
.btn-secondary:hover{background:var(--bg)}

/* ══════════════════════════════════════════════
   BULK SEND MODAL — manual step-by-step
   ══════════════════════════════════════════════ */
.bulk-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:600;display:none;align-items:center;justify-content:center;padding:16px;}
.bulk-modal.open{display:flex}
.bulk-box{background:var(--surface);border-radius:var(--radius-lg);width:100%;max-width:400px;overflow:hidden;}

.bulk-top{padding:20px 20px 16px;border-bottom:1px solid var(--border);}
.bulk-title{font-size:16px;font-weight:600;margin-bottom:4px}
.bulk-desc{font-size:12px;color:var(--text-hint);line-height:1.5}

.bulk-progress-wrap{padding:16px 20px;border-bottom:1px solid var(--border);}
.bulk-step-label{font-size:11px;color:var(--text-hint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.bulk-progress-outer{height:6px;background:var(--bg);border-radius:3px;margin-bottom:8px;overflow:hidden}
.bulk-progress-inner{height:100%;background:var(--msg);border-radius:3px;transition:width .3s}
.bulk-counter-row{display:flex;align-items:center;justify-content:space-between;}
.bulk-counter{font-size:12px;font-family:'DM Mono',monospace;color:var(--text-muted)}
.bulk-pct{font-size:12px;font-family:'DM Mono',monospace;color:var(--text-hint)}

.bulk-recipient-card{margin:14px 20px;padding:12px 14px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);}
.bulk-rec-name{font-size:14px;font-weight:500;margin-bottom:3px}
.bulk-rec-sub{font-size:12px;color:var(--text-hint)}
.bulk-rec-phone{font-size:12px;color:var(--text-muted);font-family:'DM Mono',monospace;margin-top:3px}

.bulk-instruction{margin:0 20px 16px;padding:10px 12px;background:var(--amber-bg);border:1px solid var(--amber-border);border-radius:var(--radius);font-size:12px;color:var(--amber-text);line-height:1.5;}

.bulk-actions{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;}
.btn-bulk-open{
  flex:1;padding:11px;border-radius:var(--radius);border:none;
  background:var(--msg);color:#fff;font-size:13px;font-weight:500;
  cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:7px;
}
.btn-bulk-open:hover{background:var(--msg-dark)}
.btn-bulk-open.call-style{background:var(--call)}
.btn-bulk-open.call-style:hover{background:var(--call-dark)}
.btn-bulk-next{
  flex:1;padding:11px;border-radius:var(--radius);border:none;
  background:var(--brand);color:#fff;font-size:13px;font-weight:500;
  cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:7px;
}
.btn-bulk-next:hover{background:var(--brand-dark)}
.btn-bulk-cancel{padding:11px 14px;border-radius:var(--radius);border:1px solid var(--border);background:transparent;font-size:13px;color:var(--text-muted);cursor:pointer;font-family:inherit;}
.btn-bulk-cancel:hover{background:var(--bg)}

/* Done state */
.bulk-done{padding:28px 20px;text-align:center;}
.bulk-done-icon{font-size:36px;margin-bottom:10px}
.bulk-done-title{font-size:16px;font-weight:600;margin-bottom:6px}
.bulk-done-sub{font-size:13px;color:var(--text-hint);line-height:1.5;margin-bottom:18px}
.btn-bulk-close{padding:11px 28px;border-radius:var(--radius);border:none;background:var(--brand);color:#fff;font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;}
.btn-bulk-close:hover{background:var(--brand-dark)}

/* Toast */
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1A1A18;color:#fff;padding:12px 20px;border-radius:var(--radius);font-size:13px;display:none;z-index:700;white-space:nowrap;box-shadow:0 4px 16px rgba(0,0,0,.3);}
.toast.show{display:flex;align-items:center;gap:10px}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-left">
    <a href="../admin/dashboard.php" class="back-btn">&#8592;</a>
    <div>
      <div class="topbar-title">Students</div>
      <div class="topbar-sub">Manage all student records</div>
    </div>
  </div>
  <div class="topbar-actions">
    <button class="btn-topbar btn-calls" onclick="openPanel('callsPanel')">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
        <path d="M2 3.5C2 2.67 2.67 2 3.5 2h1.618a1 1 0 0 1 .95.684l.96 2.88a1 1 0 0 1-.29 1.06l-.9.8a8.02 8.02 0 0 0 3.738 3.738l.8-.9a1 1 0 0 1 1.06-.29l2.88.96A1 1 0 0 1 14 12h0v1.5A1.5 1.5 0 0 1 12.5 15C6.701 15 2 10.299 2 4.5v-1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
      </svg>
      Calls
    </button>
    <button class="btn-topbar btn-msgs" onclick="openPanel('msgsPanel')">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
        <path d="M14 2H2a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h3l2 2 2-2h5a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
      </svg>
      Messages
    </button>
    <button class="btn-topbar btn-print" onclick="openPrintModal()">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
        <rect x="3" y="1" width="10" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/>
        <path d="M3 10H1.5A.5.5 0 0 1 1 9.5v-4A.5.5 0 0 1 1.5 5h13a.5.5 0 0 1 .5.5v4a.5.5 0 0 1-.5.5H13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        <rect x="3" y="9" width="10" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/>
        <path d="M5 12h6M5 14h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
      </svg>
      Print
    </button>
    <a href="?add=1" class="btn-topbar btn-add">
      <svg width="13" height="13" viewBox="0 0 14 14" fill="none"><path d="M7 1v12M1 7h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      Add student
    </a>
  </div>
</div>

<?php if(!empty($_SESSION['message'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?></div>
<?php endif; ?>
<?php if(!empty($_SESSION['error'])): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="page">
  <!-- STATS -->
  <div class="stats-row">
    <div class="stat-card stat-blue">
      <div class="stat-label">Total students</div>
      <div class="stat-value"><?= $total_students ?></div>
      <div class="stat-sub">Enrolled</div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Classes</div>
      <div class="stat-value"><?= $total_classes ?></div>
      <div class="stat-sub">Active groups</div>
    </div>
    <div class="stat-card stat-amber">
      <div class="stat-label">With parent</div>
      <div class="stat-value"><?= $with_parent ?></div>
      <div class="stat-sub">Linked contacts</div>
    </div>
  </div>

  <!-- SEARCH -->
  <div class="toolbar">
    <form method="GET" style="display:flex;align-items:center;gap:8px;flex:1">
      <div class="search-wrap">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
          <circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.5"/>
          <path d="M11 11l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <input type="text" name="search" placeholder="Search name, admission, parent…" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
      </div>
      <button type="submit" class="btn-search">Search</button>
      <?php if($search): ?>
        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn-reset">Clear</a>
      <?php endif; ?>
    </form>
    <?php
    $count_q = mysqli_query($conn, "SELECT COUNT(*) FROM students s JOIN users u ON s.user_id=u.id LEFT JOIN users p ON s.parent_id=p.id $search_sql");
    $result_count = mysqli_fetch_row($count_q)[0];
    ?>
    <div class="result-count"><?= $result_count ?> result<?= $result_count != 1 ? 's' : '' ?></div>
  </div>

  <!-- STUDENT LIST -->
  <div class="student-list">
    <?php
    $colors = ['av-0','av-1','av-2','av-3','av-4'];
    $idx = 0;
    $students = mysqli_query($conn, $sql);
    if($students && mysqli_num_rows($students) > 0):
      while($row = mysqli_fetch_assoc($students)):
        $initials = '';
        $words = explode(' ', $row['name']);
        foreach($words as $w) $initials .= strtoupper(substr($w,0,1));
        $initials = substr($initials,0,2);
        $av_class = $colors[$idx % 5];
        $idx++;
    ?>
    <div class="student-card">
      <div class="avatar <?= $av_class ?>"><?= htmlspecialchars($initials) ?></div>
      <div class="student-info">
        <div class="student-name"><?= htmlspecialchars($row['name']) ?></div>
        <div class="student-meta">
          <span class="meta-adm"><?= htmlspecialchars($row['admission_number']) ?></span>
          <?php if($row['class_name']): ?>
            <span class="badge badge-class"><?= htmlspecialchars($row['class_name']) ?></span>
          <?php endif; ?>
          <?php if($row['parent_name']): ?>
            <span class="badge badge-parent"><?= htmlspecialchars($row['parent_name']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-right">
        <?php if($row['phone_number']): ?>
          <span class="phone-text"><?= maskPhone($row['phone_number']) ?></span>
        <?php endif; ?>
        <div class="card-actions">
          <?php if($row['phone_number']): ?>
            <a href="tel:<?= htmlspecialchars($row['phone_number']) ?>" class="action-btn call-btn" title="Call parent">
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                <path d="M2 3.5C2 2.67 2.67 2 3.5 2h1.618a1 1 0 0 1 .95.684l.96 2.88a1 1 0 0 1-.29 1.06l-.9.8a8.02 8.02 0 0 0 3.738 3.738l.8-.9a1 1 0 0 1 1.06-.29l2.88.96A1 1 0 0 1 14 12v1.5A1.5 1.5 0 0 1 12.5 15C6.701 15 2 10.299 2 4.5V3.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
              </svg>
            </a>
            <a href="sms:<?= htmlspecialchars($row['phone_number']) ?>" class="action-btn sms-btn" title="Send SMS">
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                <path d="M14 2H2a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h3l2 2 2-2h5a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
              </svg>
            </a>
          <?php endif; ?>
          <a href="?edit_id=<?= $row['id'] ?>" class="action-btn edit" title="Edit student">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
              <path d="M11.5 2.5l2 2L5 13H3v-2L11.5 2.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            </svg>
          </a>
          <a href="?delete_id=<?= $row['id'] ?>" class="action-btn del" title="Delete student" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($row['name'])) ?>? This cannot be undone.')">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
              <path d="M3 5h10M6 5V3h4v2M7 8v4M9 8v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
              <rect x="2" y="5" width="12" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
            </svg>
          </a>
        </div>
      </div>
    </div>
    <?php endwhile; else: ?>
    <div class="empty-state">
      <div class="empty-icon">&#128100;</div>
      <div class="empty-title">No students found</div>
      <div class="empty-desc"><?= $search ? 'Try a different search term.' : 'Add your first student to get started.' ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>


<!-- ═══ CALLS PANEL ═══ -->
<div class="panel-overlay" id="callsPanel" onclick="closePanelOutside(event,'callsPanel')">
  <div class="side-panel">
    <div class="panel-header">
      <div class="panel-icon panel-icon-call">📞</div>
      <div class="panel-head-text">
        <div class="panel-title">Calls</div>
        <div class="panel-desc">Tap a number to call parent / guardian</div>
      </div>
      <button class="panel-close" onclick="closePanel('callsPanel')">✕</button>
    </div>
    <div class="panel-body">
      <div class="panel-search">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.5"/><path d="M11 11l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        <input type="text" placeholder="Search parent or student…" oninput="filterCalls(this.value)">
      </div>
      <div id="callList">
        <?php if(empty($call_rows)): ?>
          <div class="no-fees">No parents with phone numbers found.</div>
        <?php else: foreach($call_rows as $cr):
          $words = explode(' ', $cr['parent_name'] ?? $cr['student_name']);
          $ini = '';
          foreach($words as $w) $ini .= strtoupper(substr($w,0,1));
          $ini = substr($ini,0,2);
        ?>
        <div class="call-card" data-name="<?= strtolower(htmlspecialchars($cr['student_name'].' '.($cr['parent_name']??''))) ?>">
          <div class="call-avatar"><?= htmlspecialchars($ini) ?></div>
          <div class="call-info">
            <div class="call-name"><?= htmlspecialchars($cr['student_name']) ?></div>
            <div class="call-sub">
              <?= htmlspecialchars($cr['parent_name'] ?? 'No parent name') ?>
              <?php if($cr['class_name']): ?> · <?= htmlspecialchars($cr['class_name']) ?><?php endif; ?>
            </div>
            <div class="call-phone"><?= htmlspecialchars($cr['parent_phone']) ?></div>
          </div>
          <a href="tel:<?= htmlspecialchars($cr['parent_phone']) ?>" class="btn-call">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
              <path d="M2 3.5C2 2.67 2.67 2 3.5 2h1.618a1 1 0 0 1 .95.684l.96 2.88a1 1 0 0 1-.29 1.06l-.9.8a8.02 8.02 0 0 0 3.738 3.738l.8-.9a1 1 0 0 1 1.06-.29l2.88.96A1 1 0 0 1 14 12v1.5A1.5 1.5 0 0 1 12.5 15C6.701 15 2 10.299 2 4.5V3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            </svg>
            Call
          </a>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>


<!-- ═══ MESSAGES PANEL ═══ -->
<div class="panel-overlay" id="msgsPanel" onclick="closePanelOutside(event,'msgsPanel')">
  <div class="side-panel">
    <div class="panel-header">
      <div class="panel-icon panel-icon-msg">💬</div>
      <div class="panel-head-text">
        <div class="panel-title">Messages</div>
        <div class="panel-desc">Compose, broadcast, or send fee reminders</div>
      </div>
      <button class="panel-close" onclick="closePanel('msgsPanel')">✕</button>
    </div>
    <div class="panel-body">
      <div class="msg-tabs">
        <button class="msg-tab active"  onclick="switchTab('new',this)">✏️ New</button>
        <button class="msg-tab"         onclick="switchTab('broadcast',this)">📢 Broadcast</button>
        <button class="msg-tab"         onclick="switchTab('fees',this)">💰 Fee reminders</button>
      </div>

      <!-- TAB 1 — NEW SINGLE MESSAGE -->
      <div id="tab-new">
        <p style="font-size:11px;color:var(--text-hint);margin-bottom:7px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Quick templates</p>
        <div class="template-chips">
          <span class="chip" onclick="applyTemplate('general',event)">📢 General</span>
          <span class="chip" onclick="applyTemplate('reminder',event)">💰 Fee reminder</span>
          <span class="chip" onclick="applyTemplate('meeting',event)">📅 Meeting</span>
          <span class="chip" onclick="applyTemplate('results',event)">📝 Results</span>
          <span class="chip" onclick="applyTemplate('holiday',event)">🏖️ Holiday</span>
        </div>
        <div class="compose-box">
          <div class="compose-label">Recipient phone number</div>
          <input type="tel" class="compose-input" id="composePhone" placeholder="e.g. 0712 345 678">
          <div class="compose-label" style="margin-top:12px">Student name (optional)</div>
          <input type="text" class="compose-input" id="composeName" placeholder="e.g. John Kamau" style="margin-top:0">
          <div class="compose-label" style="margin-top:10px">Message</div>
          <textarea class="compose-textarea" id="composeMsg" placeholder="Type your message here…" oninput="updateChar()"></textarea>
          <div class="compose-footer">
            <span class="char-count" id="charCount">0 / 160</span>
            <button class="btn-send" onclick="sendNewMsg()">
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2.5 6L2 14l12-6Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
              Send SMS
            </button>
          </div>
        </div>
        <p style="font-size:11px;color:var(--text-hint);line-height:1.5">Opens your device's native messaging app with the number and message pre-filled.</p>
      </div>

      <!-- TAB 2 — BROADCAST -->
      <div id="tab-broadcast" style="display:none">
        <div class="broadcast-banner">
          <div class="broadcast-title">📢 Broadcast Message</div>
          <div class="broadcast-sub">Send the same message to all parents or filter by class. You'll send each one individually, one at a time.</div>
          <div class="broadcast-stats">
            <div class="bcast-stat">
              <div class="bcast-stat-val" id="bcastTotalCount"><?= count($call_rows) ?></div>
              <div class="bcast-stat-lbl">Total parents</div>
            </div>
            <div class="bcast-stat">
              <div class="bcast-stat-val" id="bcastSelectedCount"><?= count($call_rows) ?></div>
              <div class="bcast-stat-lbl">Will receive</div>
            </div>
          </div>
        </div>
        <div class="recipient-toggle">
          <button class="rtog-btn active" onclick="setBcastGroup('all',this)">👥 All parents</button>
          <button class="rtog-btn" onclick="setBcastGroup('class',this)">🏫 By class</button>
        </div>
        <div class="class-filter-wrap" id="classFilterWrap">
          <label style="font-size:11px;color:var(--text-hint);font-weight:600;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Select class</label>
          <select class="class-filter-select" id="bcastClassSelect" onchange="updateBcastCount()">
            <option value="">-- Select a class --</option>
            <?php foreach($classes_arr as $c): ?>
              <option value="<?= htmlspecialchars($c['class_name']) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="recipient-count-badge">
          <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1H2Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
          <span id="recipientBadge"><?= count($call_rows) ?> parents selected</span>
        </div>
        <p style="font-size:11px;color:var(--text-hint);margin-bottom:7px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Quick templates</p>
        <div class="template-chips" style="margin-bottom:10px">
          <span class="chip" onclick="applyBcastTemplate('general',event)">📢 General</span>
          <span class="chip" onclick="applyBcastTemplate('meeting',event)">📅 Meeting</span>
          <span class="chip" onclick="applyBcastTemplate('results',event)">📝 Results</span>
          <span class="chip" onclick="applyBcastTemplate('holiday',event)">🏖️ Holiday</span>
          <span class="chip" onclick="applyBcastTemplate('fees',event)">💰 Fee reminder</span>
        </div>
        <div class="compose-box">
          <div class="compose-label">Message to broadcast</div>
          <textarea class="compose-textarea" id="bcastMsg" placeholder="Type your broadcast message here…" oninput="updateBcastChar()" style="min-height:120px"></textarea>
          <div class="compose-footer">
            <span class="char-count" id="bcastCharCount">0 / 160</span>
            <button class="btn-send" onclick="startBroadcast()">
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2.5 6L2 14l12-6Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
              Start sending
            </button>
          </div>
        </div>
      </div>

      <!-- TAB 3 — FEE REMINDERS -->
      <div id="tab-fees" style="display:none">
        <?php if(count($all_terms) > 0): ?>
        <div class="term-selector-wrap">
          <span class="term-selector-label">📅 Term:</span>
          <select class="term-selector-select" id="termSelector" onchange="renderFeeList(this.value)">
            <?php foreach(array_reverse($all_terms) as $t): ?>
              <option value="<?= $t['id'] ?>" <?= $t['id']==$current_term_id ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['term_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div id="feeList"></div>
      </div>

    </div>
  </div>
</div>


<!-- ═══ ADD / EDIT MODAL ═══ -->
<?php if($edit_student || isset($_GET['add'])): ?>
<div class="modal-overlay" id="modalOverlay">
<?php else: ?>
<div class="modal-overlay" id="modalOverlay" style="display:none">
<?php endif; ?>
  <div class="modal-sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title"><?= $edit_student ? 'Edit student' : 'Add new student' ?></div>
      <a href="<?= $_SERVER['PHP_SELF'] ?><?= $search ? '?search='.urlencode($search) : '' ?>" class="sheet-close">&#10005;</a>
    </div>
    <form method="POST" novalidate>
      <input type="hidden" name="student_id" value="<?= $edit_student['id'] ?? '' ?>">
      <div class="form-section-label">Student info</div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Full name <span class="req">*</span></label>
          <input type="text" name="student_name" class="form-input" placeholder="e.g. Amara Osei" required
            value="<?= htmlspecialchars($edit_student['student_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Admission no. <span class="req">*</span></label>
          <input type="text" name="admission_number" class="form-input" placeholder="e.g. ADM-001" required
            value="<?= htmlspecialchars($edit_student['admission_number'] ?? '') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Class</label>
        <select name="class_id" class="form-input">
          <option value="">Select class (optional)</option>
          <?php foreach($classes_arr as $c): ?>
            <option value="<?= $c['id'] ?>" <?= (isset($edit_student['class_id']) && $edit_student['class_id'] == $c['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['class_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-section-label">Parent / guardian</div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Parent name</label>
          <input type="text" name="parent_name" class="form-input" placeholder="Guardian full name"
            value="<?= htmlspecialchars($edit_student['parent_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Parent phone</label>
          <input type="tel" name="parent_phone" class="form-input" placeholder="07XX XXX XXX"
            value="<?= htmlspecialchars($edit_student['phone_number'] ?? '') ?>">
        </div>
      </div>
      <div class="form-footer">
        <a href="<?= $_SERVER['PHP_SELF'] ?><?= $search ? '?search='.urlencode($search) : '' ?>" class="btn-secondary">Cancel</a>
        <button type="submit" name="save_student" class="btn-primary">
          <?= $edit_student ? 'Update student' : 'Add student' ?>
        </button>
      </div>
    </form>
  </div>
</div>


<!-- ═══ PRINT MODAL ═══ -->
<style>
.print-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;display:none;align-items:center;justify-content:center;padding:16px;}
.print-modal-overlay.open{display:flex}
.print-modal-box{background:var(--surface);border-radius:var(--radius-lg);width:100%;max-width:460px;padding:24px;box-shadow:0 8px 32px rgba(0,0,0,.18);}
.print-modal-title{font-size:17px;font-weight:600;margin-bottom:4px}
.print-modal-sub{font-size:12px;color:var(--text-hint);margin-bottom:18px}
.print-modal-row{margin-bottom:14px}
.print-modal-label{font-size:12px;font-weight:500;color:var(--text-muted);margin-bottom:5px;display:block}
.print-modal-select{width:100%;border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px;font-size:13px;background:var(--surface);color:var(--text);outline:none;font-family:inherit;transition:border-color .15s;}
.print-modal-select:focus{border-color:var(--brand)}
.print-modal-footer{display:flex;gap:10px;margin-top:20px}
.btn-print-go{flex:1;padding:11px;border-radius:var(--radius);border:none;background:#374151;color:#fff;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:7px;}
.btn-print-go:hover{background:#1f2937}
.btn-print-cancel{padding:11px 16px;border-radius:var(--radius);border:1px solid var(--border);background:transparent;color:var(--text-muted);font-size:14px;cursor:pointer;font-family:inherit;}
.btn-print-cancel:hover{background:var(--bg)}

/* ── PRINT STYLES ── */
@media print {
  body > *:not(#printArea){ display:none !important; }
  #printArea{ display:block !important; }
  @page{ margin:15mm; }
}
#printArea{ display:none; }
.print-doc{ font-family:'DM Sans',sans-serif; font-size:12px; color:#111; }
.print-school{ text-align:center; margin-bottom:18px; border-bottom:2px solid #111; padding-bottom:12px; }
.print-school h1{ font-size:20px; font-weight:700; margin:0 0 2px; }
.print-school p{ font-size:11px; color:#555; margin:0; }
.print-report-title{ font-size:14px; font-weight:600; margin-bottom:4px }
.print-report-meta{ font-size:11px; color:#666; margin-bottom:14px }
.print-table{ width:100%; border-collapse:collapse; font-size:11px; }
.print-table th{ background:#1A1A18; color:#fff; padding:8px 10px; text-align:left; font-weight:600; }
.print-table td{ padding:7px 10px; border-bottom:1px solid #e5e4df; vertical-align:top; }
.print-table tr:nth-child(even) td{ background:#f9f9f7; }
.print-table .bal{ font-weight:700; color:#C0392B; }
.print-table .paid{ color:#0F6E56; }
.print-summary{ margin-top:16px; display:flex; gap:20px; font-size:11px; }
.print-summary div{ padding:8px 14px; background:#f0f0ee; border-radius:6px; }
.print-summary b{ display:block; font-size:15px; font-weight:700; }
.print-footer{ margin-top:20px; text-align:center; font-size:10px; color:#999; border-top:1px solid #ddd; padding-top:8px; }
</style>

<div class="print-modal-overlay" id="printModalOverlay">
  <div class="print-modal-box">
    <div class="print-modal-title">🖨️ Print Student Fee Report</div>
    <div class="print-modal-sub">Select a term to print the full fee report with parent contacts and balances.</div>
    <div class="print-modal-row">
      <label class="print-modal-label">Term</label>
      <select class="print-modal-select" id="printTermSelect">
        <?php foreach(array_reverse($all_terms) as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $t['id']==$current_term_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['term_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="print-modal-footer">
      <button class="btn-print-cancel" onclick="closePrintModal()">Cancel</button>
      <button class="btn-print-go" onclick="doPrint()">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
          <rect x="3" y="1" width="10" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/>
          <path d="M3 10H1.5A.5.5 0 0 1 1 9.5v-4A.5.5 0 0 1 1.5 5h13a.5.5 0 0 1 .5.5v4a.5.5 0 0 1-.5.5H13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          <rect x="3" y="9" width="10" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/>
        </svg>
        Print now
      </button>
    </div>
  </div>
</div>

<!-- Print Output Area (hidden until print) -->
<div id="printArea"></div>

<!-- ══════════════════════════════════════════════════════
     BULK SEND MODAL — MANUAL STEP-BY-STEP
     Works for both fee reminders and broadcast
══════════════════════════════════════════════════════ -->
<div class="bulk-modal" id="bulkModal">
  <div class="bulk-box">

    <!-- Main sending UI (shown while in progress) -->
    <div id="bulkMainUI">
      <div class="bulk-top">
        <div class="bulk-title" id="bulkModalTitle">Sending messages</div>
        <div class="bulk-desc" id="bulkModalDesc">Send each message one at a time. Tap "Open &amp; Send" then come back and tap "Next" to continue.</div>
      </div>

      <div class="bulk-progress-wrap">
        <div class="bulk-step-label">Progress</div>
        <div class="bulk-progress-outer">
          <div class="bulk-progress-inner" id="bulkProgressBar" style="width:0%"></div>
        </div>
        <div class="bulk-counter-row">
          <span class="bulk-counter" id="bulkCounter">1 / 0</span>
          <span class="bulk-pct" id="bulkPct">0%</span>
        </div>
      </div>

      <div class="bulk-recipient-card" id="bulkRecipientCard">
        <div class="bulk-rec-name" id="bulkRecName">—</div>
        <div class="bulk-rec-sub" id="bulkRecSub">—</div>
        <div class="bulk-rec-phone" id="bulkRecPhone">—</div>
      </div>

      <div class="bulk-instruction" id="bulkInstruction">
        Tap <strong>Open &amp; Send</strong> below to open the SMS app with this message pre-filled. Send it, then come back here and tap <strong>Next →</strong> to go to the next parent.
      </div>

      <div class="bulk-actions">
        <button class="btn-bulk-cancel" onclick="cancelBulk()">Cancel</button>
        <button class="btn-bulk-open" id="bulkOpenBtn" onclick="openCurrentSms()">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M14 2H2a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h3l2 2 2-2h5a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
          Open &amp; Send
        </button>
        <button class="btn-bulk-next" id="bulkNextBtn" onclick="advanceBulk()" style="display:none">
          Next →
        </button>
      </div>
    </div>

    <!-- Done state (shown when all sent) -->
    <div id="bulkDoneUI" style="display:none">
      <div class="bulk-done">
        <div class="bulk-done-icon">🎉</div>
        <div class="bulk-done-title" id="bulkDoneTitle">All messages sent!</div>
        <div class="bulk-done-sub" id="bulkDoneSub">You've successfully sent messages to all parents.</div>
        <button class="btn-bulk-close" onclick="closeBulkModal()">Done</button>
      </div>
    </div>

  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"><span id="toastMsg"></span></div>


<!-- DATA PAYLOAD FOR JS -->
<script id="allFeeDataByTerm" type="application/json">
<?php
$js_fee_by_term = [];
foreach($all_fee_rows_by_term as $tid => $rows){
    $js_rows = [];
    foreach($rows as $fr){
        $js_rows[] = [
            'phone'    => $fr['parent_phone'],
            'name'     => $fr['student_name'],
            'parent'   => $fr['parent_name'] ?? '',
            'term'     => $fr['term_name'] ?? '',
            'term_fee' => 'KES '.number_format($fr['term_fee']),
            'paid'     => 'KES '.number_format($fr['amount_paid']),
            'balance'  => 'KES '.number_format($fr['balance']),
            'class'    => $fr['class_name'] ?? '',
            'adm'      => $fr['admission_number'],
        ];
    }
    $js_fee_by_term[$tid] = $js_rows;
}
echo json_encode($js_fee_by_term, JSON_UNESCAPED_UNICODE);
?>
</script>

<script id="allParentsData" type="application/json">
<?php
$js_parents = [];
foreach($call_rows as $cr){
    $js_parents[] = [
        'phone'        => $cr['parent_phone'],
        'student_name' => $cr['student_name'],
        'parent_name'  => $cr['parent_name'] ?? '',
        'class_name'   => $cr['class_name'] ?? '',
        'adm'          => $cr['admission_number'],
    ];
}
echo json_encode($js_parents, JSON_UNESCAPED_UNICODE);
?>
</script>


<script>
// ─── DATA ───────────────────────────────────────────────
var allFeeDataByTerm = {};
var allParentsData   = [];
try{ allFeeDataByTerm = JSON.parse(document.getElementById('allFeeDataByTerm').textContent); } catch(e){}
try{ allParentsData   = JSON.parse(document.getElementById('allParentsData').textContent);   } catch(e){}

var currentTermId = <?= $current_term_id ?>;
var bcastGroup    = 'all';

// ─── BULK STATE ─────────────────────────────────────────
// bulkData: array of {phone, msgBody, name, sub, phone_display}
var bulkData      = [];
var bulkIdx       = 0;   // index of current item being shown
var bulkCancelled = false;

// ─── PANELS ─────────────────────────────────────────────
function openPanel(id){
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
  if(id === 'msgsPanel') renderFeeList(currentTermId);
}
function closePanel(id){
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}
function closePanelOutside(e, id){
  if(e.target === document.getElementById(id)) closePanel(id);
}

// ─── MODAL BACKDROP ─────────────────────────────────────
var mo = document.getElementById('modalOverlay');
if(mo) mo.addEventListener('click', function(e){
  if(e.target === this) window.location.href = '<?= $_SERVER['PHP_SELF'] ?>';
});

// ─── ALERTS ─────────────────────────────────────────────
document.querySelectorAll('.alert').forEach(function(el){
  setTimeout(function(){ el.style.transition='opacity .4s'; el.style.opacity='0'; setTimeout(function(){ el.remove(); },400); }, 4000);
});

// ─── CALLS FILTER ────────────────────────────────────────
function filterCalls(val){
  var q = val.toLowerCase().trim();
  document.querySelectorAll('#callList .call-card').forEach(function(card){
    card.style.display = (!q || card.dataset.name.includes(q)) ? '' : 'none';
  });
}

// ─── TAB SWITCH ─────────────────────────────────────────
function switchTab(tab, btn){
  document.querySelectorAll('.msg-tab').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById('tab-new').style.display       = (tab==='new')       ? '':'none';
  document.getElementById('tab-broadcast').style.display = (tab==='broadcast') ? '':'none';
  document.getElementById('tab-fees').style.display      = (tab==='fees')      ? '':'none';
  if(tab==='fees'){ var sel=document.getElementById('termSelector'); renderFeeList(sel?sel.value:currentTermId); }
  if(tab==='broadcast') updateBcastCount();
}

// ─── CHAR COUNTERS ───────────────────────────────────────
function updateChar(){ document.getElementById('charCount').textContent = document.getElementById('composeMsg').value.length+' / 160'; }
function updateBcastChar(){ document.getElementById('bcastCharCount').textContent = document.getElementById('bcastMsg').value.length+' / 160'; }

// ─── TEMPLATES ───────────────────────────────────────────
var templates = {
  general: function(name){ return "Greetings from Little Friends School!\n\nDear Parent/Guardian"+(name?" of "+name:"")+",\n\nWe hope this message finds you well. Please do not hesitate to contact the school office for any inquiries.\n\nThank you for your continued support.\nLittle Friends School | Tel: 0729251646"; },
  reminder: function(name){ return "Greetings from Little Friends School!\n\nDear Parent/Guardian"+(name?" of "+name:"")+",\n\nThis is a kind reminder that school fee payment for the current term is now due.\n\nKindly ensure prompt payment to avoid disruption to your child's learning. Our accounts office is open Mon–Fri, 7:30 AM – 4:30 PM.\n\nThank you for your cooperation.\nLittle Friends School | Tel: 0729251646"; },
  meeting:  function(name){ return "Greetings from Little Friends School!\n\nDear Parent/Guardian"+(name?" of "+name:"")+",\n\nYou are warmly invited to a parents' meeting at the school premises. Kindly confirm your attendance by contacting the school office.\n\nWe look forward to seeing you.\nLittle Friends School | Tel: 0729251646"; },
  results:  function(name){ return "Greetings from Little Friends School!\n\nDear Parent/Guardian"+(name?" of "+name:"")+",\n\nWe are pleased to inform you that end-of-term results are now available. Kindly visit the school to collect your child's report card.\n\nThank you for your continued partnership.\nLittle Friends School | Tel: 0729251646"; },
  holiday:  function(name){ return "Greetings from Little Friends School!\n\nDear Parent/Guardian"+(name?" of "+name:"")+",\n\nWe wish you and your family a wonderful holiday season. School will reopen as per the official calendar. Please ensure your child reports on time with all required school fees paid.\n\nWarm regards,\nLittle Friends School | Tel: 0729251646"; },
  fees:     function(name){ return "Greetings from Little Friends School!\n\nDear Parent/Guardian"+(name?" of "+name:"")+",\n\nThis is a friendly reminder that school fee payment for the current term is now due. Kindly visit the accounts office to settle any outstanding balance.\n\nOur accounts office is open Mon–Fri, 7:30 AM – 4:30 PM.\n\nThank you for your prompt attention.\nLittle Friends School | Tel: 0729251646"; }
};

function applyTemplate(key, evt){
  document.querySelectorAll('#tab-new .chip').forEach(function(c){ c.classList.remove('active'); });
  evt.target.classList.add('active');
  document.getElementById('composeMsg').value = templates[key](document.getElementById('composeName').value.trim());
  updateChar();
}
function applyBcastTemplate(key, evt){
  document.querySelectorAll('#tab-broadcast .chip').forEach(function(c){ c.classList.remove('active'); });
  evt.target.classList.add('active');
  document.getElementById('bcastMsg').value = templates[key]('');
  updateBcastChar();
}

// ─── SINGLE MESSAGE ──────────────────────────────────────
function sendNewMsg(){
  var phone = document.getElementById('composePhone').value.trim();
  var msg   = document.getElementById('composeMsg').value.trim();
  if(!phone){ showToast('⚠ Please enter a phone number.'); return; }
  if(!msg)  { showToast('⚠ Please type a message.'); return; }
  window.location.href = 'sms:'+phone+'?body='+encodeURIComponent(msg);
}

// ─── BROADCAST HELPERS ───────────────────────────────────
function setBcastGroup(group, btn){
  bcastGroup = group;
  document.querySelectorAll('.rtog-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById('classFilterWrap').classList.toggle('visible', group==='class');
  updateBcastCount();
}
function getFilteredParents(){
  if(bcastGroup==='all') return allParentsData.filter(function(p){ return p.phone; });
  var cls = document.getElementById('bcastClassSelect').value;
  if(!cls) return [];
  return allParentsData.filter(function(p){ return p.phone && p.class_name===cls; });
}
function updateBcastCount(){
  var count = getFilteredParents().length;
  document.getElementById('bcastSelectedCount').textContent = count;
  document.getElementById('recipientBadge').textContent = count+' parent'+(count!==1?'s':'')+' selected';
}

// ─── START BROADCAST ─────────────────────────────────────
function startBroadcast(){
  var baseMsg = document.getElementById('bcastMsg').value.trim();
  if(!baseMsg){ showToast('⚠ Please type a message to broadcast.'); return; }
  var recipients = getFilteredParents();
  if(!recipients.length){ showToast('⚠ No recipients found for your selection.'); return; }
  var label = bcastGroup==='class'
    ? (document.getElementById('bcastClassSelect').options[document.getElementById('bcastClassSelect').selectedIndex].text+' class')
    : 'all parents';
  if(!confirm('Send to '+recipients.length+' recipients ('+label+')?\n\nYou will send each SMS individually one at a time.')) return;

  // Build bulk data
  bulkData = recipients.map(function(r){
    var personalised = baseMsg.replace(/Dear Parent\/Guardian/g, 'Dear '+(r.parent_name||'Parent/Guardian'));
    return {
      phone: r.phone,
      msgBody: personalised,
      name: r.student_name,
      sub: (r.parent_name||'Parent')+( r.class_name ? ' · '+r.class_name : ''),
      phone_display: r.phone
    };
  });

  document.getElementById('bulkModalTitle').textContent = '📢 Broadcast Message';
  document.getElementById('bulkModalDesc').textContent  = 'Send each message one at a time. Tap "Open & Send" then come back and tap "Next →" to continue.';
  openBulkModal();
}

// ─── START FEE BULK ──────────────────────────────────────
function startBulkFees(termId){
  var rows = allFeeDataByTerm[termId] || [];
  var withPhone = rows.filter(function(r){ return r.phone && r.phone.trim(); });
  if(!withPhone.length){ showToast('No parents with phone numbers to message.'); return; }
  if(!confirm('This will walk you through sending a fee reminder to each of the '+withPhone.length+' parents with outstanding balances. Continue?')) return;

  bulkData = withPhone.map(function(r){
    return {
      phone: r.phone,
      msgBody: buildFeeReminder(r),
      name: r.name,
      sub: (r.parent||'Parent')+' · Balance: '+r.balance,
      phone_display: r.phone
    };
  });

  document.getElementById('bulkModalTitle').textContent = '💰 Fee Reminders';
  document.getElementById('bulkModalDesc').textContent  = 'Send each reminder one at a time. Tap "Open & Send" then come back and tap "Next →" to continue.';
  openBulkModal();
}

// ─── BULK MODAL CORE ─────────────────────────────────────
function openBulkModal(){
  bulkIdx       = 0;
  bulkCancelled = false;

  document.getElementById('bulkMainUI').style.display = '';
  document.getElementById('bulkDoneUI').style.display = 'none';
  document.getElementById('bulkModal').classList.add('open');

  renderBulkStep();
}

function renderBulkStep(){
  if(bulkIdx >= bulkData.length){
    showBulkDone();
    return;
  }
  var r     = bulkData[bulkIdx];
  var total = bulkData.length;
  var pct   = Math.round(((bulkIdx) / total) * 100);

  document.getElementById('bulkProgressBar').style.width = pct+'%';
  document.getElementById('bulkCounter').textContent = (bulkIdx+1)+' / '+total;
  document.getElementById('bulkPct').textContent     = pct+'%';

  document.getElementById('bulkRecName').textContent  = r.name;
  document.getElementById('bulkRecSub').textContent   = r.sub;
  document.getElementById('bulkRecPhone').textContent = r.phone_display;

  // Reset buttons: show Open, hide Next
  document.getElementById('bulkOpenBtn').style.display = '';
  document.getElementById('bulkNextBtn').style.display = 'none';
}

function openCurrentSms(){
  if(bulkIdx >= bulkData.length) return;
  var r = bulkData[bulkIdx];

  var a = document.createElement('a');
  a.href = 'sms:'+r.phone+'?body='+encodeURIComponent(r.msgBody);
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);

  // After opening SMS, show Next button so user can advance manually
  document.getElementById('bulkInstruction').innerHTML = 'SMS app opened for <strong>'+escHtml(r.name)+'</strong>. Send the message, then tap <strong>Next →</strong> to continue.';
  document.getElementById('bulkOpenBtn').style.display = 'none';
  document.getElementById('bulkNextBtn').style.display = '';
}

function advanceBulk(){
  bulkIdx++;
  if(bulkIdx >= bulkData.length){
    showBulkDone();
    return;
  }
  // Reset instruction
  document.getElementById('bulkInstruction').innerHTML = 'Tap <strong>Open &amp; Send</strong> below to open the SMS app with this message pre-filled. Send it, then come back here and tap <strong>Next →</strong> to go to the next parent.';
  renderBulkStep();
}

function showBulkDone(){
  var total = bulkData.length;
  document.getElementById('bulkProgressBar').style.width = '100%';
  document.getElementById('bulkCounter').textContent = total+' / '+total;
  document.getElementById('bulkPct').textContent = '100%';

  document.getElementById('bulkMainUI').style.display = 'none';
  document.getElementById('bulkDoneUI').style.display = '';
  document.getElementById('bulkDoneTitle').textContent = 'All done! 🎉';
  document.getElementById('bulkDoneSub').textContent   = 'You\'ve sent messages to all '+total+' parents. Great job!';
}

function cancelBulk(){
  bulkCancelled = true;
  closeBulkModal();
  showToast('Cancelled after '+(bulkIdx)+' / '+bulkData.length+' messages.');
}

function closeBulkModal(){
  document.getElementById('bulkModal').classList.remove('open');
}

// ─── FEE LIST RENDER ─────────────────────────────────────
function renderFeeList(termId){
  termId = parseInt(termId);
  var rows = allFeeDataByTerm[termId] || [];
  var container = document.getElementById('feeList');
  if(!container) return;

  if(!rows.length){
    container.innerHTML = '<div class="no-fees"><div style="font-size:28px;margin-bottom:8px">🎉</div><div style="font-weight:600;margin-bottom:4px">All clear!</div>All fee balances are settled for this term.</div>';
    return;
  }

  var html = '<div style="height:3px;border-radius:2px;margin-bottom:10px;background:linear-gradient(90deg,#E24B4A,#F5A623)"></div>';
  html += '<div class="fee-header">';
  html += '<div><div class="fee-header-left">'+rows.length+' student'+(rows.length!==1?'s':'')+' with balance</div>';
  html += '<div class="fee-header-sub">Sorted by highest balance</div></div>';
  html += '<button class="btn-bulk" onclick="startBulkFees('+termId+')">Send all ('+rows.length+')</button>';
  html += '</div>';

  rows.forEach(function(fr){
    var ini = fr.name.split(' ').map(function(w){ return w.charAt(0).toUpperCase(); }).join('').substring(0,2);
    var smsBody = buildFeeReminder(fr);
    var hasPhone = fr.phone && fr.phone.trim();

    html += '<div class="fee-card">';
    html += '<div class="fee-top">';
    html += '<div class="fee-avatar">'+escHtml(ini)+'</div>';
    html += '<div class="fee-name">'+escHtml(fr.name)+'</div>';
    html += '<span class="fee-badge">'+escHtml(fr.balance)+'</span>';
    html += '</div>';

    html += '<div class="fee-meta">';
    if(fr['class']) html += '<span>🏫 '+escHtml(fr['class'])+'</span>';
    html += '<span>📋 '+escHtml(fr.adm)+'</span>';
    if(!hasPhone)   html += '<span style="color:#E24B4A">⚠ No phone</span>';
    html += '</div>';

    // Clean 3-pill summary: Term Fee | Paid | Balance
    html += '<div class="fee-summary">';
    html += '<span class="fee-pill fee-pill-paid">Term fee: '+escHtml(fr.term_fee)+'</span>';
    html += '<span class="fee-pill fee-pill-paid">✓ Paid: '+escHtml(fr.paid)+'</span>';
    html += '<span class="fee-pill fee-pill-balance">Balance: '+escHtml(fr.balance)+'</span>';
    html += '</div>';

    if(hasPhone){
      html += '<div class="fee-actions">';
      html += '<a href="sms:'+encodeURIComponent(fr.phone)+'?body='+encodeURIComponent(smsBody)+'" class="btn-sms-one"><svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M14 2H2a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h3l2 2 2-2h5a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg> Send reminder</a>';
      html += '<a href="tel:'+encodeURIComponent(fr.phone)+'" class="btn-call-one"><svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M2 3.5C2 2.67 2.67 2 3.5 2h1.618a1 1 0 0 1 .95.684l.96 2.88a1 1 0 0 1-.29 1.06l-.9.8a8.02 8.02 0 0 0 3.738 3.738l.8-.9a1 1 0 0 1 1.06-.29l2.88.96A1 1 0 0 1 14 12v1.5A1.5 1.5 0 0 1 12.5 15C6.701 15 2 10.299 2 4.5V3.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg> Call</a>';
      html += '</div>';
    }
    html += '</div>';
  });

  container.innerHTML = html;
}

// ─── FEE REMINDER MESSAGE BODY ────────────────────────────
function buildFeeReminder(r){
  var body  = "Greetings from Little Friends School!\n\n";
  body += "Dear "+(r.parent||'Parent/Guardian')+",\n\n";
  body += "This is a fee balance reminder for your child, "+r.name;
  if(r.adm)        body += " (Adm: "+r.adm+")";
  if(r['class'])   body += " - "+r['class'];
  body += ".\n\n";
  body += r.term+" FEE SUMMARY:\n";
  body += "  Term Fee  : "+r.term_fee+"\n";
  body += "  Paid      : "+r.paid+"\n";
  body += "  Balance   : "+r.balance+"\n\n";
  body += "Kindly clear the outstanding balance at your earliest convenience.\n";
  body += "Accounts office: Mon-Fri, 7:30AM - 4:30PM.\n\n";
  body += "Thank you.\nLittle Friends School | Tel: 0729251646";
  return body;
}

// ─── UTILS ───────────────────────────────────────────────
function escHtml(str){
  if(!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showToast(msg){
  var t = document.getElementById('toast');
  document.getElementById('toastMsg').textContent = msg;
  t.classList.add('show');
  clearTimeout(t._timer);
  t._timer = setTimeout(function(){ t.classList.remove('show'); }, 3500);
}

// ─── PRINT ───────────────────────────────────────────────
function openPrintModal(){
  document.getElementById('printModalOverlay').classList.add('open');
}
function closePrintModal(){
  document.getElementById('printModalOverlay').classList.remove('open');
}

function doPrint(){
  var termId = parseInt(document.getElementById('printTermSelect').value);
  var termName = document.getElementById('printTermSelect').options[document.getElementById('printTermSelect').selectedIndex].text.trim();
  var rows = allFeeDataByTerm[termId] || [];

  // Build ALL students for this term (we need the full student list, not just those with balance)
  // We'll use allParentsData for names + phone and fee data for amounts
  // Build a lookup of fee data by student name+adm
  var feeMap = {};
  rows.forEach(function(r){ feeMap[r.adm] = r; });

  // Build rows: all students who have fee data for this term
  // (rows already contains students with balance > 0; we show them)
  var totalBalance = 0, totalPaid = 0, totalFee = 0;
  var tableRows = '';
  var idx = 1;
  rows.forEach(function(r){
    totalFee     += parseFloat((r.term_fee||'').replace(/[^0-9.]/g,''))||0;
    totalPaid    += parseFloat((r.paid||'').replace(/[^0-9.]/g,''))||0;
    totalBalance += parseFloat((r.balance||'').replace(/[^0-9.]/g,''))||0;
    tableRows += '<tr>';
    tableRows += '<td>'+idx+'</td>';
    tableRows += '<td><b>'+escHtml(r.name)+'</b><br><span style="color:#888">'+escHtml(r.adm)+'</span></td>';
    tableRows += '<td>'+escHtml(r['class']||'-')+'</td>';
    tableRows += '<td>'+escHtml(r.parent||'-')+'</td>';
    tableRows += '<td>'+escHtml(r.phone||'—')+'</td>';
    tableRows += '<td>'+escHtml(r.term_fee)+'</td>';
    tableRows += '<td class="paid">'+escHtml(r.paid)+'</td>';
    tableRows += '<td class="bal">'+escHtml(r.balance)+'</td>';
    tableRows += '</tr>';
    idx++;
  });

  var now = new Date().toLocaleDateString('en-KE',{year:'numeric',month:'long',day:'numeric'});

  var html = '<div class="print-doc">';
  html += '<div class="print-school"><h1>Little Friends School</h1><p>P.O. Box · Tel: 0729251646</p></div>';
  html += '<div class="print-report-title">Fee Balance Report — '+escHtml(termName)+'</div>';
  html += '<div class="print-report-meta">Printed: '+now+' &nbsp;·&nbsp; '+rows.length+' student'+(rows.length!==1?'s':'')+' with outstanding balance</div>';
  html += '<table class="print-table">';
  html += '<thead><tr><th>#</th><th>Student</th><th>Class</th><th>Parent</th><th>Phone</th><th>Term Fee</th><th>Paid</th><th>Balance</th></tr></thead>';
  html += '<tbody>'+tableRows+'</tbody>';
  html += '</table>';
  html += '<div class="print-summary">';
  html += '<div><b>KES '+number_format(totalFee)+'</b>Total Fees</div>';
  html += '<div style="color:#0F6E56"><b>KES '+number_format(totalPaid)+'</b>Total Paid</div>';
  html += '<div style="color:#C0392B"><b>KES '+number_format(totalBalance)+'</b>Total Balance</div>';
  html += '</div>';
  html += '<div class="print-footer">Little Friends School &mdash; Generated '+now+'</div>';
  html += '</div>';

  document.getElementById('printArea').innerHTML = html;
  closePrintModal();
  setTimeout(function(){ window.print(); }, 100);
}

function number_format(n){
  return Math.round(n).toLocaleString('en-KE');
}

// ─── INIT ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
  renderFeeList(currentTermId);
  updateBcastCount();
});
</script>
</body>
</html>