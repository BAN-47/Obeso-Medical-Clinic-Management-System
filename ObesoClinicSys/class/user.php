<?php
class User {
    private $conn;
    private $table = "users";

    public function __construct($db) {
        $this->conn = $db;
    }

    // CREATE USER (Staff or Doctor)
public function create($username, $password, $staff_id = null, $doc_id = null) {
    try {
        $sql = "INSERT INTO {$this->table}
                (user_name, user_password, staff_id, doc_id)
                VALUES (:username, :password, :staff_id, :doc_id)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ":username" => $username,
            ":password" => password_hash($password, PASSWORD_DEFAULT),
            ":staff_id" => $staff_id,
            ":doc_id"   => $doc_id
        ]);
        return true;

    } catch (PDOException $e) {

        // Duplicate username
        if ($e->getCode() == 23000) {
            return "DUPLICATE_USERNAME";
        }

        throw $e; // other DB errors
    }
}

    //check role selection
    public function checkRoleSelection($staff_id, $doc_id) {
        if (!empty($staff_id) && !empty($doc_id)) {
            return "BOTH_SELECTED";  // Invalid: cannot select both
        }
        if (empty($staff_id) && empty($doc_id)) {
            return "NONE_SELECTED";  // Invalid: must select at least one
        }
        return true; // Valid
    }

    // VIEW USERS
    public function all() {
        $sql = "SELECT u.*, 
                       s.staff_first_name, s.staff_last_name,
                       d.doc_fullname
                FROM users u
                LEFT JOIN staff s ON u.staff_id = s.staff_id
                LEFT JOIN doctors d ON u.doc_id = d.doc_id
                WHERE u.is_deleted = 0
                ORDER BY u.user_created_at ASC";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    // RESET PASSWORD
    public function resetPassword($user_id, $password) {
        $sql = "UPDATE {$this->table}
                SET user_password = :password
                WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ":password" => $password,
            ":user_id"  => $user_id
        ]);
    }

    // ENABLE / DISABLE USER
    public function setStatus($user_id, $status) {
        $sql = "UPDATE {$this->table}
                SET user_is_active = :status
                WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ":status" => $status,
            ":user_id" => $user_id
        ]);
    }

    // ROLE LABEL
    public function role($u) {
        if (!empty($u['doc_id'])) return "Doctor";
        if (!empty($u['staff_id'])) return "Staff";
        return "Admin";
    }
}
