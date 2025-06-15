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
            $filename = '';
            $xmlContent = '';
            $recordCount = 0;
            
            // Eksport danych o alkoholu
            if ($exportType === 'alcohol') {
                $alcoholData = AlcoholConsumption::getByYearRange($yearFrom, $yearTo);
                $xmlContent = generateAlcoholXML($alcoholData);
                $filename = 'alcohol_consumption_' . $yearFrom . '_' . $yearTo . '.xml';
                $recordCount = count($alcoholData);
            }
            // Eksport danych o chorobach
            elseif ($exportType === 'diseases') {
                $diseaseData = getDiseaseDataByYearRange($yearFrom, $yearTo);
                $xmlContent = generateDiseaseXML($diseaseData);
                $filename = 'diseases_' . $yearFrom . '_' . $yearTo . '.xml';
                $recordCount = array_sum(array_map('count', $diseaseData));
            }
            // Eksport obu typów w jednym pliku XML
            else {
                $alcoholData = AlcoholConsumption::getByYearRange($yearFrom, $yearTo);
                $diseaseData = getDiseaseDataByYearRange($yearFrom, $yearTo);
                $xmlContent = generateCombinedXML($alcoholData, $diseaseData);
                $filename = 'complete_data_' . $yearFrom . '_' . $yearTo . '.xml';
                $recordCount = count($alcoholData) + array_sum(array_map('count', $diseaseData));
            }
            
            if ($xmlContent && !$message) {
                // Logowanie operacji
                DataLog::log(
                    $_SESSION['user_id'],
                    'export',
                    'xml',
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
                header('Content-Type: text/xml; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($xmlContent));
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
                
                echo $xmlContent;
                exit;
            }
            
        } catch (Exception $e) {
            $message = 'Błąd podczas eksportu: ' . $e->getMessage();
            $messageType = 'error';
            
            DataLog::log(
                $_SESSION['user_id'],
                'export',
                'xml',
                'export_failed.xml',
                0,
                'failed',
                $e->getMessage()
            );
        }
    }
}

/**
 * Generuje XML dla danych o alkoholu
 */
function generateAlcoholXML($data) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><alcohol_consumption></alcohol_consumption>');
    
    foreach ($data as $record) {
        $row = $xml->addChild('record');
        $row->addChild('year', $record->year);
        $row->addChild('spirits_100_alcohol', number_format($record->spirits_100_alcohol, 2, '.', ''));
        $row->addChild('wine_mead', number_format($record->wine_mead, 2, '.', ''));
        $row->addChild('wine_mead_100_alcohol', number_format($record->wine_mead_100_alcohol, 2, '.', ''));
        $row->addChild('beer', number_format($record->beer, 2, '.', ''));
        $row->addChild('beer_100_alcohol', number_format($record->beer_100_alcohol, 2, '.', ''));
    }
    
    // Formatowanie XML z wcięciami
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    
    return $dom->saveXML();
}

/**
 * Pobiera dane o chorobach pogrupowane według lat
 */
function getDiseaseDataByYearRange($yearFrom, $yearTo) {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT d.year, d.disease_code, dd.disease_name_pl, d.outpatient_count
        FROM diseases d
        JOIN disease_dictionary dd ON d.disease_code = dd.disease_code
        WHERE d.year BETWEEN ? AND ? 
        ORDER BY d.year, d.disease_code
    ");
    $stmt->execute([$yearFrom, $yearTo]);
    
    $dataByYear = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $year = $row['year'];
        if (!isset($dataByYear[$year])) {
            $dataByYear[$year] = [];
        }
        
        $dataByYear[$year][] = [
            'disease_code' => $row['disease_code'],
            'disease_name' => $row['disease_name_pl'],
            'outpatient_count' => (int)$row['outpatient_count']
        ];
    }
    
    return $dataByYear;
}

/**
 * Generuje XML dla danych o chorobach
 */
function generateDiseaseXML($dataByYear) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><diseases></diseases>');
    
    foreach ($dataByYear as $year => $diseases) {
        $yearNode = $xml->addChild('year');
        $yearNode->addAttribute('value', $year);
        
        foreach ($diseases as $disease) {
            $diseaseNode = $yearNode->addChild('disease');
            $diseaseNode->addChild('code', htmlspecialchars($disease['disease_code']));
            $diseaseNode->addChild('name', htmlspecialchars($disease['disease_name']));
            $diseaseNode->addChild('outpatient_count', $disease['outpatient_count']);
        }
    }
    
    // Formatowanie XML z wcięciami
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    
    return $dom->saveXML();
}

/**
 * Generuje XML dla kombinacji danych o alkoholu i chorobach
 */
function generateCombinedXML($alcoholData, $diseaseDataByYear) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><data_export></data_export>');
    
    // Sekcja danych o alkoholu
    $alcoholSection = $xml->addChild('alcohol_consumption');
    foreach ($alcoholData as $record) {
        $row = $alcoholSection->addChild('record');
        $row->addChild('year', $record->year);
        $row->addChild('spirits_100_alcohol', number_format($record->spirits_100_alcohol, 2, '.', ''));
        $row->addChild('wine_mead', number_format($record->wine_mead, 2, '.', ''));
        $row->addChild('wine_mead_100_alcohol', number_format($record->wine_mead_100_alcohol, 2, '.', ''));
        $row->addChild('beer', number_format($record->beer, 2, '.', ''));
        $row->addChild('beer_100_alcohol', number_format($record->beer_100_alcohol, 2, '.', ''));
    }
    
    // Sekcja danych o chorobach
    $diseaseSection = $xml->addChild('diseases');
    foreach ($diseaseDataByYear as $year => $diseases) {
        $yearNode = $diseaseSection->addChild('year');
        $yearNode->addAttribute('value', $year);
        
        foreach ($diseases as $disease) {
            $diseaseNode = $yearNode->addChild('disease');
            $diseaseNode->addChild('code', htmlspecialchars($disease['disease_code']));
            $diseaseNode->addChild('name', htmlspecialchars($disease['disease_name']));
            $diseaseNode->addChild('outpatient_count', $disease['outpatient_count']);
        }
    }
    
    // Formatowanie XML z wcięciami
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    
    return $dom->saveXML();
}

// Pobierz statystyki
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
    <title>Eksport XML - System Integracji Danych</title>
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
        <h2>Eksport danych do XML</h2>
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
                    Eksportuj do XML
                </button>
                <a href="../index.php" class="btn btn-secondary" style="text-decoration: none;">
                    Anuluj
                </a>
            </form>
        </div>
    </div>
</body>
</html>