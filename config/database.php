<?php
// Database Configuration
// ⚠️ IMPORTANT: Update these values with your actual database credentials
define('DB_HOST', 'db');
define('DB_USER', 'root');
define('DB_PASS', '123');
define('DB_NAME', 'gacs_db');

// Create database connection
function getDBConnection() {
    static $conn = null;

    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $conn->set_charset("utf8mb4");
    }

    return $conn;
}
