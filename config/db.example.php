<?php
// Copy this file to db.php and fill in your local/production credentials.
// db.php is gitignored so your real credentials are never committed.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "";
$db   = "little_friends_schools";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
