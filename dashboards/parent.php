<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'parent') {
    header("Location: ../auth/login.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Parent Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark bg-dark p-3">
    <span class="navbar-brand">Parent Panel</span>
    <a href="../auth/logout.php" class="btn btn-danger">Logout</a>
</nav>

<div class="container mt-4">
    <h3>Welcome, <?php echo $_SESSION['name']; ?></h3>

    <a href="child_results.php" class="btn btn-success mt-3">View Child Results</a>
    <a href="child_fees.php" class="btn btn-warning mt-3">View Fees</a>
</div>

</body>
</html>