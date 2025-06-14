<?php
session_start(); 

// Włączenie wymaganych plików
require_once '../config/database.php';
require_once '../models/AlcoholConsumption.php';
require_once '../models/DiseaseDictionary.php';
require_once '../models/Disease.php';
require_once '../models/DataLog.php';

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$messageType = '';

// Obsługa przesyłania pliku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
    
    // Sprawdzenie typu importu (alkohol lub choroby)
    $importType = $_POST['import_type'] ?? '';
    
    if (!in_array($importType, ['alcohol', 'diseases'])) {
        $message = 'Nieprawidłowy typ importu.';
        $messageType = 'error';
    } elseif (isset($_FILES['json_file']) && $_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
        
        $uploadedFile = $_FILES['json_file'];
        $fileName = $uploadedFile['name'];
        $fileTmpName = $uploadedFile['tmp_name'];
        $fileSize = $uploadedFile['size'];
        
        // Walidacja rozszerzenia pliku
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileExt !== 'json') {
            $message = 'Dozwolone są tylko pliki JSON.';
            $messageType = 'error';
        } elseif ($fileSize > 5 * 1024 * 1024) { // Max 5MB
            $message = 'Plik jest zbyt duży. Maksymalny rozmiar to 5MB.';
            $messageType = 'error';
        } else {
            // Odczyt zawartości pliku
            $jsonContent = file_get_contents($fileTmpName);
            $data = json_decode($jsonContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = 'Błąd parsowania JSON: ' . json_last_error_msg();
                $messageType = 'error';
            } else {
                // Import danych do bazy
                if ($importType === 'alcohol') {
                    $result = importAlcoholData($data);
                } else {
                    $result = importDiseaseData($data);
                }
                
                // Logowanie operacji
                DataLog::log(
                    $_SESSION['user_id'],
                    'import',
                    'json',
                    $fileName,
                    $result['count'],
                    $result['success'] ? 'success' : 'failed',
                    $result['error'] ?? null
                );
                
                if ($result['success']) {
                    $message = "Import zakończony sukcesem. Zaimportowano {$result['count']} rekordów.";
                    $messageType = 'success';
                } else {
                    $message = "Błąd podczas importu: " . ($result['error'] ?? 'Nieznany błąd');
                    $messageType = 'error';
                }
            }
        }
    } else {
        $message = 'Proszę wybrać plik do importu.';
        $messageType = 'error';
    }
}

/**
 * Funkcja importująca dane o spożyciu alkoholu
 * @param array $data Dane z pliku JSON
 * @return array Wynik operacji
 */
function importAlcoholData($data) {
    $count = 0;
    $pdo = getDBConnection();
    
    try {
        $pdo->beginTransaction();
        
        // Sprawdzenie struktury danych
        if (!is_array($data)) {
            throw new Exception('Nieprawidłowa struktura danych JSON');
        }
        
        // Jeśli dane są w formie obiektu z kluczem głównym
        if (isset($data['alcohol_consumption'])) {
            $data = $data['alcohol_consumption'];
        }
        
        foreach ($data as $record) {
            // Mapowanie polskich nazw na angielskie nazwy kolumn
            $year = $record['Rok'] ?? $record['year'] ?? null;
            
            if (!$year) {
                continue; // Pomijamy rekordy bez roku
            }
            
            // Sprawdzenie czy dane dla tego roku już istnieją
            $existing = AlcoholConsumption::findByYear($year);
            
            // Mapowanie danych z polskich nazw
            $mappedData = [
                'year' => $year,
                'spirits_100_alcohol' => $record['Wyroby spirytusowe (100% alkoholu)'] ?? $record['spirits_100_alcohol'] ?? null,
                'wine_mead' => $record['Wino i miody pitne'] ?? $record['wine_mead'] ?? null,
                'wine_mead_100_alcohol' => $record['Wino i miody pitne w przeliczeniu na 100% alkohol'] ?? $record['wine_mead_100_alcohol'] ?? null,
                'beer' => $record['Piwo'] ?? $record['beer'] ?? null,
                'beer_100_alcohol' => $record['Piwo w przeliczeniu na 100% alkoholu'] ?? $record['beer_100_alcohol'] ?? null
            ];
            
            if ($existing) {
                // Aktualizacja istniejących danych
                foreach ($mappedData as $key => $value) {
                    if ($key !== 'year') {
                        $existing->$key = $value;
                    }
                }
                $existing->save();
            } else {
                // Tworzenie nowego rekordu
                $alcohol = new AlcoholConsumption($mappedData);
                $alcohol->save();
            }
            $count++;
        }
        
        $pdo->commit();
        return ['success' => true, 'count' => $count];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'count' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Funkcja importująca dane o chorobach
 * @param array $data Dane z pliku JSON
 * @return array Wynik operacji
 */
function importDiseaseData($data) {
    $count = 0;
    $pdo = getDBConnection();
    
    try {
        $pdo->beginTransaction();
        
        // Sprawdzenie struktury danych
        if (!is_array($data)) {
            throw new Exception('Nieprawidłowa struktura danych JSON');
        }
        
        // Mapowanie polskich nazw chorób na kody ICD-10
        $JsonDiseaseMapping = [
            'Zespół pseudo-cushinga u alkoholików' => ['code' => 'E24.4', 'name' => 'Zespół pseudo-Cushinga u alkoholików'],
            'Zaburzenia psychiczne i zachowania spowodowane użyciem alkoholu' => ['code' => 'F10', 'name' => 'Zaburzenia psychiczne i zaburzenia zachowania spowodowane użyciem alkoholu'],
            'Zwyrodnienie układu nerwowego wywołane przez alkohol' => ['code' => 'G31.2', 'name' => 'Zwyrodnienie układu nerwowego wywołane przez alkohol'],
            'Polineuropatia alkoholowa' => ['code' => 'G62.1', 'name' => 'Polineuropatia alkoholowa'],
            'Miopatia alkoholowa' => ['code' => 'G72.1', 'name' => 'Miopatia alkoholowa'],
            'Kardiomiopatia alkoholowa' => ['code' => 'I42.6', 'name' => 'Kardiomiopatia alkoholowa'],
            'Alkoholowe zapalenie żołądka' => ['code' => 'K29.2', 'name' => 'Alkoholowe zapalenie żołądka'],
            'Alkoholowa choroba wątroby' => ['code' => 'K70', 'name' => 'Alkoholowa choroba wątroby'],
            'Przewlekłe zapalenie wątroby niesklasyfikowane gdzie indziej' => ['code' => 'K73', 'name' => 'Przewlekłe zapalenie wątroby niesklasyfikowane gdzie indziej'],
            'Zwłóknienie wątroby' => ['code' => 'K74.0', 'name' => 'Zwłóknienie wątroby'],
            'Stwardnienie wątroby' => ['code' => 'K74.1', 'name' => 'Stwardnienie wątroby'],
            'Zwłóknienie wątroby ze stwardnieniem wątroby' => ['code' => 'K74.2', 'name' => 'Zwłóknienie wątroby ze stwardnieniem wątroby'],
            'Inna i nieokreślona marskość wątroby' => ['code' => 'K74.6', 'name' => 'Inna i nieokreślona marskość wątroby'],
            'Alkoholowe ostre zapalenie trzustki' => ['code' => 'K85.2', 'name' => 'Alkoholowe ostre zapalenie trzustki'],
            'Przewlekłe zapalenie trzustki wywołane alkoholem' => ['code' => 'K86.0', 'name' => 'Alkoholowe przewlekłe zapalenie trzustki'],
            'Płodowy zespół alkoholowy (dysmorficzny)' => ['code' => 'Q86.0', 'name' => 'Płodowy zespół alkoholowy (dysmorficzny)'],
            'Stwierdzenie obecności alkoholu we krwi' => ['code' => 'R78.0', 'name' => 'Stwierdzenie obecności alkoholu we krwi']
        ];
        
        // Przetwarzanie danych
        foreach ($data as $yearRecord) {
            // Pobierz rok
            $year = $yearRecord['Rok'] ?? null;
            if (!$year) {
                continue;
            }
            
            // Przetwórz każdą chorobę w danym roku
            foreach ($yearRecord as $diseaseName => $value) {
                if ($diseaseName === 'Rok') {
                    continue;
                }
                
                // Sprawdź czy mamy mapowanie dla tej choroby
                if (!isset($JsonDiseaseMapping[$diseaseName])) {
                    error_log(">> rok $year — choroba: “{$diseaseName}” — wartość: “{$value}”");
                    if ($diseaseName === 'Rok') continue;
                    if (!isset($JsonDiseaseMapping[$diseaseName])) {
                        error_log("   >> brak mapowania dla: “{$diseaseName}”");
                    continue;
                    }
                }
                
                $diseaseInfo = $JsonDiseaseMapping[$diseaseName];
                
                // Obsłuż wartości typu "<5"
                if (is_string($value) && $value === '<5') {
                    $outpatientCount = 4; // Przyjmujemy 4 jako wartość dla "<5"
                } else {
                    $outpatientCount = intval($value);
                }
                
                // Tworzenie rekordu
                $disease = new Disease([
                    'disease_code' => $diseaseInfo['code'],
                    'disease_name' => $diseaseInfo['name'],
                    'province' => 'Polska',
                    'year' => $year,
                    'outpatient_count' => $outpatientCount,
                    'hospital_count' => 0,
                    'emergency_count' => 0,
                    'admission_count' => 0
                ]);
                $disease->save();
                $count++;
            }
        }
        
        $pdo->commit();
        return ['success' => true, 'count' => $count];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'count' => 0, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import JSON - System Integracji Danych</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            padding-top: 60px;
        }
        
        .header {
            background-color: #333;
            color: white;
            padding: 1rem;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
        }
        
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .import-form {
            background-color: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
            color: #555;
        }
        
        select,
        input[type="file"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .file-info {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }
        
        .btn {
            background-color: #4CAF50;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: #45a049;
        }
        
        .btn-secondary {
            background-color: #666;
            margin-left: 1rem;
        }
        
        .btn-secondary:hover {
            background-color: #555;
        }
        
        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .sample-format {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin-top: 2rem;
        }
        
        .sample-format h3 {
            margin-bottom: 1rem;
            color: #333;
        }
        
        .code-block {
            background-color: #e9ecef;
            padding: 1rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.9rem;
            overflow-x: auto;
            white-space: pre;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Import danych JSON</h1>
        <a href="../index.php" style="color: white; text-decoration: none;">← Powrót do panelu</a>
    </div>
    
    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="import-form">
            <h2>Import danych z pliku JSON</h2>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="import_type">Typ danych do importu:</label>
                    <select name="import_type" id="import_type" required>
                        <option value="">-- Wybierz typ danych --</option>
                        <option value="alcohol">Dane o spożyciu alkoholu</option>
                        <option value="diseases">Dane o chorobach</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="json_file">Wybierz plik JSON:</label>
                    <input type="file" name="json_file" id="json_file" accept=".json" required>
                </div>
                
                <div class="file-info">
                    <strong>Informacje o pliku:</strong>
                    <ul>
                        <li>Maksymalny rozmiar: 5MB</li>
                        <li>Dozwolone formaty: .json</li>
                        <li>Kodowanie: UTF-8</li>
                    </ul>
                </div>
                
                <button type="submit" name="submit" class="btn">Importuj dane</button>
                <a href="../index.php" class="btn btn-secondary" style="text-decoration: none;">Anuluj</a>
            </form>
        </div>
        
        <div class="sample-format">
            <h3>Przykładowy format plików JSON:</h3>
            
            <h4>Dane o spożyciu alkoholu:</h4>
            <div class="code-block">[
    {
        "Rok": 2023,
        "Wyroby spirytusowe (100% alkoholu)": 3.5,
        "Wino i miody pitne": 7.6,
        "Wino i miody pitne w przeliczeniu na 100% alkohol": 0.91,
        "Piwo": 38.6,
        "Piwo w przeliczeniu na 100% alkoholu": 2.12
    }
]
// LUB format angielski:
[
    {
        "year": 2023,
        "spirits_100_alcohol": 3.5,
        "wine_mead": 7.6,
        "wine_mead_100_alcohol": 0.91,
        "beer": 38.6,
        "beer_100_alcohol": 2.12
    }
]</div>
            
            <h4 style="margin-top: 1rem;">Dane o chorobach:</h4>
            <div class="code-block">[
    {
        "Rok": 2023,
        "Zespół pseudo-cushinga u alkoholików": "&lt;5",
        "Zaburzenia psychiczne i zachowania spowodowane użyciem alkoholu": 2081,
        "Zwyrodnienie układu nerwowego wywołane przez alkohol": 733,
        "Polineuropatia alkoholowa": 1108,
        "Miopatia alkoholowa": 5,
        "Kardiomiopatia alkoholowa": 72,
        "Alkoholowe zapalenie żołądka": 105,
        "Alkoholowa choroba wątroby": 10769,
        "Zwłóknienie wątroby": 1495,
        "Stwardnienie wątroby": 50,
        "Inna i nieokreślona marskość wątroby": 5832
    }
]

// LUB format angielski (każda choroba osobno):
[
    {
        "disease_code": "F10",
        "disease_name": "Zaburzenia psychiczne spowodowane alkoholem",
        "province": "Polska",
        "year": 2023,
        "outpatient_count": 2081,
        "hospital_count": 800,
        "emergency_count": 450,
        "admission_count": 300
    }
]</div>
        </div>
    </div>
</body>
</html>