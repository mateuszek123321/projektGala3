<?php
require_once __DIR__ . '/BaseModel.php';

class DataLog extends BaseModel {
    protected static $table = 'data_logs';
    
    /**
     * Zapisz log operacji importu/eksportu
     * @param int $userId ID użytkownika
     * @param string $action 'import' lub 'export'
     * @param string $fileType 'xml' lub 'json'
     * @param string $fileName Nazwa pliku
     * @param int $recordsCount Liczba przetworzonych rekordów
     * @param string $status 'success' lub 'failed'
     * @param string|null $errorMessage Komunikat błędu (opcjonalny)
     */
    public static function log($userId, $action, $fileType, $fileName, $recordsCount, $status, $errorMessage = null) {
        $log = new static([
            'user_id' => $userId,
            'action' => $action,
            'file_type' => $fileType,
            'file_name' => $fileName,
            'records_count' => $recordsCount,
            'status' => $status,
            'error_message' => $errorMessage
        ]);
        return $log->save();
    }
    
    /**
     * Pobierz logi dla użytkownika
     * @param int $userId ID użytkownika
     * @param int $limit Limit rekordów
     */
    public static function getUserLogs($userId, $limit = 50) {
        $instance = new static();
        $stmt = $instance->pdo->prepare(
            "SELECT * FROM " . static::$table . 
            " WHERE user_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $logs = [];
        foreach ($results as $result) {
            $logs[] = new static($result);
        }
        return $logs;
    }
    
    /**
     * Pobierz statystyki importu/eksportu
     */
    public static function getStatistics() {
        $instance = new static();
        $stmt = $instance->pdo->query("
            SELECT 
                action,
                file_type,
                status,
                COUNT(*) as count,
                SUM(records_count) as total_records
            FROM " . static::$table . "
            GROUP BY action, file_type, status
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}