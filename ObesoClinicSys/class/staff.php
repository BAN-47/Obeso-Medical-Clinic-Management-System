<?php
class Staff{
    private $conn;
    private $table = "staff";

public function __construct($db){
    $this->conn = $db;
}
public function add($STAFF_FNAME, $STAFF_LNAME, $STAFF_MID_INIT, $STAFF_CONTACT, $STAFF_EMAIL){
    try {
        $sql = "INSERT INTO {$this->table}
            (staff_first_name, staff_last_name, staff_middle_init, staff_contact_num, staff_email, staff_created_at)
            VALUES (:STAFF_FNAME, :STAFF_LNAME, :STAFF_MID_INIT, :STAFF_CONTACT, :STAFF_EMAIL, NOW())";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ":STAFF_FNAME" => $STAFF_FNAME,
            ":STAFF_LNAME" => $STAFF_LNAME,
            ":STAFF_MID_INIT" => $STAFF_MID_INIT,
            ":STAFF_CONTACT" => $STAFF_CONTACT,
            ":STAFF_EMAIL" => $STAFF_EMAIL
        ]);

        return true;

    } catch (PDOException $e) {

        // MySQL duplicate key error
        if ($e->errorInfo[1] == 1062) {
            return "DUPLICATE_EMAIL";
        }

        return false;
    }
}
public function searchByName($keyword){
    $sql = "SELECT * FROM {$this->table}
            WHERE staff_first_name LIKE :keyword OR staff_last_name LIKE :keyword
            ORDER BY staff_last_name ASC";
    $stmt = $this->conn->prepare($sql);
    $stmt->execute([":keyword" => "%$keyword%"]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
public function all(){
     $sql = "SELECT * FROM {$this->table}
                WHERE is_deleted = 0
                ORDER BY staff_id DESC";
    $stmt = $this->conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
   public function delete($id) {
        $sql = "UPDATE {$this->table}
                SET is_deleted = 1
                WHERE staff_id = :id";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $id]);
}
public function update($STAFF_ID, $STAFF_FNAME, $STAFF_LNAME, $STAFF_MID_INIT, $STAFF_CONTACT, $STAFF_EMAIL){
    $sql = "UPDATE {$this->table} 
                    SET staff_first_name = :STAFF_FNAME,
                    staff_last_name = :STAFF_LNAME,
                    staff_middle_init = :STAFF_MID_INIT,
                    staff_contact_num = :STAFF_CONTACT,
                    staff_email = :STAFF_EMAIL,
                    staff_updated_at = NOW()
                    WHERE staff_id = :STAFF_ID";
    $stmt = $this->conn->prepare($sql);
    return $stmt->execute([
            ":STAFF_FNAME" => $STAFF_FNAME,
            ":STAFF_LNAME" => $STAFF_LNAME,
            ":STAFF_MID_INIT" => $STAFF_MID_INIT,
            ":STAFF_CONTACT" => $STAFF_CONTACT,
            ":STAFF_EMAIL" => $STAFF_EMAIL,
            ":STAFF_ID" => $STAFF_ID]);
}
}
?>