<?php
// api/auth.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Configura la cookie de sesión para que sea válida en todo el servidor local
require_once 'db.php'; 

// 2. CONFIGURAMOS LAS COOKIES DE SESIÓN PARA EL DOMINIO LOCAL
session_set_cookie_params([
    'path' => '/',
    'samesite' => 'Lax'
]);

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $action = $data['action'] ?? '';

    // ===== VERIFICAR SESIÓN =====
    if ($action === 'verify') {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            exit();
        }

        if (time() - $_SESSION['created'] > 28800) {
            session_destroy();
            http_response_code(401);
            echo json_encode(['error' => 'Sesión expirada']);
            exit();
        }

        echo json_encode([
            'success' => true,
            'user' => ['nombre' => $_SESSION['user_nombre']]
        ]);
        exit();
    }

    // ===== LOGOUT =====
    if ($action === 'logout') {
        // 1. Extraemos los datos de la sesión antes de destruirla
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            $id_empleado = $_SESSION['user_id'] ?? null;
            $id_turno = $_SESSION['id_turno'] ?? null;

            // 2. Si hay datos válidos del cajero, insertamos en la tabla de logs_salida
            if ($id_empleado && $id_turno) {
                try {
                    $stmtLog = $pdo->prepare("INSERT INTO logs_salida (id_empleado, id_turno) VALUES (:id_empleado, :id_turno)");
                    $stmtLog->execute([
                        'id_empleado' => $id_empleado,
                        'id_turno' => $id_turno
                    ]);
                } catch (PDOException $logError) {
                    // Evitamos que un fallo al guardar el log detenga el logout del cajero
                    echo json_encode(['error' => 'Error SQL: ' . $logError->getMessage()]);
                    exit();
                    //error_log("No se pudo registrar logs_salida: " . $logError->getMessage());
                }
            }
        }

        // 3. Finalmente destruimos la sesión y retornamos éxito
        session_destroy();
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['error' => 'Acción no válida']);
    exit();
}

echo json_encode(['error' => 'Método no permitido']);
exit();
?>