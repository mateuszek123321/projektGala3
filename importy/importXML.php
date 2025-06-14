<?php
session_start();

// Włączenie wymaganych plików
require_once '../config/database.php';
require_once '../models/AlcoholConsumption.php';
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
    
    // Sprawdzenie typu importu (alkohol lub choroby)
    $importType = $_POST['import_type'] ?? '';
    
    if (!in_array($importType, ['alcohol', 'diseases'])) {
        $message = 'Nieprawidłowy typ importu.';
        $messageType = 'error';
    } elseif (isset($_FILES['xml_file']) && $_FILES['xml_file']['error'] === UPLOAD_ERR_OK) {
        
        $uploadedFile = $_FILES['xml_file'];
        $fileName = $uploadedFile['name'];
        $fileTmpName = $uploadedFile['tmp_name'];
        $fileSize = $uploadedFile['size'];
        
        // Walidacja rozszerzenia pliku
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileExt !== 'xml') {
            $message = 'Dozwolone są tylko pliki XML.';
            $messageType = 'error';
        } elseif ($fileSize > 5 * 1024 * 1024) { // Max 5MB
            $message = 'Plik jest zbyt duży. Maksymalny rozmiar to 5MB.';
            $messageType = 'error';
        } else {
            // Odczyt zawartości pliku
            $xmlContent = file_get_contents($fileTmpName);
            
            // Parsowanie XML
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlContent);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMessage = 'Błąd parsowania XML: ';
                foreach ($errors as $error) {
                    $errorMessage .= $error->message . ' ';
                }
                $message = $errorMessage;
                $messageType = 'error';
            } else {
                // Import danych do bazy
                if ($importType === 'alcohol') {
                    $result = importAlcoholDataXML($xml);
                } else {
                    $result = importDiseaseDataXML($xml);
                }
                
                // Logowanie operacji
                DataLog::log(
                    $_SESSION['user_id'],
                    'import',
                    'xml',
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
        $message = 'Proszę wybrać plik do importu. Kod błędu: ' . ($_FILES['xml_file']['error'] ?? 'brak pliku');
        $messageType = 'error';
    }
}

/**
 * Funkcja importująca dane o spożyciu alkoholu z XML
 */
function importAlcoholDataXML($xml) {
    $count = 0;
    $pdo = getDBConnection();
    
    try {
        $pdo->beginTransaction();
        
        // Przetwarzanie każdego wiersza
        foreach ($xml->wiersz as $wiersz) {
            $year = (int)$wiersz->Rok;
            
            if (!$year) {
                continue;
            }
            
            // Sprawdzenie czy dane dla tego roku już istnieją
            $existing = AlcoholConsumption::findByYear($year);
            
            // Przygotowanie danych
            $mappedData = [
                'year' => $year,
                'spirits_100_alcohol' => (float)$wiersz->Wyroby_spirytusowe_100_alkoholu,
                'wine_mead' => (float)$wiersz->Wino_i_miody_pitne,
                'wine_mead_100_alcohol' => (float)$wiersz->Wino_i_miody_pitne_w_przeliczeniu_na_100_alkohol,
                'beer' => (float)$wiersz->Piwo,
                'beer_100_alcohol' => (float)$wiersz->Piwo_w_przeliczeniu_na_100_alkoholu
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
 * Funkcja importująca dane o chorobach z XML
 */
function importDiseaseDataXML($xml) {
    $count = 0;
    $pdo = getDBConnection();
    
    try {
        $pdo->beginTransaction();
        
        // Mapowanie nazw XML na kody ICD-10
        $xmlToDiseaseMapping = [
            'zespol_pseudo_cushinga_u_alkoholikow' => ['code' => 'E24.4', 'name' => 'Zespół pseudo-Cushinga u alkoholików'],
            'zaburzenia_psychiczne_i_zachowania_spowodowane_uzyciem_alkoholu' => ['code' => 'F10', 'name' => 'Zaburzenia psychiczne i zaburzenia zachowania spowodowane użyciem alkoholu'],
            'zwyrodnienie_ukladu_nerwowego_wywolane_przez_alkohol' => ['code' => 'G31.2', 'name' => 'Zwyrodnienie układu nerwowego wywołane przez alkohol'],
            'polineuropatia_alkoholowa' => ['code' => 'G62.1', 'name' => 'Polineuropatia alkoholowa'],
            'miopatia_alkoholowa' => ['code' => 'G72.1', 'name' => 'Miopatia alkoholowa'],
            'kardiomiopatia_alkoholowa' => ['code' => 'I42.6', 'name' => 'Kardiomiopatia alkoholowa'],
            'alkoholowe_zapalenie_zoladka' => ['code' => 'K29.2', 'name' => 'Alkoholowe zapalenie żołądka'],
            'alkoholowa_choroba_watroby' => ['code' => 'K70', 'name' => 'Alkoholowa choroba wątroby'],
            'alkoholowe_uszkodzenie_watroby_niesklasyfikowane_gdzie_indziej' => ['code' => 'K73', 'name' => 'Przewlekłe zapalenie wątroby niesklasyfikowane gdzie indziej'],
            'zwloknienie_watroby' => ['code' => 'K74.0', 'name' => 'Zwłóknienie wątroby'],
            'stwardnienie_watroby' => ['code' => 'K74.1', 'name' => 'Stwardnienie wątroby'],
            'zwloknienie_watroby_ze_stwardnieniem_watroby' => ['code' => 'K74.2', 'name' => 'Zwłóknienie wątroby ze stwardnieniem wątroby'],
            'inna_i_nieokreslona_marskosc_watroby' => ['code' => 'K74.6', 'name' => 'Inna i nieokreślona marskość wątroby'],
            'alkoholowe_ostre_zapalenie_trzustki' => ['code' => 'K85.2', 'name' => 'Alkoholowe ostre zapalenie trzustki'],
            'przewlekle_zapalenie_trzustki_wywolane_alkoholem' => ['code' => 'K86.0', 'name' => 'Alkoholowe przewlekłe zapalenie trzustki'],
            'plodowy_zespol_alkoholowy_dysmoryczny' => ['code' => 'Q86.0', 'name' => 'Płodowy zespół alkoholowy (dysmorficzny)'],
            'stwierdzenie_obecnosci_alkoholu_we_krwi' => ['code' => 'R78.0', 'name' => 'Stwierdzenie obecności alkoholu we krwi']
        ];
        
        // Przetwarzanie każdego roku
        foreach ($xml->rok as $rok) {
            $year = (int)$rok['year'];
            
            if (!$year) {
                continue;
            }
            
            // Przetwarzanie każdej choroby w danym roku
            foreach ($rok->children() as $diseaseName => $value) {
                // Sprawdź czy mamy mapowanie dla tej choroby
                if (!isset($xmlToDiseaseMapping[$diseaseName])) {
                    continue;
                }
                
                $diseaseInfo = $xmlToDiseaseMapping[$diseaseName];
                
                // Obsłuż wartości typu "<5"
                $textValue = (string)$value;
                if ($textValue === '<5' || $textValue === '&lt;5') {
                    $outpatientCount = 4;
                } else {
                    $outpatientCount = intval($textValue);
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
    <title>Import XML - System Integracji Danych</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body {
            padding-top: 60px;
        }
        
        .header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
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
        <h1>Import danych XML</h1>
        <a href="../index.php">← Powrót do panelu</a>
    </div>
    
    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Import danych z pliku XML</h2>
            
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
                    <label for="xml_file">Wybierz plik XML:</label>
                    <input type="file" name="xml_file" id="xml_file" accept=".xml" required>
                </div>
                
                <div class="info">
                    <strong>Informacje o pliku:</strong>
                    <ul>
                        <li>Maksymalny rozmiar: 5MB</li>
                        <li>Dozwolone formaty: .xml</li>
                        <li>Kodowanie: UTF-8</li>
                    </ul>
                </div>
                
                <button type="submit" name="submit" class="btn">Importuj dane</button>
                <a href="../index.php" class="btn btn-secondary" style="text-decoration: none;">Anuluj</a>
            </form>
        </div>
        
        <div class="card">
            <h3>Przykładowy format plików XML:</h3>
            
            <h4>Dane o spożyciu alkoholu:</h4>
            <div class="code-block">&lt;?xml version="1.0" encoding="utf-8"?&gt;
&lt;tabela&gt;
  &lt;wiersz&gt;
    &lt;Rok&gt;2023&lt;/Rok&gt;
    &lt;Wyroby_spirytusowe_100_alkoholu&gt;3.5&lt;/Wyroby_spirytusowe_100_alkoholu&gt;
    &lt;Wino_i_miody_pitne&gt;7.6&lt;/Wino_i_miody_pitne&gt;
    &lt;Wino_i_miody_pitne_w_przeliczeniu_na_100_alkohol&gt;0.91&lt;/Wino_i_miody_pitne_w_przeliczeniu_na_100_alkohol&gt;
    &lt;Piwo&gt;38.6&lt;/Piwo&gt;
    &lt;Piwo_w_przeliczeniu_na_100_alkoholu&gt;2.12&lt;/Piwo_w_przeliczeniu_na_100_alkoholu&gt;
  &lt;/wiersz&gt;
&lt;/tabela&gt;</div>
            
            <h4>Dane o chorobach:</h4>
            <div class="code-block">&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;dane_alkoholowe&gt;
  &lt;rok year="2023"&gt;
    &lt;zespol_pseudo_cushinga_u_alkoholikow&gt;&amp;lt;5&lt;/zespol_pseudo_cushinga_u_alkoholikow&gt;
    &lt;zaburzenia_psychiczne_i_zachowania_spowodowane_uzyciem_alkoholu&gt;2081&lt;/zaburzenia_psychiczne_i_zachowania_spowodowane_uzyciem_alkoholu&gt;
    &lt;zwyrodnienie_ukladu_nerwowego_wywolane_przez_alkohol&gt;733&lt;/zwyrodnienie_ukladu_nerwowego_wywolane_przez_alkohol&gt;
    &lt;polineuropatia_alkoholowa&gt;1108&lt;/polineuropatia_alkoholowa&gt;
    &lt;alkoholowa_choroba_watroby&gt;10769&lt;/alkoholowa_choroba_watroby&gt;
  &lt;/rok&gt;
&lt;/dane_alkoholowe&gt;</div>
        </div>
    </div>
</body>
</html>