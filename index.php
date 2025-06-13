<?php
require_once 'config/database.php';

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Obsługa wylogowania
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Integracji Danych - Panel Główny</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
        }
        
        .header {
            background-color: #333;
            color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 1.5rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logout-btn {
            background-color: #dc3545;
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .logout-btn:hover {
            background-color: #c82333;
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .welcome-section {
            background-color: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .feature-card {
            background-color: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
        }
        
        .feature-card h3 {
            color: #333;
            margin-bottom: 1rem;
        }
        
        .feature-card p {
            color: #666;
            margin-bottom: 1rem;
        }
        
        .feature-btn {
            display: inline-block;
            background-color: #4CAF50;
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .feature-btn:hover {
            background-color: #45a049;
        }
        
        .status-info {
            background-color: #e9ecef;
            padding: 1rem;
            border-radius: 4px;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>System Integracji Danych</h1>
        <div class="user-info">
            <span>Zalogowany jako: <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="?logout=1" class="logout-btn">Wyloguj</a>
        </div>
    </div>
    
    <div class="container">
        <div class="welcome-section">
            <h2>Witaj w systemie integracji danych!</h2>
            <p>Ten system umożliwia integrację danych dotyczących spożycia alkoholu oraz występowania chorób w Polsce.</p>
        </div>
        
        <div class="features-grid">
            <div class="feature-card">
                <h3>Import danych XML</h3>
                <p>Importuj dane o spożyciu alkoholu oraz chorobach z plików XML.</p>
                <a href="importy/importXML.php" class="feature-btn">Importuj XML</a>
            </div>
            
            <div class="feature-card">
                <h3>Import danych JSON</h3>
                <p>Importuj dane o spożyciu alkoholu oraz chorobach z plików JSON.</p>
                <a href="importy/importJSON.php" class="feature-btn">Importuj JSON</a>
            </div>
            
            <div class="feature-card">
                <h3>Eksport danych XML</h3>
                <p>Eksportuj zgromadzone dane do formatu XML.</p>
                <a href="exporty/exportXML.php" class="feature-btn">Eksportuj XML</a>
            </div>
            
            <div class="feature-card">
                <h3>Eksport danych JSON</h3>
                <p>Eksportuj zgromadzone dane do formatu JSON.</p>
                <a href="exporty/exportJSON.php" class="feature-btn">Eksportuj JSON</a>
            </div>
            
            <div class="feature-card">
                <h3>Przeglądaj dane</h3>
                <p>Przeglądaj i analizuj zaimportowane dane.</p>
                <a href="data/view.php" class="feature-btn">Przeglądaj</a>
            </div>
            
            <div class="feature-card">
                <h3>Statystyki</h3>
                <p>Zobacz statystyki i wykresy na podstawie zgromadzonych danych.</p>
                <a href="data/stats.php" class="feature-btn">Statystyki</a>
            </div>
        </div>
        
        <div class="status-info">
            <h3>Status systemu</h3>
            <p>Moduły dostępne: Import/Export XML, Import/Export JSON</p>
            <p>Baza danych: MySQL</p>
        </div>
    </div>
</body>
</html>