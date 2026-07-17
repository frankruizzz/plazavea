<?php
// api/clientes.php
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
} catch (PDOException $e) {
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

if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        $data = $_POST;
    }

    $action = $data['action'] ?? '';

    // ============================================
    // BUSCAR CLIENTE POR DNI O RUC
    // ============================================
    if ($action === 'buscar') {
        $id_tipo_documento = intval($data['id_tipo_documento'] ?? 0); // 1 = DNI, 2 = RUC
        $numero_documento = trim($data['numero_documento'] ?? '');

        if (!in_array($id_tipo_documento, [1, 2])) {
            echo json_encode(['success' => false, 'error' => 'Tipo de documento inválido']);
            exit();
        }

        if (empty($numero_documento)) {
            echo json_encode(['success' => false, 'error' => 'Ingrese un número de documento']);
            exit();
        }

        if ($id_tipo_documento === 1 && !preg_match('/^\d{8}$/', $numero_documento)) {
            echo json_encode(['success' => false, 'error' => 'El DNI debe tener 8 dígitos']);
            exit();
        }

        if ($id_tipo_documento === 2 && !preg_match('/^\d{11}$/', $numero_documento)) {
            echo json_encode(['success' => false, 'error' => 'El RUC debe tener 11 dígitos']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id_cliente, id_tipo_documento, numero_documento, 
                                          nombres, apellido_paterno, apellido_materno
                                   FROM clientes
                                   WHERE id_tipo_documento = :tipo AND numero_documento = :numero");
            $stmt->execute(['tipo' => $id_tipo_documento, 'numero' => $numero_documento]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cliente) {
                // Para RUC (empresa) se muestra solo razón social (nombres + apellido_paterno)
                // Para DNI (persona) se muestra el nombre completo
                $nombre_completo = $id_tipo_documento === 2
                    ? trim($cliente['nombres'] . ' ' . $cliente['apellido_paterno'])
                    : trim($cliente['nombres'] . ' ' . $cliente['apellido_paterno'] . ' ' . $cliente['apellido_materno']);

                echo json_encode([
                    'success' => true,
                    'encontrado' => true,
                    'cliente' => [
                        'id_cliente' => intval($cliente['id_cliente']),
                        'id_tipo_documento' => intval($cliente['id_tipo_documento']),
                        'numero_documento' => $cliente['numero_documento'],
                        'nombres' => $cliente['nombres'],
                        'apellido_paterno' => $cliente['apellido_paterno'],
                        'apellido_materno' => $cliente['apellido_materno'],
                        'nombre_completo' => $nombre_completo
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'encontrado' => false,
                    'error' => $id_tipo_documento === 2
                        ? 'RUC no encontrado en la base de datos'
                        : 'DNI no encontrado en la base de datos'
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
        exit();
    }

    // ============================================
    // REGISTRAR NUEVO CLIENTE (si no se encontró)
    // ============================================
    if ($action === 'crear') {
        $id_tipo_documento = intval($data['id_tipo_documento'] ?? 0);
        $numero_documento = trim($data['numero_documento'] ?? '');
        $nombreCompleto = trim($data['nombres'] ?? '');

        if (!in_array($id_tipo_documento, [1, 2])) {
            echo json_encode(['success' => false, 'error' => 'Tipo de documento inválido']);
            exit();
        }

        if (empty($numero_documento) || empty($nombreCompleto)) {
            echo json_encode(['success' => false, 'error' => 'Ingrese el número de documento y el nombre / razón social']);
            exit();
        }

        try {
            // Para RUC guardamos todo en "nombres" (razón social) y dejamos apellidos vacíos
            $stmt = $pdo->prepare("INSERT INTO clientes 
                                  (id_tipo_documento, numero_documento, nombres, apellido_paterno, apellido_materno)
                                  VALUES (:tipo, :numero, :nombres, :ap, :am)");
            $stmt->execute([
                'tipo' => $id_tipo_documento,
                'numero' => $numero_documento,
                'nombres' => $nombreCompleto,
                'ap' => $data['apellido_paterno'] ?? '',
                'am' => $data['apellido_materno'] ?? ''
            ]);

            echo json_encode([
                'success' => true,
                'id_cliente' => $pdo->lastInsertId(),
                'nombre_completo' => $nombreCompleto
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'No se pudo registrar el cliente (¿documento duplicado?): ' . $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['error' => 'Acción no válida']);
    exit();
}

echo json_encode(['error' => 'Método no permitido']);
exit();
?>