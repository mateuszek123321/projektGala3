<?php
session_start();
require_once '../config/database.php';
require_once '../models/AlcoholConsumption.php';
require_once '../models/Disease.php';

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Pobieranie parametrów
$yearFrom = $_GET['year_from'] ?? 2010;
$yearTo = $_GET['year_to'] ?? 2023;
$diseaseCode = $_GET['disease_code'] ?? 'all';
$alcoholType = $_GET['alcohol_type'] ?? 'total';

// Pobieranie danych
$alcoholData = AlcoholConsumption::getByYearRange($yearFrom, $yearTo);
$diseaseCodes = Disease::getUniqueCodes();

// Przygotowanie danych dla wykresu
$chartData = prepareChartData($alcoholData, $diseaseCode, $alcoholType, $yearFrom, $yearTo);

/**
 * Przygotowuje dane dla wykresu
 */
function prepareChartData($alcoholData, $diseaseCode, $alcoholType, $yearFrom, $yearTo) {
    $pdo = getDBConnection();
    $data = [
        'years' => [],
        'alcohol' => [],
        'diseases' => [],
        'labels' => []
    ];
    
    // Przygotuj dane o alkoholu
    foreach ($alcoholData as $record) {
        $data['years'][] = $record->year;
        
        // Wybór typu alkoholu
        switch ($alcoholType) {
            case 'spirits':
                $data['alcohol'][] = (float)$record->spirits_100_alcohol;
                $data['labels']['alcohol'] = 'Spirytus (100% alkoholu)';
                break;
            case 'wine':
                $data['alcohol'][] = (float)$record->wine_mead_100_alcohol;
                $data['labels']['alcohol'] = 'Wino i miody (100% alkoholu)';
                break;
            case 'beer':
                $data['alcohol'][] = (float)$record->beer_100_alcohol;
                $data['labels']['alcohol'] = 'Piwo (100% alkoholu)';
                break;
            case 'total':
            default:
                $data['alcohol'][] = (float)$record->getTotalAlcohol100();
                $data['labels']['alcohol'] = 'Całkowite spożycie (100% alkoholu)';
                break;
        }
    }
    
    // Pobierz dane o chorobach
    if ($diseaseCode === 'all') {
        // Suma wszystkich chorób
        $stmt = $pdo->prepare("
            SELECT year, SUM(outpatient_count) as total
            FROM diseases
            WHERE year BETWEEN ? AND ?
            GROUP BY year
            ORDER BY year
        ");
        $stmt->execute([$yearFrom, $yearTo]);
        $data['labels']['disease'] = 'Wszystkie choroby (suma)';
        
        $diseasesByYear = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $diseasesByYear[$row['year']] = (int)$row['total'];
        }
        
        foreach ($data['years'] as $year) {
            $data['diseases'][] = $diseasesByYear[$year] ?? 0;
        }
    } else {
        // Konkretna choroba - poprawione zapytanie
        $stmt = $pdo->prepare("
            SELECT d.year, d.outpatient_count as total
            FROM diseases d
            WHERE d.disease_code = ? AND d.year BETWEEN ? AND ?
            ORDER BY d.year
        ");
        $stmt->execute([$diseaseCode, $yearFrom, $yearTo]);
        
        // Pobierz nazwę choroby ze słownika
        $nameStmt = $pdo->prepare("SELECT disease_name_pl FROM disease_dictionary WHERE disease_code = ?");
        $nameStmt->execute([$diseaseCode]);
        $diseaseName = $nameStmt->fetchColumn();
        
        $data['labels']['disease'] = $diseaseName ?: 'Nieznana choroba';
        
        $diseasesByYear = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $diseasesByYear[$row['year']] = (int)$row['total'];
        }
        
        foreach ($data['years'] as $year) {
            $data['diseases'][] = $diseasesByYear[$year] ?? 0;
        }
    }
    
    return $data;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statystyki - System Integracji Danych</title>
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .chart-container {
            position: relative;
            height: 400px;
            margin: 2rem 0;
        }
        
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .correlation-info {
            background-color: #e9ecef;
            padding: 1rem;
            border-radius: 4px;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Statystyki i analizy</h1>
        <a href="../index.php">← Powrót do panelu</a>
    </div>
    
    <div class="container">
        <!-- Filtry -->
        <div class="card">
            <h2>Wybierz dane do analizy</h2>
            <form method="GET" action="">
                <div class="filters">
                    <div class="form-group">
                        <label for="year_from">Rok od:</label>
                        <input type="number" name="year_from" id="year_from" 
                               value="<?php echo $yearFrom; ?>" min="1990" max="2024">
                    </div>
                    
                    <div class="form-group">
                        <label for="year_to">Rok do:</label>
                        <input type="number" name="year_to" id="year_to" 
                               value="<?php echo $yearTo; ?>" min="1990" max="2024">
                    </div>
                    
                    <div class="form-group">
                        <label for="alcohol_type">Typ alkoholu:</label>
                        <select name="alcohol_type" id="alcohol_type">
                            <option value="total" <?php echo $alcoholType === 'total' ? 'selected' : ''; ?>>
                                Całkowite spożycie (suma)
                            </option>
                            <option value="spirits" <?php echo $alcoholType === 'spirits' ? 'selected' : ''; ?>>
                                Wyroby spirytusowe
                            </option>
                            <option value="wine" <?php echo $alcoholType === 'wine' ? 'selected' : ''; ?>>
                                Wino i miody pitne
                            </option>
                            <option value="beer" <?php echo $alcoholType === 'beer' ? 'selected' : ''; ?>>
                                Piwo
                            </option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="disease_code">Choroba:</label>
                        <select name="disease_code" id="disease_code">
                            <option value="all" <?php echo $diseaseCode === 'all' ? 'selected' : ''; ?>>
                                Wszystkie choroby (suma)
                            </option>
                            <?php foreach ($diseaseCodes as $code): ?>
                                <option value="<?php echo $code['disease_code']; ?>" 
                                    <?php echo $diseaseCode === $code['disease_code'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(substr($code['disease_name'], 0, 50)); ?>...
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn">Pokaż wykres</button>
            </form>
        </div>
        
        <!-- Wykres -->
        <div class="card">
            <h2>Analiza korelacji: Spożycie alkoholu vs Choroby</h2>
            <div class="chart-container">
                <canvas id="correlationChart"></canvas>
            </div>
            
            <div class="correlation-info">
                <h3>Interpretacja wykresu:</h3>
                <ul>
                    <li><strong>Oś lewa (niebieska):</strong> <?php echo $chartData['labels']['alcohol'] ?? 'Alkohol'; ?> - litry na osobę</li>
                    <li><strong>Oś prawa (czerwona):</strong> <?php echo $chartData['labels']['disease'] ?? 'Choroby'; ?> - liczba przypadków</li>
                    <li><strong>Oś X:</strong> Lata (<?php echo $yearFrom; ?> - <?php echo $yearTo; ?>)</li>
                </ul>
            </div>
        </div>
        
        <!-- Statystyki podsumowujące -->
        <div class="card">
            <h2>Statystyki podsumowujące</h2>
            <div class="stats-grid">
                <div class="stat-box">
                    <h3>Średnie spożycie alkoholu</h3>
                    <p class="number"><?php echo number_format(array_sum($chartData['alcohol']) / count($chartData['alcohol']), 2); ?> l/osobę</p>
                    <p>w wybranym okresie</p>
                </div>
                
                <div class="stat-box">
                    <h3>Średnia liczba przypadków</h3>
                    <p class="number"><?php echo number_format(array_sum($chartData['diseases']) / count($chartData['diseases']), 0); ?></p>
                    <p>choroby w wybranym okresie</p>
                </div>
                
                <div class="stat-box">
                    <h3>Zmiana spożycia</h3>
                    <?php 
                    $alcoholChange = end($chartData['alcohol']) - reset($chartData['alcohol']);
                    $alcoholChangePercent = (reset($chartData['alcohol']) != 0) ? 
                        ($alcoholChange / reset($chartData['alcohol'])) * 100 : 0;
                    ?>
                    <p class="number" style="color: <?php echo $alcoholChange > 0 ? '#dc3545' : '#28a745'; ?>">
                        <?php echo $alcoholChange > 0 ? '+' : ''; ?><?php echo number_format($alcoholChangePercent, 1); ?>%
                    </p>
                    <p>od <?php echo $yearFrom; ?> do <?php echo $yearTo; ?></p>
                </div>
                
                <div class="stat-box">
                    <h3>Zmiana zachorowań</h3>
                    <?php 
                    $diseaseChange = end($chartData['diseases']) - reset($chartData['diseases']);
                    $diseaseChangePercent = (reset($chartData['diseases']) != 0) ? 
                        ($diseaseChange / reset($chartData['diseases'])) * 100 : 0;
                    ?>
                    <p class="number" style="color: <?php echo $diseaseChange > 0 ? '#dc3545' : '#28a745'; ?>">
                        <?php echo $diseaseChange > 0 ? '+' : ''; ?><?php echo number_format($diseaseChangePercent, 1); ?>%
                    </p>
                    <p>od <?php echo $yearFrom; ?> do <?php echo $yearTo; ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Dane dla wykresu
    const chartData = <?php echo json_encode($chartData); ?>;
    
    // Konfiguracja wykresu
    const ctx = document.getElementById('correlationChart').getContext('2d');
    const correlationChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.years,
            datasets: [{
                label: chartData.labels.alcohol || 'Spożycie alkoholu (l/osobę)',
                data: chartData.alcohol,
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                yAxisID: 'y-alcohol',
                tension: 0.1
            }, {
                label: chartData.labels.disease || 'Liczba przypadków chorób',
                data: chartData.diseases,
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                yAxisID: 'y-disease',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'Korelacja między spożyciem alkoholu a zachorowaniami'
                }
            },
            scales: {
                x: {
                    display: true,
                    title: {
                        display: true,
                        text: 'Rok'
                    }
                },
                'y-alcohol': {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Spożycie alkoholu (l/osobę)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                },
                'y-disease': {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Liczba przypadków'
                    }
                }
            }
        }
    });
    </script>
</body>
</html>