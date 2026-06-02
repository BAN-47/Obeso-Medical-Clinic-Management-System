<?php

class Database {
    private $host;
    private $port;
    private $dbname;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST');
        $this->port = getenv('DB_PORT');
        $this->dbname = getenv('DB_NAME');
        $this->username = getenv('DB_USER');
        $this->password = getenv('DB_PASS');
    }

    public function connect() {
        if ($this->conn === null) {

            try {
                if (!$this->host || !$this->port || !$this->dbname) {
                    throw new Exception("Missing DB environment variables");
                }

                $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8";

                $this->conn = new PDO(
                    $dsn,
                    $this->username,
                    $this->password
                );

                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }

        return $this->conn;
    }
}