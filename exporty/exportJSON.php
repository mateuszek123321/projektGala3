<?php
session_start();
// Dołączenie plików
require_once '../config/database.php';
require_once '../models/AlcoholConsumption.php';
require_once '../models/Disease.php';
require_once '../models/DataLog.php';

// Sprawdzenie logowania
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$messageType = '';

// Obsługa eksportu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    
    $exportType = $_POST['export_type'] ?? '';
    $yearFrom = $_POST['year_from'] ?? date('Y') - 10;
    $yearTo = $_POST['year_to'] ?? date('Y');
    
    if (!in_array($exportType, ['alcohol', 'diseases', 'both'])) {
        $message = 'Nieprawidłowy typ eksportu.';
        $messageType = 'error';
    } else {
        try {
            $data = [];
            $filename = '';
            
            // Pobieranie danych w zależności od typu
            if ($exportType === 'alcohol' || $exportType === 'both') {
                $alcoholData = AlcoholConsumption::getByYearRange($yearFrom, $yearTo);
                
                // Format standardowy z angielskimi nazwami
                $alcoholArray = [];
                foreach ($alcoholData as $record) {
                    $alcoholArray[] = [
                        'id' => (int)$record->id,
                        'year' => (int)$record->year,
                        'spirits_100_alcohol' => (float)$record->spirits_100_alcohol,
                        'wine_mead' => (float)$record->wine_mead,
                        'wine_mead_100_alcohol' => (float)$record->wine_mead_100_alcohol,
                        'beer' => (float)$record->beer,
                        'beer_100_alcohol' => (float)$record->beer_100_alcohol
                    ];
                }
                $data['alcohol_consumption'] = $alcoholArray;
            }
            
            if ($exportType === 'diseases' || $exportType === 'both') {
                // Pobierz wszystkie choroby w zakresie lat
                $diseaseData = Disease::raw(
                    "SELECT * FROM diseases WHERE year BETWEEN ? AND ? ORDER BY year, disease_code",
                    [$yearFrom, $yearTo]
                )->fetchAll(PDO::FETCH_ASSOC);
                
                // Format standardowy - każdy rekord osobno
                $diseaseArray = [];
                foreach ($diseaseData as $record) {
                    $diseaseArray[] = [
                        'id' => (int)$record['id'],
                        'disease_code' => $record['disease_code'],
                        'disease_name' => $record['disease_name'],
                        'province' => $record['province'],
                        'year' => (int)$record['year'],
                        'outpatient_count' => (int)$record['outpatient_count']
                    ];
                }
                $data['diseases'] = $diseaseArray;
            }
            
            // Generowanie nazwy pliku
            if ($exportType === 'both') {
                $filename = 'complete_data_' . $yearFrom . '_' . $yearTo . '.json';
            } elseif ($exportType === 'alcohol') {
                $filename = 'alcohol_consumption_' . $yearFrom . '_' . $yearTo . '.json';
            } else {
                $filename = 'diseases_' . $yearFrom . '_' . $yearTo . '.json';
            }
            
            // Konwersja do JSON
            $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if ($jsonContent === false) {
                throw new Exception('Błąd podczas generowania JSON: ' . json_last_error_msg());
            }
            
            // Logowanie operacji
            $recordCount = 0;
            if (isset($data['alcohol_consumption'])) $recordCount += count($data['alcohol_consumption']);
            if (isset($data['diseases'])) $recordCount += count($data['diseases']);
            
            DataLog::log(
                $_SESSION['user_id'],
                'export',
                'json',
                $filename,
                $recordCount,
                'success',
                null
            );
            
            // Wyczyść bufor wyjściowy przed wysłaniem nagłówków
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Wysłanie pliku do pobrania
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($jsonContent));
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
            
            echo $jsonContent;
            exit;
            
        } catch (Exception $e) {
            $message = 'Błąd podczas eksportu: ' . $e->getMessage();
            $messageType = 'error';
            
            // Logowanie błędu
            DataLog::log(
                $_SESSION['user_id'],
                'export',
                'json',
                'export_failed.json',
                0,
                'failed',
                $e->getMessage()
            );
        }
    }
}

// Pobierz statystyki do wyświetlenia
try {
    $alcoholStats = AlcoholConsumption::getStatistics();
    $diseaseStats = Disease::count();
} catch (Exception $e) {
    $alcoholStats = ['total_records' => 0, 'min_year' => 'brak', 'max_year' => 'brak'];
    $diseaseStats = 0;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eksport JSON - System Integracji Danych</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .export-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .stats-box {
            background-color: #e9ecef;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .radio-group {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .radio-group label {
            display: flex;
            align-items: center;
            font-weight: normal;
        }
        
        .radio-group input[type="radio"] {
            margin-right: 0.5rem;
        }
        
        .code-block {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Eksport danych do JSON</h1>
        <a href="../index.php">← Powrót do panelu</a>
    </div>
    
    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statystyki -->
        <div class="card">
            <h2>Dostępne dane</h2>
            <div class="grid">
                <div class="stat-box">
                    <h3>Dane o alkoholu</h3>
                    <p class="number"><?php echo $alcoholStats['total_records'] ?? 0; ?></p>
                    <p>rekordów (lata <?php echo $alcoholStats['min_year'] ?? 'brak'; ?> - <?php echo $alcoholStats['max_year'] ?? 'brak'; ?>)</p>
                </div>
                <div class="stat-box">
                    <h3>Dane o chorobach</h3>
                    <p class="number"><?php echo $diseaseStats; ?></p>
                    <p>rekordów</p>
                </div>
            </div>
        </div>
        
        <!-- Formularz eksportu -->
        <div class="card">
            <h2>Opcje eksportu</h2>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Typ danych do eksportu:</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="export_type" value="alcohol" checked>
                            Tylko spożycie alkoholu
                        </label>
                        <label>
                            <input type="radio" name="export_type" value="diseases">
                            Tylko choroby
                        </label>
                        <label>
                            <input type="radio" name="export_type" value="both">
                            Wszystkie dane
                        </label>
                    </div>
                </div>
                
                <div class="export-options">
                    <div class="form-group">
                        <label for="year_from">Rok od:</label>
                        <input type="number" name="year_from" id="year_from" 
                               value="<?php echo date('Y') - 10; ?>" 
                               min="1990" max="<?php echo date('Y'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="year_to">Rok do:</label>
                        <input type="number" name="year_to" id="year_to" 
                               value="<?php echo date('Y'); ?>" 
                               min="1990" max="<?php echo date('Y'); ?>">
                    </div>
                </div>
                
                <button type="submit" name="export" class="btn">
                    Eksportuj do JSON
                </button>
                <a href="../index.php" class="btn btn-secondary" style="text-decoration: none;">
                    Anuluj
                </a>
            </form>
        </div>
    </div>
</body>
</html>