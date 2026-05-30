<?php
class Doctor {
    private $conn;
    private $table_doctor = "doctors";

    public function __construct($db) {
        $this->conn = $db;
    }

    /* ADD DOCTOR */
    public function addDoctor($fullname, $contact, $email) {
        $sql = "INSERT INTO {$this->table_doctor}
                (doc_fullname, doc_contact_num, doc_email, doc_created_at)
                VALUES (:fullname, :contact, :email, NOW())";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ':fullname' => $fullname,
            ':contact' => $contact,
            ':email' => $email
        ]);
    }

    /* UPDATE DOCTOR */
    public function updateDoctor($doc_id, $fullname, $contact, $email) {
        $sql = "UPDATE {$this->table_doctor}
                SET doc_fullname = :fullname,
                    doc_contact_num = :contact,
                    doc_email = :email,
                    doc_updated_at = NOW()
                WHERE doc_id = :id";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ':fullname' => $fullname,
            ':contact' => $contact,
            ':email' => $email,
            ':id' => $doc_id
        ]);
    }

    /* DELETE DOCTOR */
    public function deleteDoctor($doc_id) {
       $sql = "UPDATE {$this->table_doctor}
                 SET is_deleted = 1
                 WHERE doc_id = :id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $doc_id]);
    }

    /* GET ALL DOCTORS */
    public function getAllDoctors() {
   $sql = "SELECT *
        FROM {$this->table_doctor}
        WHERE is_deleted = 0
        ORDER BY doc_fullname ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* SEARCH DOCTOR */
    public function searchDoctors($keyword) {
   $sql = "SELECT *
        FROM {$this->table_doctor}
        WHERE doc_fullname LIKE :keyword
        AND is_deleted = 0
        ORDER BY doc_fullname ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':keyword' => "%$keyword%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* FIND BY ID */
    public function getDoctorById($id) {
      $sql = "SELECT *
        FROM {$this->table_doctor}
        WHERE doc_id = :id
        AND is_deleted = 0";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
