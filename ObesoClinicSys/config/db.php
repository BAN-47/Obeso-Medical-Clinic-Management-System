<?php
     class Database {
          private $host = "mysql-udg6.railway.internal";
          private $dbname = "railway";
          private $username = "root";
          private $password = "qltVAiWVzXuKrskoUClPwMCbRZeqpxUt";
          private $conn;


          public function connect() {
               if ($this->conn == null) {
                    try {
                         $this->conn = new PDO("mysql:host={$this->host};dbname={$this->dbname}",
                                        $this->username, $this->password);
                         $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    }catch(PDOException $e) {
                         echo "Connected failed: " . $e->getMessage();
                    }
               }

               return $this->conn;
          }
     }
 ?>