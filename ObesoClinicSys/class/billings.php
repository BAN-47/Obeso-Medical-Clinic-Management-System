<?php
class Billing {
    private $conn;
    private $table = "billing";

    public function __construct($db){
        $this->conn = $db;
    }

    /* ================= VIEW ALL BILLINGS ================= */
    public function viewAll() {
        $sql = "
            SELECT b.*,
                   p.full_name AS patient_name,
                   d.doc_fullname AS doctor_name,
                   c.checkup_date
            FROM billing b
            JOIN patients p ON p.patient_id = b.patient_id
            JOIN doctors d ON d.doc_id = b.doc_id
            LEFT JOIN checkups c ON c.checkup_id = b.checkup_id
            WHERE b.is_deleted = 0
            ORDER BY b.billed_at DESC
        ";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================= UPDATE BILLING ================= */
    public function update($bill_id, $payment_status, $payment_method) {
        $sql = "UPDATE {$this->table}
                SET payment_status = :status,
                    payment_method = :method
                WHERE bill_id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ":status" => $payment_status,
            ":method" => $payment_method,
            ":id" => $bill_id
        ]);
    }

    /* ================= SOFT DELETE BILLING ================= */
    public function delete($bill_id) {

        $sql = "UPDATE {$this->table}
                SET is_deleted = 1
                WHERE bill_id = :id";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':id' => $bill_id
        ]);
    }
}
?>