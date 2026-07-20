<?php
// api/auth.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php'; 

session_set_cookie_params([
    'path' => '/',
    'samesite' => 'Lax'
]);

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?? $_POST;
    
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

    // ===== LOGOUT (CONTROL DE SALIDAS CORREGIDO) =====
    if ($action === 'logout') {
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            
            // 1. Buscamos el ID del empleado usando variantes comunes por seguridad
            $id_empleado = $_SESSION['user_id'] ?? $_SESSION['id_empleado'] ?? null;
            
            // 2. Buscamos el Turno usando variantes comunes (ej. si en el login pusiste 'turno' o 'id_turno')
            $id_turno = $_SESSION['id_turno'] ?? $_SESSION['turno'] ?? null;

            // 3. CAPA DE DIAGNÓSTICO: Si falta alguno, exponemos la sesión en DevTools para corregir el Login
            if (empty($id_empleado) || empty($id_turno)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'No se pudo registrar la salida porque faltan datos esenciales en la sesión.',
                    'ayuda' => 'Verifica cómo guardas las variables en tu script de inicio de sesión.',
                    'datos_actuales_en_sesion' => $_SESSION
                ]);
                exit();
            }

            // 4. Inserción limpia en la base de datos real
            try {
                $stmtLog = $pdo->prepare("INSERT INTO logs_salida (id_empleado, id_turno) VALUES (:id_empleado, :id_turno)");
                $stmtLog->execute([
                    'id_empleado' => $id_empleado,
                    'id_turno' => $id_turno
                ]);
            } catch (PDOException $logError) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Fallo crítico de base de datos al insertar en logs_salida.',
                    'sql_error' => $logError->getMessage()
                ]);
                exit();
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'No existe una sesión activa para cerrar.']);
            exit();
        }

        // 5. Si el flujo se completó e insertó con éxito, destruimos la sesión de forma segura
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