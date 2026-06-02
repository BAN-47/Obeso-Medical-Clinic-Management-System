<?php

class Database {
    private $conn;

    public function connect() {

        if ($this->conn === null) {

            // ✅ RAILWAY MYSQL VARIABLES (PRIMARY METHOD)
            $host = getenv("MYSQLHOST");
            $port = getenv("MYSQLPORT") ?: 3306;
            $user = getenv("MYSQLUSER");
            $pass = getenv("MYSQLPASSWORD");
            $dbname = getenv("MYSQLDATABASE");

            // ❌ fallback to DATABASE_URL (optional compatibility)
            if (!$host && getenv("DATABASE_URL")) {
                $url = getenv("DATABASE_URL");
                $db = parse_url($url);

                $host = $db["host"];
                $port = $db["port"] ?? 3306;
                $user = $db["user"];
                $pass = $db["pass"] ?? "";
                $dbname = ltrim($db["path"], "/");
            }

            // 🚨 VALIDATION
            if (!$host || !$user || !$dbname) {
                die("❌ Missing DB environment variables");
            }

            try {
                $this->conn = new PDO(
                    "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "USE `$dbname`"
                    ]
                );

                // optional debug (remove later)
                // echo "✅ DB Connected to: $dbname";

            } catch (PDOException $e) {
                die("❌ DB Connection failed: " . $e->getMessage());
            }
        }

        return $this->conn;
    }
}