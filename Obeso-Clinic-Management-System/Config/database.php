<?php
     class Database {
          private $host = "localhost";
          private $dbname = "obeso_clinic_database";
          private $username = "root";
          private $password = "dealwiththeletters12";
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
               $apiUrl = "http://localhost:8000/";
               $timeout = 2;

               // Check if API is already running
               $ch = curl_init($apiUrl);
               curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
               curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
               curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
               curl_setopt($ch, CURLOPT_FAILONERROR, true);
               
               $response = @curl_exec($ch);
               $httpCode = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
               curl_close($ch);

               // If API is running, return
               if ($httpCode == 200) {
                    return true;
               }

               // API not running, try to start it
               $venvPath = realpath(__DIR__ . "\\..\\..\\.venv\\Scripts\\python.exe");
               $globalPython = "C:\\Users\\BAN\\AppData\\Local\\Programs\\Python\\Python314\\python.exe";
               $pythonPath = $venvPath ?: $globalPython;
               $apiPath = realpath(__DIR__ . "\\..\\python_ai\\app.py");
               $checkUrl = "http://127.0.0.1:8000/";

               if ($pythonPath && file_exists($pythonPath) && $apiPath && file_exists($apiPath)) {
                    $startCmd = 'cmd.exe /c start /B "" ' . escapeshellarg($pythonPath) . ' ' . escapeshellarg($apiPath);
                    pclose(popen($startCmd, "r"));

                    // Wait for the API to become available
                    $tries = 0;
                    while ($tries < 10) {
                         sleep(1);
                         $ch = curl_init($checkUrl);
                         curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                         curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                         curl_setopt($ch, CURLOPT_FAILONERROR, true);
                         $response = @curl_exec($ch);
                         $httpCode = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
                         curl_close($ch);

                         if ($httpCode == 200) {
                              return true;
                         }
                         $tries++;
                    }
               }

               return false;
          }
     }

     // Auto-start API on every page load
     Database::ensureAPIRunning();
 ?>