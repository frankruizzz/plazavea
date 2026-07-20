<?php
// api/auth_admin.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$host = 'localhost';
$dbname = 'plazavea';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]);
    exit();
}

session_set_cookie_params(['path' => '/', 'samesite' => 'Lax']);
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?? $_POST;
    
    $action = $data['action'] ?? '';

    // ===== LOGIN ADMINISTRADOR =====
    if ($action === 'login') {
        $usuario = $data['usuario'] ?? '';
        $clave = $data['clave'] ?? '';

        if (empty($usuario) || empty($clave)) {
            echo json_encode(['error' => 'Por favor, complete todos los campos']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM administradores WHERE usuario = :usuario LIMIT 1");
        $stmt->execute(['usuario' => $usuario]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($clave, $admin['clave'])) {
            // Guardamos datos en variables de sesión únicas para administración
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id_admin'];
            $_SESSION['admin_nombre'] = $admin['nombre'];
            $_SESSION['admin_created'] = time();

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Usuario o contraseña de administrador incorrectos']);
        }
        exit();
    }

    // ===== VERIFICAR SESIÓN ADMIN =====
    if ($action === 'verify') {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            exit();
        }
        // Expiración por inactividad (8 horas)
        if (time() - $_SESSION['admin_created'] > 28800) {
            session_destroy();
            http_response_code(401);
            echo json_encode(['error' => 'Sesión de administración expirada']);
            exit();
        }

        echo json_encode([
            'success' => true,
            'user' => ['nombre' => $_SESSION['admin_nombre']]
        ]);
        exit();
    }

    // ===== LOGOUT ADMIN =====
    if ($action === 'logout') {
        unset($_SESSION['admin_logged_in']);
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_nombre']);
        unset($_SESSION['admin_created']);
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['error' => 'Acción no válida']);
    exit();
}

echo json_encode(['error' => 'Método no permitido']);
exit();
?>