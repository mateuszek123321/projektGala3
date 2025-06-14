<?php
require_once __DIR__ . '/BaseModel.php';

class DiseaseDictionary extends BaseModel {
    protected static $table = 'disease_dictionary';
    protected static $primaryKey = 'disease_code';
    
    /**
     * Znajdź chorobę po kodzie
     */
    public static function findByCode($code) {
        return static::find($code);
    }
    
    /**
     * Znajdź chorobę po polskiej nazwie
     */
    public static function findByPolishName($name) {
        $instance = new static();
        $stmt = $instance->pdo->prepare(
            "SELECT * FROM " . static::$table . " WHERE disease_name_pl = ?"
        );
        $stmt->execute([$name]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? new static($result) : null;
    }
    
    /**
     * Pobierz wszystkie choroby z kategorii
     */
    public static function findByCategory($category) {
        return static::where('category', '=', $category);
    }
    
    /**
     * Mapowanie polskich nazw na kody ICD-10
     */
    public static function getPolishToCodeMapping() {
        $instance = new static();
        $stmt = $instance->pdo->query(
            "SELECT disease_code, disease_name_pl FROM " . static::$table
        );
        $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Odwróć mapowanie: nazwa_pl => kod
        return array_flip($results);
    }
    
    /**
     * Pobierz wszystkie kategorie
     */
    public static function getCategories() {
        $instance = new static();
        $stmt = $instance->pdo->query(
            "SELECT DISTINCT category FROM " . static::$table . " ORDER BY category"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Sprawdź czy choroba istnieje w słowniku
     */
    public static function exists($code) {
        return static::count(['disease_code' => $code]) > 0;
    }
    
    /**
     * Dodaj nową chorobę do słownika
     */
    public static function addDisease($code, $namePl, $category = null) {
        $disease = new static([
            'disease_code' => $code,
            'disease_name_pl' => $namePl,
            'category' => $category
        ]);
        return $disease->save();
    }
}