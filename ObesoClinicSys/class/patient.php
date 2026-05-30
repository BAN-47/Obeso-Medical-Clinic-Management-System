<?php
class Patient {
    private $conn;
    private $table = "patients";

    public function __construct($db) {
        $this->conn = $db;
    }

    /* ADD PATIENT */
    public function add($data) {
        $sql = "INSERT INTO {$this->table}
                (full_name, address, birthday, age, sex,
                 civil_status, religion, occupation,
                 contact_person, contact_person_age, contact_number)
                VALUES
                (:full_name, :address, :birthday, :age, :sex,
                 :civil_status, :religion, :occupation,
                 :contact_person, :contact_person_age, :contact_number)";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($data);
    }

    /* VIEW ALL (ACTIVE ONLY) */
    public function viewAll() {
        $sql = "SELECT * FROM {$this->table}
                WHERE is_deleted = 0
                ORDER BY patient_id DESC";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* GET BY ID */
    public function getById($id) {
        $sql = "SELECT * FROM {$this->table}
                WHERE patient_id = :id
                AND is_deleted = 0";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* UPDATE */
    public function update($id, $data) {
        $sql = "UPDATE {$this->table}
                SET full_name = :full_name,
                    address = :address,
                    birthday = :birthday,
                    age = :age,
                    sex = :sex,
                    civil_status = :civil_status,
                    religion = :religion,
                    occupation = :occupation,
                    contact_person = :contact_person,
                    contact_person_age = :contact_person_age,
                    contact_number = :contact_number
                WHERE patient_id = :id";

        $data['id'] = $id;

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($data);
    }

    /* SOFT DELETE */
    public function delete($id) {
        $sql = "UPDATE {$this->table}
                SET is_deleted = 1
                WHERE patient_id = :id";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
}
?>