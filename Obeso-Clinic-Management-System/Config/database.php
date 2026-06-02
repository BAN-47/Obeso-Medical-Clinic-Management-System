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