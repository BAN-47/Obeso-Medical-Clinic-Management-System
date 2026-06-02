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

          // Auto-start Python API if not running on Windows only
          public static function ensureAPIRunning() {
               if (PHP_OS_FAMILY !== 'Windows') {
                    return false;
               }

               $apiUrl = "https://obeso-medical-clinic-management-system-3.onrender.com/"; // Update with your Flask server URL if needed

               // ================= CHECK IF API RUNNING =================
               $ch = curl_init($apiUrl);
               curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
               curl_setopt($ch, CURLOPT_TIMEOUT, 2);
               $response = @curl_exec($ch);
               $httpCode = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
               curl_close($ch);

               if ($httpCode == 200) {
                    return true;
               }

               $pythonPath = "C:\\xampp\\htdocs\\VS_PHP\\Obeso-Medical-Clinic-Management-System\\.venv\\Scripts\\python.exe";
               $appPath = realpath(__DIR__ . "/../python_ai/app.py");

               if (!file_exists($pythonPath)) {
                    error_log("Python not found");
                    return false;
               }

               if (!file_exists($appPath)) {
                    error_log("app.py not found");
                    return false;
               }

               $command = "start /B \"\" \"$pythonPath\" \"$appPath\"";
               pclose(popen("cmd /c $command", "r"));
               sleep(3);

               return true;
          }
     }

     // Auto-start API on Windows only
     if (PHP_OS_FAMILY === 'Windows') {
          Database::ensureAPIRunning();
     }
 ?>