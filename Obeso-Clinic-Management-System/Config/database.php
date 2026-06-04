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
                         $this->conn = new PDO("mysql:host={$this->host};dbname={$this->dbname}",
                                        $this->username, $this->password);
                         $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    }catch(PDOException $e) {
                         echo "Connected failed : " . $e->getMessage();
                    }
               }

               return $this->conn;
          }

          // Auto-start Python API if not running
          public static function ensureAPIRunning() {

               $apiUrl = "http://127.0.0.1:8000/";

               // ================= CHECK IF API RUNNING =================

               $ch = curl_init($apiUrl);

               curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
               curl_setopt($ch, CURLOPT_TIMEOUT, 2);

               $response = @curl_exec($ch);

               $httpCode = @curl_getinfo($ch, CURLINFO_HTTP_CODE);

               curl_close($ch);

               // ================= API ALREADY RUNNING =================

               if ($httpCode == 200) {
                    return true;
               }

               // ================= PATHS =================

               $pythonPath = "C:\\xampp\\htdocs\\VS_PHP\\Obeso-Medical-Clinic-Management-System\\.venv\\Scripts\\python.exe";

               $appPath = realpath(__DIR__ . "/../python_ai/app.py");

               // ================= VALIDATE FILES =================

               if (!file_exists($pythonPath)) {
                    error_log("Python not found");
                    return false;
               }

               if (!file_exists($appPath)) {
                    error_log("app.py not found");
                    return false;
               }

               // ================= START FLASK API =================

               $command = "start /B \"\" \"$pythonPath\" \"$appPath\"";

               pclose(popen("cmd /c $command", "r"));

               // ================= WAIT FOR SERVER =================

               sleep(3);

               return true;
          }
     }

     // Auto-start API on every page load
     Database::ensureAPIRunning();
 ?>
