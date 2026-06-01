<?php
     class Database {
          private $host = "sql105.infinityfree.com";
          private $dbname = "if0_40936666_obeso_clinic_database";
          private $username = "if0_40936666";
          private $password = "HlUf9MHo1WBXMk";
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