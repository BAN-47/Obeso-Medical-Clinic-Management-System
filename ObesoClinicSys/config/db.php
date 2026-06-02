<?php

class Database {
    private $conn;

    public function connect() {

        if ($this->conn === null) {

            $url = getenv("DATABASE_URL");

            if (!$url) {
                die("Missing DATABASE_URL");
            }

            $db = parse_url($url);

            $host = $db["host"];
            $port = $db["port"] ?? 3306;
            $user = $db["user"];
            $pass = $db["pass"] ?? ""; // handles no password
            $dbname = ltrim($db["path"], "/");

            try {
                $this->conn = new PDO(
                    "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8",
                    $user,
                    $pass
                );

                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }

        return $this->conn;
    }
}