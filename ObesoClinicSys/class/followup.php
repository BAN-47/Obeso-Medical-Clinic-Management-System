<?php
class Followup {
    private $conn;
    private $table = "follow_ups";

    public function __construct($db) {
        $this->conn = $db;
    }

/* ================= VIEW ALL FOLLOWUPS ================= */
public function viewAll() {
    $sql = "
        SELECT 
            f.*,
            p.full_name AS patient_name,
            d.doc_fullname AS doctor_name,   -- <-- FIXED
            c.checkup_date
        FROM follow_ups f
        JOIN patients p ON p.patient_id = f.patient_id
        JOIN doctors d ON d.doc_id = f.doc_id
        LEFT JOIN checkups c ON c.checkup_id = f.checkup_id
        WHERE f.is_deleted = 0
        ORDER BY f.followup_date DESC
    ";
    return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}


    /* ================= UPDATE FOLLOWUP ================= */
    public function update($id, $date, $notes, $status) {
        $stmt = $this->conn->prepare("
            UPDATE {$this->table}
            SET followup_date = :date,
                notes = :notes,
                status = :status
            WHERE followup_id = :id
        ");
        return $stmt->execute([
            'date' => $date,
            'notes' => $notes,
            'status' => $status,
            'id' => $id
        ]);
    }

    /* ================= DELETE FOLLOWUP ================= */
public function delete($id) {
    $stmt = $this->conn->prepare("
        UPDATE {$this->table}
        SET is_deleted = 1
        WHERE followup_id = :id
    ");

    return $stmt->execute([
        'id' => $id
    ]);
}
}
?>