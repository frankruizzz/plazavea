<?php
// api/empleados.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$host = 'localhost';
$dbname = 'plazavea';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Error de conexión a la base de datos: ' . $e->getMessage()]);
    exit();
}

session_set_cookie_params(['path' => '/', 'samesite' => 'Lax']);
session_start();

// ====== CAPA DE SEGURIDAD ======
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Acceso denegado. Se requieren privilegios de Administrador.']);
    exit();
}

// ====== ACCIÓN: LISTAR EMPLEADOS (GET) ======
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'listar') {
        try {
            // Seleccionamos las columnas reales según tu esquema de base de datos
            $stmt = $pdo->query("SELECT id_empleado, nombres, apellido_paterno, apellido_materno, usuario, id_turno FROM empleados ORDER BY id_empleado DESC");
            $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($empleados);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al listar empleados: ' . $e->getMessage()]);
        }
        exit();
    }
}

// ====== ACCIÓN: CREAR EMPLEADO (POST) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?? $_POST;
    
    $action = $data['action'] ?? '';

    if ($action === 'crear') {
        $nombres = $data['nombres'] ?? '';
        $apellido_paterno = $data['apellido_paterno'] ?? '';
        $apellido_materno = $data['apellido_materno'] ?? '';
        $usuario = $data['usuario'] ?? '';
        $clave = $data['clave'] ?? '';
        $id_turno = intval($data['id_turno'] ?? 1);

        // Validaciones con el nuevo esquema
        if (empty($nombres) || empty($apellido_paterno) || empty($apellido_materno) || empty($usuario) || empty($clave)) {
            echo json_encode(['error' => 'Todos los campos son obligatorios.']);
            exit();
        }

        try {
            // Validar unicidad de usuario
            $stmtCheck = $pdo->prepare("SELECT id_empleado FROM empleados WHERE usuario = :usuario LIMIT 1");
            $stmtCheck->execute(['usuario' => $usuario]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['error' => 'El nombre de usuario ya se encuentra registrado.']);
                exit();
            }

            // Encriptamos la clave usando Bcrypt para guardarla en password_hash
            $claveHash = password_hash($clave, PASSWORD_BCRYPT);

            // Inserción limpia con las columnas reales de tu tabla
            $sql = "INSERT INTO empleados (nombres, apellido_paterno, apellido_materno, usuario, password_hash, id_turno) 
                    VALUES (:nombres, :apellido_paterno, :apellido_materno, :usuario, :password_hash, :id_turno)";
            
            $stmtInsert = $pdo->prepare($sql);
            $stmtInsert->execute([
                'nombres' => $nombres,
                'apellido_paterno' => $apellido_paterno,
                'apellido_materno' => $apellido_materno,
                'usuario' => $usuario,
                'password_hash' => $claveHash,
                'id_turno' => $id_turno
            ]);

            echo json_encode(['success' => true]);
            
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Error al guardar el cajero: ' . $e->getMessage()]);
        }
        exit();
    }
}

echo json_encode(['error' => 'Operación no permitida.']);
exit();
?>