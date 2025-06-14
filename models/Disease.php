<?php
require_once __DIR__ . '/BaseModel.php';

class Disease extends BaseModel {
    protected static $table = 'diseases';
    protected static $primaryKey = 'id';

    // Znajdź choroby według roku
    public static function findByYear($year) {
        return static::where('year', '=', $year);
    }
    
    // Znajdź choroby według kodu
    public static function findByCode($code) {
        return static::where('disease_code', '=', $code);
    }
    
    // Pobierz unikalne kody chorób
    public static function getUniqueCodes() {
        $instance = new static();
        $stmt = $instance->pdo->query(
            "SELECT DISTINCT disease_code, disease_name FROM " . static::$table . " ORDER BY disease_code"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Pobierz dane dla konkretnej choroby i zakresu lat
    public static function getByCodeAndYearRange($code, $startYear, $endYear) {
        $instance = new static();
        $stmt = $instance->pdo->prepare("
            SELECT * FROM " . static::$table . " 
            WHERE disease_code = ? AND year BETWEEN ? AND ? 
            ORDER BY year
        ");
        $stmt->execute([$code, $startYear, $endYear]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $models = [];
        foreach ($results as $result) {
            $models[] = new static($result);
        }
        return $models;
    }
    
    // Pobierz statystyki dla roku
    public static function getYearStatistics($year) {
        $instance = new static();
        $stmt = $instance->pdo->prepare("
            SELECT 
                COUNT(DISTINCT disease_code) as unique_diseases,
                SUM(outpatient_count) as total_outpatient,
                SUM(hospital_count) as total_hospital,
                SUM(emergency_count) as total_emergency,
                SUM(admission_count) as total_admission
            FROM " . static::$table . " 
            WHERE year = ?
        ");
        $stmt->execute([$year]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}