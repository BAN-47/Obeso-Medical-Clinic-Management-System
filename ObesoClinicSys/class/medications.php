<?php
class Medications {
    private $conn;
    private $table = "medications";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAllMedications() {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} ORDER BY generic_name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMedicationById($medication_id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE medication_id = :id");
        $stmt->execute([':id' => $medication_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function addMedication($generic_name, $brand_name, $category, $preparation, $volume_bottle, $unit_price) {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} 
                (generic_name, brand_name, category, preparation, `volume/bottle`, unit_price) 
             VALUES 
                (:generic_name, :brand_name, :category, :preparation, :volume_bottle, :unit_price)"
        );
        return $stmt->execute([
            ':generic_name'  => $generic_name,
            ':brand_name'    => $brand_name,
            ':category'      => $category,
            ':preparation'   => $preparation,
            ':volume_bottle' => $volume_bottle,
            ':unit_price'    => $unit_price
        ]);
    }

    public function updateMedication($medication_id, $generic_name, $brand_name, $category, $preparation, $volume_bottle, $unit_price) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET 
                generic_name   = :generic_name,
                brand_name     = :brand_name,
                category       = :category,
                preparation    = :preparation,
                `volume/bottle` = :volume_bottle,
                unit_price     = :unit_price
             WHERE medication_id = :id"
        );
        return $stmt->execute([
            ':generic_name'  => $generic_name,
            ':brand_name'    => $brand_name,
            ':category'      => $category,
            ':preparation'   => $preparation,
            ':volume_bottle' => $volume_bottle,
            ':unit_price'    => $unit_price,
            ':id'            => $medication_id
        ]);
    }

    public function deleteMedication($medication_id) {
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE medication_id = :id");
        return $stmt->execute([':id' => $medication_id]);
    }
}
?>