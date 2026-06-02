<?php

class Database {
    private $conn;

    public function connect() {

        if ($this->conn === null) {

            $url = getenv("DATABASE_URL");

            if (!$url) {
                die("DATABASE_URL missing");
            }

            $db = parse_url($url);

            $host = $db["host"];
            $port = $db["port"] ?? 3306;
            $user = $db["user"];
            $pass = $db["pass"] ?? "";
            $dbname = ltrim($db["path"], "/");

            try {
                $this->conn = new PDO(
                    "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8",
                    $user,
                    $pass
                );

                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            } catch (PDOException $e) {
                die("DB Connection failed: " . $e->getMessage());
            }
        }

        return $this->conn;
    }
}