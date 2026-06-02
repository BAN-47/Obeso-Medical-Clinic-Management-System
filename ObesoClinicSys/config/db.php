<?php

class Database {
    private $conn;

    public function connect() {

        if ($this->conn === null) {

            $host = getenv("MYSQLHOST");
            $port = getenv("MYSQLPORT") ?: 3306;
            $user = getenv("MYSQLUSER");
            $pass = getenv("MYSQLPASSWORD");
            $dbname = getenv("MYSQLDATABASE");

            if (!$host || !$user || !$dbname) {
                die("❌ Missing Railway MySQL variables");
            }

            try {
                $this->conn = new PDO(
                    "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]
                );

                // optional test
                // echo "DB CONNECTED OK";

            } catch (PDOException $e) {
                die("❌ DB Connection failed: " . $e->getMessage());
            }
        }

        return $this->conn;
    }
}