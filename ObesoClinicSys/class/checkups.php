<?php
class Checkup {
    private $conn;
    private $table="checkups";

    public function __construct($db){ $this->conn=$db; }

    public function add($data){
        try{
            $stmt=$this->conn->prepare("
                INSERT INTO {$this->table}
                (patient_id,doc_id,doc_fullname,checkup_date,
                chief_complaint,history_present_illness,diagnosis,
                blood_pressure,respiratory_rate,weight,heart_rate,temperature)
                VALUES (:pid,:did,:df,:cd,:cc,:hpi,:dx,:bp,:rr,:wt,:hr,:temp)
            ");
            return $stmt->execute($data);
        } catch(PDOException $e){ return false; }
    }

    public function viewAll(){

        $sql = "
            SELECT *
            FROM {$this->table}
            WHERE is_deleted = 0
            ORDER BY checkup_date DESC
        ";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /* ================= FILTER CHECKUPS ================= */
 public function filter($doctor_id = null, $date = null) {

        $sql = "
            SELECT c.*, p.full_name AS patient_name
            FROM checkups c
            JOIN patients p ON p.patient_id = c.patient_id
            WHERE c.is_deleted = 0
        ";

        $params = [];

        if (!empty($doctor_id)) {
            $sql .= " AND c.doc_id = :doc_id";
            $params['doc_id'] = $doctor_id;
        }

        if (!empty($date)) {
            $sql .= " AND c.checkup_date = :checkup_date";
            $params['checkup_date'] = $date;
        }

        $sql .= " ORDER BY c.checkup_date DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

/* ================= DELETE CHECKUP ================= */
    public function delete($id) {

        $stmt = $this->conn->prepare("
            UPDATE checkups
            SET is_deleted = 1
            WHERE checkup_id = :id
        ");

        return $stmt->execute([
            'id' => $id
        ]);
    }

}
