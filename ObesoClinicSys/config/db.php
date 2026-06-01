<?php
     class Database {
          private $host = "localhost";
          private $dbname = "obeso_clinic_database";
          private $username = "root";
          private $password = "";
          private $conn;


          public function connect() {
    if ($this->conn == null) {
        try {

            $host = getenv('mysql-udg6.railway.internal');
            $db   = getenv('railway');
            $user = getenv('root');
            $pass = getenv('qltVAiWVzXuKrskoUClPwMCbRZeqpxUt');

            $this->conn = new PDO(
                "mysql:host=$host;dbname=$db;charset=utf8mb4",
                $user,
                $pass
            );

            $this->conn->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );

        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    return $this->conn;
}
     }
 ?>