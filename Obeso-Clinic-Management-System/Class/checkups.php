<?php
class Checkup {
    private $conn;
    private $table = "checkups";

    public function __construct($db) {
        $this->conn = $db;
    }

    /* ================= DUPLICATE CHECK ================= */
    public function exists($patient_id, $checkup_date, $doc_id, $diagnosis = null) {
        $sql = "SELECT checkup_id
                FROM {$this->table}
                WHERE patient_id = ?
                  AND checkup_date = ?
                  AND doc_id = ?";

        $params = [$patient_id, $checkup_date, $doc_id];

        if ($diagnosis !== null) {
            $sql .= " AND diagnosis = ?";
            $params[] = $diagnosis;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* ================= ADD CHECKUP ================= */
    public function add(
        $patient_id,
        $checkup_date,
        $doc_id = null,
        $chief_complaint = null,
        $history_present_illness = null,
        $diagnosis = null,
        $blood_pressure = null,
        $respiratory_rate = null,
        $weight = null,
        $heart_rate = null,
        $temperature = null,
        $doc_fullname = null
    ) {
        $existing = $this->exists($patient_id, $checkup_date, $doc_id, $diagnosis);
        if ($existing) {
            throw new Exception("Duplicate checkup detected for this patient on the same date.");
        }

        $sql = "INSERT INTO {$this->table} 
                (patient_id, checkup_date, doc_id, doc_fullname,
                chief_complaint, history_present_illness, diagnosis,
                blood_pressure, respiratory_rate, weight,
                heart_rate, temperature, status)
                VALUES
                (:patient_id, :checkup_date, :doc_id, :doc_fullname,
                :chief_complaint, :history_present_illness, :diagnosis,
                :blood_pressure, :respiratory_rate, :weight,
                :heart_rate, :temperature, 'completed')";

        $stmt = $this->conn->prepare($sql);

        $stmt->bindValue(':patient_id', $patient_id, PDO::PARAM_INT);
        $stmt->bindValue(':checkup_date', $checkup_date);
        $stmt->bindValue(':doc_id', $doc_id, $doc_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':doc_fullname', $doc_fullname);
        $stmt->bindValue(':chief_complaint', $chief_complaint);
        $stmt->bindValue(':history_present_illness', $history_present_illness);
        $stmt->bindValue(':diagnosis', $diagnosis);
        $stmt->bindValue(':blood_pressure', $blood_pressure);
        $stmt->bindValue(':respiratory_rate', $respiratory_rate);
        $stmt->bindValue(':weight', $weight);
        $stmt->bindValue(':heart_rate', $heart_rate);
        $stmt->bindValue(':temperature', $temperature);

        $stmt->execute();
        return $this->conn->lastInsertId();
    }

    /* ================= UPDATE CHECKUP ================= */
    public function update(
        $checkup_id,
        $patient_id,
        $checkup_date,
        $doc_id = null,
        $chief_complaint = null,
        $history_present_illness = null,
        $diagnosis = null,
        $blood_pressure = null,
        $respiratory_rate = null,
        $weight = null,
        $heart_rate = null,
        $temperature = null,
        $doc_fullname = null
    ) {
        $sql = "UPDATE {$this->table}
                SET patient_id = :patient_id,
                    checkup_date = :checkup_date,
                    doc_id = :doc_id,
                    doc_fullname = :doc_fullname,
                    chief_complaint = :chief_complaint,
                    history_present_illness = :history_present_illness,
                    diagnosis = :diagnosis,
                    blood_pressure = :blood_pressure,
                    respiratory_rate = :respiratory_rate,
                    weight = :weight,
                    heart_rate = :heart_rate,
                    temperature = :temperature
                WHERE checkup_id = :checkup_id";

        $stmt = $this->conn->prepare($sql);

        $stmt->bindValue(':patient_id', $patient_id, PDO::PARAM_INT);
        $stmt->bindValue(':checkup_date', $checkup_date);
        $stmt->bindValue(':doc_id', $doc_id, $doc_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':doc_fullname', $doc_fullname);
        $stmt->bindValue(':chief_complaint', $chief_complaint);
        $stmt->bindValue(':history_present_illness', $history_present_illness);
        $stmt->bindValue(':diagnosis', $diagnosis);
        $stmt->bindValue(':blood_pressure', $blood_pressure);
        $stmt->bindValue(':respiratory_rate', $respiratory_rate);
        $stmt->bindValue(':weight', $weight);
        $stmt->bindValue(':heart_rate', $heart_rate);
        $stmt->bindValue(':temperature', $temperature);
        $stmt->bindValue(':checkup_id', $checkup_id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /* ================= GET ALL CHECKUPS FOR A PATIENT ================= */
    public function getByPatient($patient_id) {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM {$this->table}
            WHERE patient_id = ?
            ORDER BY checkup_date DESC
        ");

        $stmt->execute([$patient_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================= GET CHECKUP BY ID ================= */
    public function get($checkup_id) {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM {$this->table}
            WHERE checkup_id = ?
        ");

        $stmt->execute([$checkup_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* ================= SEARCH CHECKUPS BY DATE (WITH PAGINATION) ================= */
    public function searchByDate($patient_id, $checkup_date = null, $limit = 3, $offset = 0) {
        if ($checkup_date) {
            $sql = "
                SELECT *
                FROM {$this->table}
                WHERE patient_id = :pid
                  AND checkup_date = :cdate
                ORDER BY checkup_date DESC
                LIMIT :limit OFFSET :offset
            ";
        } else {
            $sql = "
                SELECT *
                FROM {$this->table}
                WHERE patient_id = :pid
                ORDER BY checkup_date DESC
                LIMIT :limit OFFSET :offset
            ";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':pid', $patient_id, PDO::PARAM_INT);

        if ($checkup_date) {
            $stmt->bindValue(':cdate', $checkup_date);
        }

        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================= COUNT CHECKUPS (FOR PAGINATION) ================= */
    public function countByPatient($patient_id, $checkup_date = null) {
        if ($checkup_date) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*)
                FROM {$this->table}
                WHERE patient_id = ? AND checkup_date = ?
            ");
            $stmt->execute([$patient_id, $checkup_date]);
        } else {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*)
                FROM {$this->table}
                WHERE patient_id = ?
            ");
            $stmt->execute([$patient_id]);
        }

        return (int)$stmt->fetchColumn();
    }
}
?>
