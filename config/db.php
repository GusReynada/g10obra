<?php
/**
 * Database Configuration & Connection
 * Compatible with Localhost (XAMPP / Laragon / Wamp) and Hostinger cPanel / hPanel
 */

// Hostinger / Local Database Credentials
// Actualiza estos valores con los que te proporcione Hostinger en su panel MySQL:
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'g10obra_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

class Database {
    private static ?PDO $instance = null;
    private static ?string $connectionError = null;

    /**
     * Obtiene la instancia singleton de PDO
     */
    public static function getConnection(): ?PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                self::$connectionError = $e->getMessage();
                // En producción no se recomienda imprimir el error directamente por seguridad
                error_log("DB Connection Error: " . $e->getMessage());
                return null;
            }
        }
        return self::$instance;
    }

    /**
     * Retorna el estado de la conexión para diagnósticos
     */
    public static function checkStatus(): array {
        $conn = self::getConnection();
        if ($conn !== null) {
            return [
                'success' => true,
                'message' => 'Conexión a MySQL establecida con éxito.',
                'database' => DB_NAME,
                'host' => DB_HOST
            ];
        }

        return [
            'success' => false,
            'message' => 'No se pudo conectar a la base de datos.',
            'error' => self::$connectionError ?? 'Error desconocido',
            'host' => DB_HOST,
            'database' => DB_NAME
        ];
    }
}
