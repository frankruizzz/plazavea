<?php
// api/caja.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// CONFIGURACIÓN
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

session_start();

// VERIFICAR SESIÓN
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================
// POST - ABRIR O CERRAR CAJA
// ============================================
if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $action = $data['action'] ?? '';

    // ===== ABRIR CAJA =====
    if ($action === 'abrir') {
        $monto_inicial = floatval($data['monto_inicial'] ?? 0);

        if ($monto_inicial < 0) {
            echo json_encode(['error' => 'El monto no puede ser negativo']);
            exit();
        }

        // Verificar si ya tiene caja abierta
        $stmt = $pdo->prepare("SELECT id_control_caja FROM control_caja 
                              WHERE id_empleado = :empleado AND estado = 'Abierto'");
        $stmt->execute(['empleado' => $_SESSION['user_id']]);
        if ($stmt->fetch()) {
            echo json_encode(['error' => 'Ya tiene una caja abierta']);
            exit();
        }

        // Obtener turno actual
        $stmt = $pdo->prepare("SELECT id_turno FROM turnos 
                              WHERE TIME(NOW()) BETWEEN hora_inicio AND hora_fin");
        $stmt->execute();
        $turno = $stmt->fetch();
        $id_turno = $turno['id_turno'] ?? 1;

        $stmt = $pdo->prepare("INSERT INTO control_caja 
                              (id_empleado, id_turno, fecha_apertura, monto_inicial, estado)
                              VALUES (:empleado, :turno, NOW(), :monto, 'Abierto')");
        $stmt->execute([
            'empleado' => $_SESSION['user_id'],
            'turno' => $id_turno,
            'monto' => $monto_inicial
        ]);

        echo json_encode([
            'success' => true,
            'caja_id' => $pdo->lastInsertId(),
            'monto_inicial' => $monto_inicial
        ]);
        exit();
    }

    // ===== CERRAR CAJA =====
    if ($action === 'cerrar') {
        $monto_real = floatval($data['monto_real'] ?? 0);

        if ($monto_real < 0) {
            echo json_encode(['error' => 'El monto no puede ser negativo']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT id_control_caja, monto_inicial, monto_final_sistema 
                              FROM control_caja 
                              WHERE id_empleado = :empleado AND estado = 'Abierto'");
        $stmt->execute(['empleado' => $_SESSION['user_id']]);
        $caja = $stmt->fetch();

        if (!$caja) {
            echo json_encode(['error' => 'No hay caja abierta']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE control_caja 
                              SET fecha_cierre = NOW(), 
                                  monto_final_real = :monto_real,
                                  estado = 'Cerrado'
                              WHERE id_control_caja = :caja");
        $stmt->execute([
            'monto_real' => $monto_real,
            'caja' => $caja['id_control_caja']
        ]);

        $monto_sistema = floatval($caja['monto_inicial']) + floatval($caja['monto_final_sistema']);

        echo json_encode([
            'success' => true,
            'monto_sistema' => $monto_sistema,
            'monto_real' => $monto_real,
            'diferencia' => $monto_real - $monto_sistema
        ]);
        exit();
    }

    echo json_encode(['error' => 'Acción no válida: ' . $action]);
    exit();
}

// ============================================
// GET - ESTADO DE CAJA
// ============================================
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT c.*, t.nombre_turno
                          FROM control_caja c
                          LEFT JOIN turnos t ON c.id_turno = t.id_turno
                          WHERE c.id_empleado = :empleado 
                          ORDER BY c.id_control_caja DESC
                          LIMIT 1");
    $stmt->execute(['empleado' => $_SESSION['user_id']]);
    $caja = $stmt->fetch();

    if ($caja) {
        if ($caja['estado'] === 'Cerrado') {
            echo json_encode([
                'estado' => 'Cerrado',
                'fecha_apertura' => $caja['fecha_apertura'],
                'fecha_cierre' => $caja['fecha_cierre'],
                'monto_final' => floatval($caja['monto_final_real'])
            ]);
        } else {
            echo json_encode([
                'estado' => 'Abierto',
                'caja_id' => $caja['id_control_caja'],
                'monto_inicial' => floatval($caja['monto_inicial']),
                'monto_actual' => floatval($caja['monto_inicial'] + $caja['monto_final_sistema']),
                'fecha_apertura' => $caja['fecha_apertura'],
                'turno' => $caja['nombre_turno'] ?? 'No asignado'
            ]);
        }
    } else {
        echo json_encode(['estado' => 'Sin_caja']);
    }
    exit();
}

echo json_encode(['error' => 'Método no permitido']);
exit();
?>