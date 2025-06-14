<?php
require_once __DIR__ . '/../config/database.php';

abstract class BaseModel {
    protected static $table = '';
    protected static $primaryKey = 'id';
    protected $data = [];
    protected $pdo;
    
    public function __construct($data = []) {
        $this->pdo = getDBConnection();
        $this->data = $data;
    }
    
    // Magiczne metody do dostępu do właściwości
    public function __get($name) {
        return $this->data[$name] ?? null;
    }
    
    public function __set($name, $value) {
        $this->data[$name] = $value;
    }
    
    // Znajdź rekord po ID
    public static function find($id) {
        $instance = new static();
        $stmt = $instance->pdo->prepare(
            "SELECT * FROM " . static::$table . " WHERE " . static::$primaryKey . " = ?"
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? new static($result) : null;
    }
    
    // Znajdź wszystkie rekordy
    public static function all() {
        $instance = new static();
        $stmt = $instance->pdo->query("SELECT * FROM " . static::$table);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $models = [];
        foreach ($results as $result) {
            $models[] = new static($result);
        }
        return $models;
    }
    
    // Znajdź rekordy według warunku
    public static function where($column, $operator, $value) {
        $instance = new static();
        $stmt = $instance->pdo->prepare(
            "SELECT * FROM " . static::$table . " WHERE $column $operator ?"
        );
        $stmt->execute([$value]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $models = [];
        foreach ($results as $result) {
            $models[] = new static($result);
        }
        return $models;
    }
    
    // Zapisz rekord (insert lub update)
    public function save() {
        if (isset($this->data[static::$primaryKey])) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }
    
    // Wstaw nowy rekord
    protected function insert() {
        $columns = array_keys($this->data);
        $placeholders = array_map(function($col) { return ":$col"; }, $columns);
        
        $sql = "INSERT INTO " . static::$table . " (" . implode(', ', $columns) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($this->data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        if ($stmt->execute()) {
            $this->data[static::$primaryKey] = $this->pdo->lastInsertId();
            return true;
        }
        return false;
    }
    
    // Zaktualizuj istniejący rekord
    protected function update() {
        $columns = [];
        foreach ($this->data as $key => $value) {
            if ($key !== static::$primaryKey) {
                $columns[] = "$key = :$key";
            }
        }
        
        $sql = "UPDATE " . static::$table . " SET " . implode(', ', $columns) . 
               " WHERE " . static::$primaryKey . " = :" . static::$primaryKey;
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($this->data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        return $stmt->execute();
    }
    
    // Usuń rekord
    public function delete() {
        if (!isset($this->data[static::$primaryKey])) {
            return false;
        }
        
        $stmt = $this->pdo->prepare(
            "DELETE FROM " . static::$table . " WHERE " . static::$primaryKey . " = ?"
        );
        return $stmt->execute([$this->data[static::$primaryKey]]);
    }
    
    // Konwertuj do tablicy
    public function toArray() {
        return $this->data;
    }
    
    // Rozpocznij transakcję
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    // Zatwierdź transakcję
    public function commit() {
        return $this->pdo->commit();
    }
    
    // Cofnij transakcję
    public function rollback() {
        return $this->pdo->rollBack();
    }
    
    // Wykonaj surowe zapytanie SQL
    public static function raw($sql, $params = []) {
        $instance = new static();
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    // Policz rekordy
    public static function count($conditions = []) {
        $instance = new static();
        $sql = "SELECT COUNT(*) FROM " . static::$table;
        
        if (!empty($conditions)) {
            $whereClause = [];
            foreach ($conditions as $key => $value) {
                $whereClause[] = "$key = :$key";
            }
            $sql .= " WHERE " . implode(' AND ', $whereClause);
        }
        
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute($conditions);
        return $stmt->fetchColumn();
    }
}