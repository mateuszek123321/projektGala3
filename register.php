<?php
session_start();
require_once 'config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    // Walidacja
    if (empty($username) || empty($email) || empty($password) || empty($password_confirm)) {
        $error = 'Proszę wypełnić wszystkie pola.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Proszę podać prawidłowy adres email.';
    } elseif (strlen($password) < 6) {
        $error = 'Hasło musi mieć co najmniej 6 znaków.';
    } elseif ($password !== $password_confirm) {
        $error = 'Hasła nie są identyczne.';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Sprawdzenie czy użytkownik już istnieje
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $error = 'Użytkownik o podanej nazwie lub emailu już istnieje.';
            } else {
                // Dodanie nowego użytkownika
                $hashedPassword = hashPassword($password);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                $stmt->execute([$username, $email, $hashedPassword]);
                
                $success = 'Rejestracja zakończona sukcesem! Możesz się teraz zalogować.';
                
                // Wyczyść pola formularza
                $username = '';
                $email = '';
            }
        } catch (PDOException $e) {
            $error = 'Wystąpił błąd podczas rejestracji.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja - System Integracji Danych</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .register-container {
            background-color: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }
        
        h2 {
            text-align: center;
            margin-bottom: 1.5rem;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #555;
            font-weight: bold;
        }
        
        input[type="text"],
        input[type="password"],
        input[type="email"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus,
        input[type="email"]:focus {
            outline: none;
            border-color: #4CAF50;
        }
        
        .btn {
            width: 100%;
            padding: 0.75rem;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: #45a049;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .link {
            text-align: center;
            margin-top: 1rem;
        }
        
        .link a {
            color: #4CAF50;
            text-decoration: none;
        }
        
        .link a:hover {
            text-decoration: underline;
        }
        
        .password-hint {
            font-size: 0.875rem;
            color: #666;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2>Rejestracja nowego użytkownika</h2>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Nazwa użytkownika:</label>
                <input type="text" id="username" name="username" required 
                       value="<?php echo htmlspecialchars($username ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="email">Adres email:</label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo htmlspecialchars($email ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Hasło:</label>
                <input type="password" id="password" name="password" required>
                <div class="password-hint">Minimum 6 znaków</div>
            </div>
            
            <div class="form-group">
                <label for="password_confirm">Potwierdź hasło:</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>
            
            <button type="submit" class="btn">Zarejestruj się</button>
        </form>
        
        <div class="link">
            <p>Masz już konto? <a href="login.php">Zaloguj się</a></p>
        </div>
    </div>
</body>
</html>