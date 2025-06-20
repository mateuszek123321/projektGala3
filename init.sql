-- Tworzenie bazy danych
CREATE DATABASE IF NOT EXISTS integracja_systemow;
USE integracja_systemow;

-- Tabela użytkowników
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela danych o spożyciu alkoholu
CREATE TABLE IF NOT EXISTS alcohol_consumption (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    spirits_100_alcohol DECIMAL(10, 2),
    wine_mead DECIMAL(10, 2),
    wine_mead_100_alcohol DECIMAL(10, 2),
    beer DECIMAL(10, 2),
    beer_100_alcohol DECIMAL(10, 2),
    UNIQUE KEY unique_year (year)
);

-- Tabela słownika chorób
CREATE TABLE IF NOT EXISTS disease_dictionary (
    disease_code VARCHAR(20) PRIMARY KEY,
    disease_name_pl VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Wypełnienie słownika chorób
INSERT INTO disease_dictionary (disease_code, disease_name_pl, category) VALUES
('E24.4', 'Zespół pseudo-Cushinga u alkoholików', 'Choroby endokrynologiczne'),
('F10', 'Zaburzenia psychiczne i zaburzenia zachowania spowodowane użyciem alkoholu', 'Zaburzenia psychiczne'),
('G31.2', 'Zwyrodnienie układu nerwowego wywołane przez alkohol', 'Choroby układu nerwowego'),
('G62.1', 'Polineuropatia alkoholowa', 'Choroby układu nerwowego'),
('G72.1', 'Miopatia alkoholowa', 'Choroby układu nerwowego'),
('I42.6', 'Kardiomiopatia alkoholowa', 'Choroby układu krążenia'),
('K29.2', 'Alkoholowe zapalenie żołądka', 'Choroby układu pokarmowego'),
('K70', 'Alkoholowa choroba wątroby', 'Choroby układu pokarmowego'),
('K73', 'Przewlekłe zapalenie wątroby niesklasyfikowane gdzie indziej', 'Choroby układu pokarmowego'),
('K74.0', 'Zwłóknienie wątroby', 'Choroby układu pokarmowego'),
('K74.1', 'Stwardnienie wątroby', 'Choroby układu pokarmowego'),
('K74.2', 'Zwłóknienie wątroby ze stwardnieniem wątroby', 'Choroby układu pokarmowego'),
('K74.6', 'Inna i nieokreślona marskość wątroby', 'Choroby układu pokarmowego'),
('K85.2', 'Alkoholowe ostre zapalenie trzustki', 'Choroby układu pokarmowego'),
('K86.0', 'Alkoholowe przewlekłe zapalenie trzustki', 'Choroby układu pokarmowego'),
('Q86.0', 'Płodowy zespół alkoholowy (dysmorficzny)', 'Wady wrodzone'),
('R78.0', 'Stwierdzenie obecności alkoholu we krwi', 'Objawy i nieprawidłowe wyniki badań')
AS new_values
ON DUPLICATE KEY UPDATE 
    disease_name_pl = VALUES(disease_name_pl),
    category = VALUES(category);

-- Tabela danych o chorobach
CREATE TABLE IF NOT EXISTS diseases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    disease_code VARCHAR(20) NOT NULL,
    disease_name VARCHAR(255) NOT NULL,
    province VARCHAR(100),
    year INT NOT NULL,
    outpatient_count INT,
    FOREIGN KEY (disease_code) REFERENCES disease_dictionary(disease_code),
    INDEX idx_year (year),
    INDEX idx_disease_code (disease_code)
);

-- Tabela logów importu/eksportu
CREATE TABLE IF NOT EXISTS data_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    file_type VARCHAR(20) NOT NULL,
    file_name VARCHAR(255),
    records_count INT,
    status VARCHAR(20) NOT NULL,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Widok łączący dane chorób z nazwami ze słownika
CREATE OR REPLACE VIEW diseases_full_view AS
SELECT 
    d.id,
    d.disease_code,
    dd.disease_name_pl,
    dd.category,
    d.province,
    d.year,
    d.outpatient_count,
    d.outpatient_count AS total_count
FROM diseases d
JOIN disease_dictionary dd ON d.disease_code = dd.disease_code
ORDER BY d.year DESC, dd.category, dd.disease_code;

-- Widok statystyk chorób
CREATE OR REPLACE VIEW disease_statistics AS
SELECT 
    dd.disease_code,
    dd.disease_name_pl,
    dd.category,
    COUNT(DISTINCT d.year) as years_count,
    MIN(d.year) as first_year,
    MAX(d.year) as last_year,
    SUM(d.outpatient_count) as total_outpatient
FROM disease_dictionary dd
LEFT JOIN diseases d ON dd.disease_code = d.disease_code
GROUP BY dd.disease_code, dd.disease_name_pl, dd.category;

-- Przykładowe dane testowe dla użytkownika
INSERT INTO users (username, email, password) VALUES 
('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi') -- hasło: password
AS new_user
ON DUPLICATE KEY UPDATE username = new_user.username;