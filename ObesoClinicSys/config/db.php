<?php
     class Database {
          private $host;
          private $dbname;
          private $username;
          private $password;
          private $conn;

          public function __construct() {
               $this->host = getenv('DB_HOST') ?: 'mysql-udg6.railway.internal';
               $this->dbname = getenv('DB_NAME') ?: 'obeso_clinic_database';
               $this->username = getenv('DB_USER') ?: 'root';
               $this->password = getenv('DB_PASS') ?: 'qltVAiWVzXuKrskoUClPwMCbRZeqpxUt';
          }

          public function connect() {
               if ($this->conn == null) {
                    try {
                         if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
                              throw new Exception('PDO MySQL driver not found. Enable the pdo_mysql extension.');
                         }

                         $this->conn = new PDO("mysql:host={$this->host};dbname={$this->dbname}",
                                        $this->username, $this->password);
                         $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    }catch(PDOException $e) {
                         echo "Connected failed: " . $e->getMessage();
                    }catch(Exception $e) {
                         echo "Connected failed: " . $e->getMessage();
                    }
               }

               return $this->conn;
          }
     }
 ?>