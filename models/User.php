<?php
require_once __DIR__ . '/BaseModel.php';

class User extends BaseModel {
    protected static $table = 'users';
    
    // Znajdź użytkownika po nazwie lub emailu
    public static function findByUsername($username) {
        $instance = new static();
        $stmt = $instance->pdo->prepare(
            "SELECT * FROM " . static::$table . " WHERE username = ? OR email = ?"
        );
        $stmt->execute([$username, $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? new static($result) : null;
    }
    
    // Sprawdź czy użytkownik istnieje
    public static function exists($username, $email) {
        $instance = new static();
        $stmt = $instance->pdo->prepare(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE username = ? OR email = ?"
        );
        $stmt->execute([$username, $email]);
        return $stmt->fetchColumn() > 0;
    }
    
    // Ustaw hasło (automatyczne hashowanie)
    public function setPassword($password) {
        $this->data['password'] = password_hash($password, PASSWORD_DEFAULT);
    }
    
    // Weryfikuj hasło
    public function verifyPassword($password) {
        return password_verify($password, $this->data['password']);
    }
}