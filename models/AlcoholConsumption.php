<?php
require_once __DIR__ . '/BaseModel.php';

class AlcoholConsumption extends BaseModel {
    protected static $table = 'alcohol_consumption';
    
    // Znajdź dane dla konkretnego roku
    public static function findByYear($year) {
        $results = static::where('year', '=', $year);
        return count($results) > 0 ? $results[0] : null;
    }
    
    // Pobierz dane z zakresu lat
    public static function getByYearRange($startYear, $endYear) {
        $instance = new static();
        $stmt = $instance->pdo->prepare(
            "SELECT * FROM " . static::$table . " WHERE year BETWEEN ? AND ? ORDER BY year"
        );
        $stmt->execute([$startYear, $endYear]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $models = [];
        foreach ($results as $result) {
            $models[] = new static($result);
        }
        return $models;
    }
    
    // Oblicz całkowite spożycie alkoholu 100%
    public function getTotalAlcohol100() {
        return ($this->spirits_100_alcohol ?? 0) + 
               ($this->wine_mead_100_alcohol ?? 0) + 
               ($this->beer_100_alcohol ?? 0);
    }
    
    // Pobierz statystyki
    public static function getStatistics() {
        $instance = new static();
        $stmt = $instance->pdo->query("
            SELECT 
                MIN(year) as min_year,
                MAX(year) as max_year,
                AVG(spirits_100_alcohol) as avg_spirits,
                AVG(wine_mead_100_alcohol) as avg_wine,
                AVG(beer_100_alcohol) as avg_beer,
                COUNT(*) as total_records
            FROM " . static::$table
        );
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}