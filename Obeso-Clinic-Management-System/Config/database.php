<?php

class Database {
    private $conn;

    public function connect() {
        if ($this->conn === null) {

            $url = getenv("DATABASE_URL");

            if (!$url) {
                die("Missing DATABASE_URL");
            }

            $dbparts = parse_url($url);

            $host = $dbparts["host"];
            $port = $dbparts["port"];
            $user = $dbparts["user"];
            $pass = $dbparts["pass"];
            $dbname = ltrim($dbparts["path"], "/");

            $this->conn = new PDO(
                "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8",
                $user,
                $pass
            );

            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->conn;
    }
}