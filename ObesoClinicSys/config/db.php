<?php

class Database {
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        // Use Railway ENV variables ONLY
        $this->host = getenv('DB_HOST');
        $this->dbname = getenv('DB_NAME');
        $this->username = getenv('DB_USER');
        $this->password = getenv('DB_PASS');
    }

    public function connect() {
        if ($this->conn === null) {

            try {
                // Ensure all values exist
                if (!$this->host || !$this->dbname || !$this->username) {
                    throw new Exception("Missing DB environment variables");
                }

                // Create PDO connection
                $this->conn = new PDO(
                    "mysql:host={$this->host};dbname={$this->dbname};charset=utf8",
                    $this->username,
                    $this->password
                );

                // Throw real errors
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