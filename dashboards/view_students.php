<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Fetch students with class info, ordered by admission number (chronological)
$query = mysqli_query($conn, "
SELECT students.*, users.name, classes.class_name
FROM students
JOIN users ON students.user_id = users.id
LEFT JOIN classes ON students.class_id = classes.id
ORDER BY students.admission_number ASC
");

// Check query success
if(!$query){
    die("Query Failed: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>LITTLE FRIENDS SCHOOLS | Students List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f0f4f7, #d9e2ec);
            min-height: 100vh;
        }
        .header {
            text-align: center;
            padding: 30px 10px;
            background-color: #0d6efd;
            color: white;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .header h1 { margin:0; font-size:2rem; }
        .table-container {
            background:white;
            padding:20px;
            border-radius:12px;
            box-shadow:0 4px 15px rgba(0,0,0,0.1);
            overflow-x:auto;
        }
        table th {
            background-color:#0d6efd;
            color:white;
        }
        table td, table th { text-align:center; vertical-align:middle; }
        .btn-print {
            float:right;
            margin-bottom:10px;
        }
        @media print {
            body { background: white; }
            .btn-print, .btn-back { display:none; }
            .header { box-shadow:none; border-radius:0; }
        }
    </style>

    <script>
        function printPage() {
            window.print();
        }
    </script>
</head>
<body>

<div class="container mt-4">

    <!-- SCHOOL HEADER -->
    <div class="header">
        <h1>LITTLE FRIENDS SCHOOLS</h1>
        <p>Students List</p>
    </div>

    <!-- Print Button -->
    <button class="btn btn-success btn-print" onclick="printPage()">
        <i class="bi bi-printer-fill"></i> Print List
    </button>

    <!-- STUDENTS TABLE -->
    <div class="table-container">
        <table class="table table-striped table-bordered table-hover">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Admission Number</th>
                    <th>Class</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $count = 1;
                while($row = mysqli_fetch_assoc($query)) { ?>
                <tr>
                    <td><?php echo $count++; ?></td>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars($row['admission_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['class_name'] ?? 'No Class'); ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- Back Button -->
    <a href="admin.php" class="btn btn-secondary btn-back mt-3">
        <i class="bi bi-arrow-left-circle"></i> Back to Dashboard
    </a>

</div>

</body>
</html>