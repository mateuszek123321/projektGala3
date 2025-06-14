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
                
                if ($format === 'polish') {
                    // Format z polskimi nazwami
                    $alcoholArray = [];
                    foreach ($alcoholData as $record) {
                        $alcoholArray[] = [
                            'Rok' => $record->year,
                            'Wyroby spirytusowe (100% alkoholu)' => (float)$record->spirits_100_alcohol,
                            'Wino i miody pitne' => (float)$record->wine_mead,
                            'Wino i miody pitne w przeliczeniu na 100% alkohol' => (float)$record->wine_mead_100_alcohol,
                            'Piwo' => (float)$record->beer,
                            'Piwo w przeliczeniu na 100% alkoholu' => (float)$record->beer_100_alcohol
                        ];
                    }
                    $data['spozycie_alkoholu'] = $alcoholArray;
                } else {
                    // Format z angielskimi nazwami
                    $alcoholArray = [];
                    foreach ($alcoholData as $record) {
                        $alcoholArray[] = $record->toArray();
                    }
                    $data['alcohol_consumption'] = $alcoholArray;
                }
            }
            
            if ($exportType === 'diseases' || $exportType === 'both') {
                // Mapowanie kodów chorób na polskie nazwy
                $polishDiseaseNames = [
                    'E24.4' => 'Zespół pseudo-cushinga u alkoholików',
                    'F10' => 'Zaburzenia psychiczne i zachowania spowodowane użyciem alkoholu',
                    'G31.2' => 'Zwyrodnienie układu nerwowego wywołane przez alkohol',
                    'G62.1' => 'Polineuropatia alkoholowa',
                    'G72.1' => 'Miopatia alkoholowa',
                    'I42.6' => 'Kardiomiopatia alkoholowa',
                    'K29.2' => 'Alkoholowe zapalenie żołądka',
                    'K70' => 'Alkoholowa choroba wątroby',
                    'K73' => 'Alkoholowe uszkodzenie wątroby niesklasyfikowane gdzie indziej',
                    'K74.0' => 'Zwłóknienie wątroby',
                    'K74.1' => 'Stwardnienie wątroby',
                    'K74.2' => 'Zwłóknienie wątroby ze stwardnieniem wątroby',
                    'K74.6' => 'Inna i nieokreślona marskość wątroby',
                    'K85.2' => 'Alkoholowe ostre zapalenie trzustki',
                    'K86.0' => 'Przewlekłe zapalenie trzustki wywołane alkoholem',
                    'Q86.0' => 'Płodowy zespół alkoholowy (dysmoriczny)',
                    'R78.0' => 'Stwierdzenie obecności alkoholu we krwi'
                ];
                
                // Pobierz wszystkie choroby w zakresie lat
                $diseaseData = Disease::raw(
                    "SELECT * FROM diseases WHERE year BETWEEN ? AND ? ORDER BY year, disease_code",
                    [$yearFrom, $yearTo]
                )->fetchAll(PDO::FETCH_ASSOC);
                
                if ($format === 'polish') {
                    // Format z polskimi nazwami - zgrupowane po latach
                    $diseasesByYear = [];
                    
                    foreach ($diseaseData as $record) {
                        $year = $record['year'];
                        if (!isset($diseasesByYear[$year])) {
                            $diseasesByYear[$year] = ['Rok' => (int)$year];
                        }
                        
                        // Znajdź polską nazwę choroby
                        $polishName = $polishDiseaseNames[$record['disease_code']] ?? $record['disease_name'];
                        
                        // Konwersja wartości - jeśli mniejsze niż 5, zapisz jako "<5"
                        $value = (int)$record['outpatient_count'];
                        if ($value > 0 && $value < 5) {
                            $diseasesByYear[$year][$polishName] = '<5';
                        } else {
                            $diseasesByYear[$year][$polishName] = $value;
                        }
                    }
                    
                    $data['choroby'] = array_values($diseasesByYear);
                } else {
                    // Format z angielskimi nazwami - każdy rekord osobno
                    $diseaseArray = [];
                    foreach ($diseaseData as $record) {
                        $diseaseArray[] = [
                            'disease_code' => $record['disease_code'],
                            'disease_name' => $record['disease_name'],
                            'province' => $record['province'],
                            'year' => (int)$record['year'],
                            'outpatient_count' => (int)$record['outpatient_count'],
                            'hospital_count' => (int)$record['hospital_count'],
                            'emergency_count' => (int)$record['emergency_count'],
                            'admission_count' => (int)$record['admission_count']
                        ];
                    }
                    $data['diseases'] = $diseaseArray;
                }
            }
            
            // Generowanie nazwy pliku
            if ($exportType === 'both') {
                $filename = 'dane_kompletne_' . $yearFrom . '_' . $yearTo . '.json';
            } elseif ($exportType === 'alcohol') {
                $filename = 'alkohol_' . $yearFrom . '_' . $yearTo . '.json';
            } else {
                $filename = 'choroby_' . $yearFrom . '_' . $yearTo . '.json';
            }
            
            // Konwersja do JSON
            $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if ($jsonContent === false) {
                throw new Exception('Błąd podczas generowania JSON');
            }
            
            // Logowanie operacji
            $recordCount = 0;
            if (isset($data['spozycie_alkoholu'])) $recordCount += count($data['spozycie_alkoholu']);
            if (isset($data['alcohol_consumption'])) $recordCount += count($data['alcohol_consumption']);
            if (isset($data['choroby'])) $recordCount += count($data['choroby']);
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
            
            // Wysłanie pliku do pobrania
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($jsonContent));
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
$alcoholStats = AlcoholConsumption::getStatistics();
$diseaseStats = Disease::count();
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
        
        <!-- Przykład formatu -->
        <div class="card">
            <h3>Przykład wygenerowanego pliku JSON</h3>
            <div class="code-block">{
    "spozycie_alkoholu": [
        {
            "Rok": 2023,
            "Wyroby spirytusowe (100% alkoholu)": 3.5,
            "Wino i miody pitne": 7.6,
            "Wino i miody pitne w przeliczeniu na 100% alkohol": 0.91,
            "Piwo": 38.6,
            "Piwo w przeliczeniu na 100% alkoholu": 2.12
        }
    ],
    "choroby": [
        {
            "Rok": 2023,
            "Zespół pseudo-cushinga u alkoholików": "&lt;5",
            "Zaburzenia psychiczne i zaburzenia zachowania spowodowane użyciem alkoholu": 2081,
            "Zwyrodnienie układu nerwowego wywołane przez alkohol": 733,
            "Polineuropatia alkoholowa": 1108,
            "Kardiomiopatia alkoholowa": 72,
            "Alkoholowa choroba wątroby": 10769
        }
    ]
}</div>
        </div>
    </div>
</body>
</html>