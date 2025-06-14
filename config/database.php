<?php
// Konfiguracja bazy danych
// Dla Dockera użyj 'db' jako host, dla XAMPP użyj 'localhost'
$isDocker = getenv('DOCKER_ENV') !== false || file_exists('/.dockerenv');

define('DB_HOST', $isDocker ? 'db' : 'localhost');
define('DB_NAME', 'integracja_systemow');
define('DB_USER', $isDocker ? 'user' : 'root');
define('DB_PASS', $isDocker ? 'password' : '');
define('DB_CHARSET', 'utf8mb4');

// Funkcja do nawiązania połączenia z bazą danych
function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die("Błąd połączenia z bazą danych: " . $e->getMessage());
    }
}

// Funkcja do hashowania hasła
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Funkcja do weryfikacji hasła
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Rozpoczęcie sesji jeśli nie została rozpoczęta
if (session_status() === PHP_SESSION_NONE) {
    @session_start(); // @ tłumi warning jeśli sesja już była rozpoczęta gdzie indziej
}

// Automatyczne ładowanie klas modeli
spl_autoload_register(function ($class) {
    $modelPath = __DIR__ . '/../models/' . $class . '.php';
    if (file_exists($modelPath)) {
        require_once $modelPath;
    }
});
?>