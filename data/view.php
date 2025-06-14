<?php
require_once '../config/database.php';
require_once '../models/AlcoholConsumption.php';
require_once '../models/Disease.php';

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Pobieranie parametrów filtrowania
$dataType = $_GET['type'] ?? 'alcohol';
$yearFrom = $_GET['year_from'] ?? 2020;
$yearTo = $_GET['year_to'] ?? 2023;

// Pobieranie danych
if ($dataType === 'alcohol') {
    $data = AlcoholConsumption::getByYearRange($yearFrom, $yearTo);
} else {
    // Dla chorób pobieramy unikalne kody
    $diseaseCodes = Disease::getUniqueCodes();
    $selectedCode = $_GET['disease_code'] ?? ($diseaseCodes[0]['disease_code'] ?? '');
    
    if ($selectedCode) {
        $data = Disease::getByCodeAndYearRange($selectedCode, $yearFrom, $yearTo);
    } else {
        $data = [];
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Przeglądanie danych - System Integracji Danych</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="header">
        <h1>Przeglądanie danych</h1>
        <a href="../index.php">← Powrót do panelu</a>
    </div>
    
    <div class="container">
        <!-- Formularz filtrowania -->
        <div class="card">
            <h2>Filtry</h2>
            <form method="GET" action="">
                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                    <div class="form-group">
                        <label for="type">Typ danych:</label>
                        <select name="type" id="type" onchange="this.form.submit()">
                            <option value="alcohol" <?php echo $dataType === 'alcohol' ? 'selected' : ''; ?>>
                                Spożycie alkoholu
                            </option>
                            <option value="diseases" <?php echo $dataType === 'diseases' ? 'selected' : ''; ?>>
                                Choroby
                            </option>
                        </select>
                    </div>
                    
                    <?php if ($dataType === 'diseases'): ?>
                    <div class="form-group">
                        <label for="disease_code">Kod choroby:</label>
                        <select name="disease_code" id="disease_code">
                            <?php foreach ($diseaseCodes as $code): ?>
                                <option value="<?php echo $code['disease_code']; ?>" 
                                    <?php echo $selectedCode === $code['disease_code'] ? 'selected' : ''; ?>>
                                    <?php echo $code['disease_code']; ?> - 
                                    <?php echo htmlspecialchars(substr($code['disease_name'], 0, 50)); ?>...
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
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
                    
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn">Filtruj</button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Wyświetlanie danych -->
        <div class="card">
            <h2>
                <?php echo $dataType === 'alcohol' ? 'Dane o spożyciu alkoholu' : 'Dane o chorobach'; ?>
                (<?php echo $yearFrom; ?> - <?php echo $yearTo; ?>)
            </h2>
            
            <?php if (empty($data)): ?>
                <p>Brak danych dla wybranych kryteriów.</p>
            <?php else: ?>
                <?php if ($dataType === 'alcohol'): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Rok</th>
                                <th>Spirytus 100%</th>
                                <th>Wino i miody</th>
                                <th>Wino i miody 100%</th>
                                <th>Piwo</th>
                                <th>Piwo 100%</th>
                                <th>Razem 100%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $record): ?>
                            <tr>
                                <td><?php echo $record->year; ?></td>
                                <td><?php echo number_format($record->spirits_100_alcohol ?? 0, 2); ?> l</td>
                                <td><?php echo number_format($record->wine_mead ?? 0, 2); ?> l</td>
                                <td><?php echo number_format($record->wine_mead_100_alcohol ?? 0, 2); ?> l</td>
                                <td><?php echo number_format($record->beer ?? 0, 2); ?> l</td>
                                <td><?php echo number_format($record->beer_100_alcohol ?? 0, 2); ?> l</td>
                                <td><strong><?php echo number_format($record->getTotalAlcohol100(), 2); ?> l</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Rok</th>
                                <th>Kod choroby</th>
                                <th>Nazwa choroby</th>
                                <th>Województwo</th>
                                <th>Ambulatoryjne</th>
                                <th>Szpitalne</th>
                                <th>SOR</th>
                                <th>Izba przyjęć</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $record): ?>
                            <tr>
                                <td><?php echo $record->year; ?></td>
                                <td><?php echo htmlspecialchars($record->disease_code); ?></td>
                                <td><?php echo htmlspecialchars($record->disease_name); ?></td>
                                <td><?php echo htmlspecialchars($record->province); ?></td>
                                <td><?php echo number_format($record->outpatient_count ?? 0); ?></td>
                                <td><?php echo number_format($record->hospital_count ?? 0); ?></td>
                                <td><?php echo number_format($record->emergency_count ?? 0); ?></td>
                                <td><?php echo number_format($record->admission_count ?? 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>