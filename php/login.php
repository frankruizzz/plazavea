<?php
// php/login.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');


// CONFIGURACIÓN DE BASE DE DATOS
$host = 'localhost';
$dbname = 'plazavea';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
    exit();
}

// Configura la cookie de sesión para que sea válida en todo el servidor local
session_set_cookie_params([
    'path' => '/',
    'samesite' => 'Lax'
]);
session_start();

// OBTENER DATOS DEL FORMULARIO
$usuario = trim($_POST['usuario'] ?? '');
$password = $_POST['password'] ?? '';
$id_turno = intval($_POST['id_turno'] ?? 0);

// VALIDAR CAMPOS
if (empty($usuario) || empty($password) || empty($id_turno)) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos son obligatorios']);
    exit();
}

// BUSCAR EMPLEADO
try {
    $stmt = $pdo->prepare("SELECT id_empleado, nombres, apellido_paterno, password_hash 
                           FROM empleados 
                           WHERE usuario = :usuario");
    $stmt->execute(['usuario' => $usuario]);
    $empleado = $stmt->fetch(PDO::FETCH_ASSOC);

    // VALIDAR CREDENCIALES
    if (!$empleado) {
        echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
        exit();
    }

    if (!password_verify($password, $empleado['password_hash'])) {
        echo json_encode(['status' => 'error', 'message' => 'Contraseña incorrecta']);
        exit();
    }

    // VERIFICAR QUE EL TURNO EXISTA
    $stmt = $pdo->prepare("SELECT id_turno FROM turnos WHERE id_turno = :id_turno");
    $stmt->execute(['id_turno' => $id_turno]);
    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Turno no válido']);
        exit();
    }

    // ============================================
    // LOGIN EXITOSO - GUARDAR SESIÓN
    // ============================================
    $_SESSION['user_id'] = $empleado['id_empleado'];
    $_SESSION['user_nombre'] = $empleado['nombres'] . ' ' . $empleado['apellido_paterno'];
    $_SESSION['user_usuario'] = $usuario;
    $_SESSION['id_turno'] = $id_turno;
    $_SESSION['logged_in'] = true;
    $_SESSION['created'] = time();

    try {
        // Insertamos id_empleado e id_turno. 
        // id_log se genera solo (AUTO_INCREMENT) y fecha_ingreso toma el tiempo del servidor por defecto.
        $stmtLog = $pdo->prepare("INSERT INTO logs_ingreso (id_empleado, id_turno) VALUES (:id_empleado, :id_turno)");
        $stmtLog->execute([
            'id_empleado' => $empleado['id_empleado'],
            'id_turno' => $id_turno
        ]);
    } catch (PDOException $logError) {
        // Captura silenciosa para que un fallo en la tabla de logs no impida al cajero iniciar sesión
        error_log("Fallo al escribir en logs: " . $logError->getMessage());
    }

    // CREAR REGISTRO DE APERTURA DE CAJA
    /*$stmt = $pdo->prepare("INSERT INTO control_caja 
                          (id_empleado, id_turno, fecha_apertura, monto_inicial, estado)
                          VALUES (:empleado, :turno, NOW(), 0, 'Abierto')");
    $stmt->execute([
        'empleado' => $empleado['id_empleado'],
        'turno' => $id_turno
    ]);*/

    $caja_id = $pdo->lastInsertId();

    // RESPONDER CON ÉXITO
    echo json_encode([
        'status' => 'success',
        'message' => 'Login exitoso',
        'empleado' => $_SESSION['user_nombre'],
        'caja_id' => $caja_id,
        'user' => [
            'id' => $empleado['id_empleado'],
            'nombre' => $_SESSION['user_nombre']
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error en el servidor']);
}
?>