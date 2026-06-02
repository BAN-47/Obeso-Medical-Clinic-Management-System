<?php

class Database {
    private $host;
    private $port;
    private $dbname;
    private $user;
    private $pass;
    private $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST');
        $this->port = getenv('DB_PORT');
        $this->dbname = getenv('DB_NAME');
        $this->user = getenv('DB_USER');
        $this->pass = getenv('DB_PASS') ?: "";
    }

    public function connect() {

        if ($this->conn === null) {

            try {
                if (!$this->host || !$this->port || !$this->dbname || !$this->user) {
                    die("Missing DB environment variables");
                }

                $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8";

                $this->conn = new PDO(
                    $dsn,
                    $this->user,
                    $this->pass
                );

                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            } catch (PDOException $e) {
                die("DB Connection failed: " . $e->getMessage());
            }
        }

        return $this->conn;
    }
}