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
                $filename = 'alkohol_' . $yearFrom . '_' . $yearTo . '.xml';
                $recordCount = count($alcoholData);
            }
            // Eksport danych o chorobach
            elseif ($exportType === 'diseases') {
                $diseaseData = getDiseaseDataByYearRange($yearFrom, $yearTo);
                $xmlContent = generateDiseaseXML($diseaseData);
                $filename = 'choroby_' . $yearFrom . '_' . $yearTo . '.xml';
                $recordCount = count($diseaseData);
            }
            // Eksport obu typów w jednym pliku ZIP
            else {
                // Generuj oba pliki XML
                $alcoholData = AlcoholConsumption::getByYearRange($yearFrom, $yearTo);
                $alcoholXML = generateAlcoholXML($alcoholData);
                
                $diseaseData = getDiseaseDataByYearRange($yearFrom, $yearTo);
                $diseaseXML = generateDiseaseXML($diseaseData);
                
                // Tworzenie archiwum ZIP
                $zip = new ZipArchive();
                $zipFileName = 'dane_kompletne_' . $yearFrom . '_' . $yearTo . '.zip';
                $zipPath = sys_get_temp_dir() . '/' . $zipFileName;
                
                if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
                    $zip->addFromString('alkohol_' . $yearFrom . '_' . $yearTo . '.xml', $alcoholXML);
                    $zip->addFromString('choroby_' . $yearFrom . '_' . $yearTo . '.xml', $diseaseXML);
                    $zip->close();
                    
                    $recordCount = count($alcoholData) + count($diseaseData);
                    
                    // Logowanie operacji
                    DataLog::log(
                        $_SESSION['user_id'],
                        'export',
                        'xml',
                        $zipFileName,
                        $recordCount,
                        'success',
                        null
                    );
                    
                    // Wysłanie pliku ZIP
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
                    header('Content-Length: ' . filesize($zipPath));
                    readfile($zipPath);
                    unlink($zipPath);
                    exit;
                } else {
                    $message = 'Błąd podczas tworzenia archiwum ZIP.';
                    $messageType = 'error';
                }
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
                
                // Wysłanie pliku do pobrania
                header('Content-Type: text/xml; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($xmlContent));
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
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><tabela></tabela>');
    
    foreach ($data as $record) {
        $wiersz = $xml->addChild('wiersz');
        $wiersz->addChild('Rok', $record->year);
        $wiersz->addChild('Wyroby_spirytusowe_100_alkoholu', number_format($record->spirits_100_alcohol, 1, '.', ''));
        $wiersz->addChild('Wino_i_miody_pitne', number_format($record->wine_mead, 1, '.', ''));
        $wiersz->addChild('Wino_i_miody_pitne_w_przeliczeniu_na_100_alkohol', number_format($record->wine_mead_100_alcohol, 2, '.', ''));
        $wiersz->addChild('Piwo', number_format($record->beer, 1, '.', ''));
        $wiersz->addChild('Piwo_w_przeliczeniu_na_100_alkoholu', number_format($record->beer_100_alcohol, 2, '.', ''));
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
    
    // Mapowanie kodów chorób na nazwy XML
    $codeToXmlName = [
        'E24.4' => 'zespol_pseudo_cushinga_u_alkoholikow',
        'F10' => 'zaburzenia_psychiczne_i_zachowania_spowodowane_uzyciem_alkoholu',
        'G31.2' => 'zwyrodnienie_ukladu_nerwowego_wywolane_przez_alkohol',
        'G62.1' => 'polineuropatia_alkoholowa',
        'G72.1' => 'miopatia_alkoholowa',
        'I42.6' => 'kardiomiopatia_alkoholowa',
        'K29.2' => 'alkoholowe_zapalenie_zoladka',
        'K70' => 'alkoholowa_choroba_watroby',
        'K73' => 'przewlekle_zapalenie_watroby_niesklasyfikowane_gdzie_indziej',
        'K74.0' => 'zwloknienie_watroby',
        'K74.1' => 'stwardnienie_watroby',
        'K74.2' => 'zwloknienie_watroby_ze_stwardnieniem_watroby',
        'K74.6' => 'inna_i_nieokreslona_marskosc_watroby',
        'K85.2' => 'alkoholowe_ostre_zapalenie_trzustki',
        'K86.0' => 'przewlekle_zapalenie_trzustki_wywolane_alkoholem',
        'Q86.0' => 'plodowy_zespol_alkoholowy_dysmorficzny',
        'R78.0' => 'stwierdzenie_obecnosci_alkoholu_we_krwi'
    ];
    
    $stmt = $pdo->prepare("
        SELECT year, disease_code, outpatient_count 
        FROM diseases 
        WHERE year BETWEEN ? AND ? 
        ORDER BY year, disease_code
    ");
    $stmt->execute([$yearFrom, $yearTo]);
    
    $dataByYear = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $year = $row['year'];
        if (!isset($dataByYear[$year])) {
            $dataByYear[$year] = [];
        }
        
        if (isset($codeToXmlName[$row['disease_code']])) {
            $xmlName = $codeToXmlName[$row['disease_code']];
            $dataByYear[$year][$xmlName] = $row['outpatient_count'];
        }
    }
    
    return $dataByYear;
}

/**
 * Generuje XML dla danych o chorobach
 */
function generateDiseaseXML($dataByYear) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><dane_alkoholowe></dane_alkoholowe>');
    
    foreach ($dataByYear as $year => $diseases) {
        $rokNode = $xml->addChild('rok');
        $rokNode->addAttribute('year', $year);
        
        // Lista wszystkich możliwych chorób w odpowiedniej kolejności
        $allDiseases = [
            'zespol_pseudo_cushinga_u_alkoholikow',
            'zaburzenia_psychiczne_i_zachowania_spowodowane_uzyciem_alkoholu',
            'zwyrodnienie_ukladu_nerwowego_wywolane_przez_alkohol',
            'polineuropatia_alkoholowa',
            'miopatia_alkoholowa',
            'kardiomiopatia_alkoholowa',
            'alkoholowe_zapalenie_zoladka',
            'alkoholowa_choroba_watroby',
            'przewlekle_zapalenie_watroby_niesklasyfikowane_gdzie_indziej',
            'zwloknienie_watroby',
            'stwardnienie_watroby',
            'zwloknienie_watroby_ze_stwardnieniem_watroby',
            'inna_i_nieokreslona_marskosc_watroby',
            'alkoholowe_ostre_zapalenie_trzustki',
            'przewlekle_zapalenie_trzustki_wywolane_alkoholem',
            'plodowy_zespol_alkoholowy_dysmorficzny',
            'stwierdzenie_obecnosci_alkoholu_we_krwi'
        ];
        
        foreach ($allDiseases as $diseaseName) {
            $value = $diseases[$diseaseName] ?? 0;
            
            // Konwersja wartości <5 na format XML
            if ($value > 0 && $value < 5) {
                $rokNode->addChild($diseaseName, '<5');
            } else {
                $rokNode->addChild($diseaseName, $value);
            }
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
$alcoholStats = AlcoholConsumption::getStatistics();
$diseaseStats = Disease::count();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eksport XML - System Integracji Danych</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="header">
        <h1>Eksport danych do XML</h1>
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
                            Spożycie alkoholu
                        </label>
                        <label>
                            <input type="radio" name="export_type" value="diseases">
                            Choroby
                        </label>
                        <label>
                            <input type="radio" name="export_type" value="both">
                            Wszystkie dane (ZIP)
                        </label>
                    </div>
                </div>
                
                <div class="grid">
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
        
        <!-- Przykład formatu -->
        <div class="card">
            <h3>Format eksportowanych plików XML</h3>
            <p>Pliki XML są generowane zgodnie z formatem źródłowym:</p>
            <ul>
                <li><strong>Alkohol:</strong> struktura &lt;tabela&gt;&lt;wiersz&gt;...&lt;/wiersz&gt;&lt;/tabela&gt;</li>
                <li><strong>Choroby:</strong> struktura &lt;dane_alkoholowe&gt;&lt;rok year="..."&gt;...&lt;/rok&gt;&lt;/dane_alkoholowe&gt;</li>
                <li>Wartości mniejsze niż 5 są eksportowane jako "&lt;5"</li>
                <li>Kodowanie: UTF-8</li>
            </ul>
        </div>
    </div>
</body>
</html>