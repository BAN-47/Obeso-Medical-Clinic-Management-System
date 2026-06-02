<?php

class Database {
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $port;
    private $conn;

    public function __construct() {
        // Railway MySQL ENV variables
        $this->host = getenv('MYSQLHOST');
        $this->dbname = getenv('MYSQLDATABASE');
        $this->username = getenv('MYSQLUSER');
        $this->password = getenv('MYSQLPASSWORD');
        $this->port = getenv('MYSQLPORT');
    }

    public function connect() {
        if ($this->conn === null) {

            try {
                // Validate environment variables
                if (!$this->host || !$this->dbname || !$this->username || !$this->port) {
                    throw new Exception("Missing Railway MySQL environment variables");
                }

                // Create PDO connection
                $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8";

                $this->conn = new PDO(
                    $dsn,
                    $this->username,
                    $this->password
                );

                // Enable error mode
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            } catch (Exception $e) {
                die("Configuration error: " . $e->getMessage());
            }
        }

        return $this->conn;
    }
}