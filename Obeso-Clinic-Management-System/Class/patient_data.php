<?php
class Patient {
    private $conn;
    private $table = "patients";

    // Constructor receives the PDO database connection
    public function __construct($db) {
        $this->conn = $db;
    }

    // 🔍 Check if patient already exists (by full name, birthday, and contact number)
    public function exists($full_name, $birthday, $contact_number) {
        $sql = "SELECT patient_id FROM {$this->table} 
                WHERE full_name = :full_name AND birthday = :birthday AND contact_number = :contact_number
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ":full_name" => $full_name,
            ":birthday" => $birthday,
            ":contact_number" => $contact_number
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC); // Returns patient row if exists, false if not
    }

    // ➕ Add new patient with duplication check
    public function add($full_name, $address, $birthday, $age, $sex, $civil_status = null, $religion = null, $occupation = null, $contact_person = null, $contact_person_age = null, $contact_number = null) {
        // Check for duplicate patient
        if ($this->exists($full_name, $birthday, $contact_number)) {
            throw new Exception("Duplicate patient detected: {$full_name} ({$birthday})");
        }

        $sql = "INSERT INTO {$this->table} 
                (full_name, address, birthday, age, sex, civil_status, religion, occupation, contact_person, contact_person_age, contact_number)
                VALUES (:full_name, :address, :birthday, :age, :sex, :civil_status, :religion, :occupation, :contact_person, :contact_person_age, :contact_number)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ":full_name" => $full_name,
            ":address" => $address,
            ":birthday" => $birthday,
            ":age" => $age,
            ":sex" => $sex,
            ":civil_status" => $civil_status,
            ":religion" => $religion,
            ":occupation" => $occupation,
            ":contact_person" => $contact_person,
            ":contact_person_age" => $contact_person_age,
            ":contact_number" => $contact_number
        ]);
        return $this->conn->lastInsertId(); // Return the newly inserted patient ID
    }

    // ✏️ Update patient details
    public function update($patient_id, $full_name, $address, $birthday, $age, $sex, $civil_status = null, $religion = null, $occupation = null, $contact_person = null, $contact_person_age = null, $contact_number = null) {
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
                WHERE patient_id = :patient_id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ":full_name" => $full_name,
            ":address" => $address,
            ":birthday" => $birthday,
            ":age" => $age,
            ":sex" => $sex,
            ":civil_status" => $civil_status,
            ":religion" => $religion,
            ":occupation" => $occupation,
            ":contact_person" => $contact_person,
            ":contact_person_age" => $contact_person_age,
            ":contact_number" => $contact_number,
            ":patient_id" => $patient_id
        ]);
    }

    // 🔍 Get patient by ID
    public function get($patient_id) {
        $sql = "SELECT * FROM {$this->table} WHERE patient_id = :patient_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([":patient_id" => $patient_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
