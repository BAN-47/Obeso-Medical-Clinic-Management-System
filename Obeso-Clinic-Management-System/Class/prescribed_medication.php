<?php
class PrescribedMedication {
    private $conn;
    private $table = "prescribed_medications";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function add($checkup_id, $medication_id, $pres_generic_name = null, $pres_brand_name = null, $dose = null, $amount = null, $frequency = null, $duration = null) {
        $sql = "INSERT INTO {$this->table} 
                (checkup_id, medication_id, pres_generic_name, pres_brand_name, dose, amount, frequency, duration)
                VALUES (:checkup_id, :medication_id, :pres_generic_name, :pres_brand_name, :dose, :amount, :frequency, :duration)";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ":checkup_id"        => $checkup_id,
            ":medication_id"     => $medication_id,
            ":pres_generic_name" => $pres_generic_name,
            ":pres_brand_name"   => $pres_brand_name,
            ":dose"              => $dose,
            ":amount"            => $amount,
            ":frequency"         => $frequency,
            ":duration"          => $duration
        ]);
    }

    public function getLatestByPatient($checkup_id) {
        $stmt = $this->conn->prepare(
            "SELECT pm.*, pm.pres_generic_name, pm.pres_brand_name
             FROM prescribed_medications pm
             WHERE pm.checkup_id = ?"
        );
        $stmt->execute([$checkup_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update($prescription_id, $checkup_id, $medication_id, $pres_generic_name = null, $pres_brand_name = null, $dose = null, $amount = null, $frequency = null, $duration = null) {
        $sql = "UPDATE {$this->table}
                SET checkup_id          = :checkup_id,
                    medication_id       = :medication_id,
                    pres_generic_name   = :pres_generic_name,
                    pres_brand_name     = :pres_brand_name,
                    dose                = :dose,
                    amount              = :amount,
                    frequency           = :frequency,
                    duration            = :duration
                WHERE prescription_id = :prescription_id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ":checkup_id"        => $checkup_id,
            ":medication_id"     => $medication_id,
            ":pres_generic_name" => $pres_generic_name,
            ":pres_brand_name"   => $pres_brand_name,
            ":dose"              => $dose,
            ":amount"            => $amount,
            ":frequency"         => $frequency,
            ":duration"          => $duration,
            ":prescription_id"   => $prescription_id
        ]);
    }
}
?>