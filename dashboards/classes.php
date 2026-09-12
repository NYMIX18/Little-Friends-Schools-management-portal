<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Add Class
if(isset($_POST['add'])) {
    $class_name = $_POST['class_name'];
    $termly_fees = $_POST['termly_fees'];
    mysqli_query($conn, "INSERT INTO classes (class_name, termly_fees) VALUES ('$class_name', '$termly_fees')");
}

// Fetch classes
$classes = mysqli_query($conn, "SELECT * FROM classes");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Classes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h3>Manage Classes & Termly Fees</h3>

    <form method="POST" class="mb-3">
        <input type="text" name="class_name" class="form-control mb-2" placeholder="Class Name" required>
        <input type="number" name="termly_fees" class="form-control mb-2" placeholder="Termly Fees" step="0.01" required>
        <button name="add" class="btn btn-primary">Add Class</button>
    </form>

    <h5>Existing Classes</h5>
    <table class="table table-bordered">
        <tr>
            <th>Class Name</th>
            <th>Termly Fees</th>
        </tr>
        <?php while($row = mysqli_fetch_assoc($classes)) { ?>
        <tr>
            <td><?php echo $row['class_name']; ?></td>
            <td><?php echo number_format($row['termly_fees'], 2); ?></td>
        </tr>
        <?php } ?>
    </table>

    <a href="admin.php" class="btn btn-secondary">Back</a>
</div>
</body>
</html>