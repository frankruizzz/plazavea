<?php
// api/reportes.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
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

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    // ===== RESUMEN DEL DÍA =====
    if ($action === 'resumen_dia') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_ventas, 
                                      COALESCE(SUM(total_pagar), 0) as total_monto
                               FROM ventas 
                               WHERE DATE(fecha_emision) = CURDATE() 
                               AND id_empleado = :empleado");
        $stmt->execute(['empleado' => $_SESSION['user_id']]);
        $ventas = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT p.descripcion, SUM(dv.cantidad) as total_vendido
                              FROM detalle_ventas dv
                              JOIN ventas v ON dv.id_venta = v.id_venta
                              JOIN productos p ON dv.codigo_producto = p.codigo_producto
                              WHERE DATE(v.fecha_emision) = CURDATE()
                              AND v.id_empleado = :empleado
                              GROUP BY dv.codigo_producto
                              ORDER BY total_vendido DESC
                              LIMIT 5");
        $stmt->execute(['empleado' => $_SESSION['user_id']]);
        $top_productos = $stmt->fetchAll();

        // ===== MONTO DE APERTURA Y MONTO ACTUAL EN CAJA =====
        // Se toma la caja abierta del empleado (si existe). El monto actual
        // aumenta automáticamente porque ventas.php suma cada venta a
        // monto_final_sistema en la misma transacción de la venta.
        $stmt = $pdo->prepare("SELECT monto_inicial, monto_final_sistema 
                              FROM control_caja 
                              WHERE id_empleado = :empleado AND estado = 'Abierto'
                              ORDER BY id_control_caja DESC LIMIT 1");
        $stmt->execute(['empleado' => $_SESSION['user_id']]);
        $caja = $stmt->fetch();

        $monto_apertura = $caja ? floatval($caja['monto_inicial']) : 0;
        $monto_caja_actual = $caja ? floatval($caja['monto_inicial']) + floatval($caja['monto_final_sistema']) : 0;

        echo json_encode([
            'total_ventas' => intval($ventas['total_ventas'] ?? 0),
            'total_monto' => floatval($ventas['total_monto'] ?? 0),
            'top_productos' => $top_productos,
            'monto_apertura' => $monto_apertura,
            'monto_caja_actual' => $monto_caja_actual
        ]);
        exit();
    }

    // ===== VENTAS POR PERÍODO =====
    if ($action === 'ventas_periodo') {
        $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
        $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("SELECT v.*, c.nombres as cliente_nombre,
                                      mp.nombre_metodo as metodo_pago
                               FROM ventas v
                               LEFT JOIN clientes c ON v.id_cliente = c.id_cliente
                               LEFT JOIN metodos_pago mp ON v.id_metodo_pago = mp.id_metodo_pago
                               WHERE DATE(v.fecha_emision) BETWEEN :inicio AND :fin
                               AND v.id_empleado = :empleado
                               ORDER BY v.fecha_emision DESC");
        $stmt->execute([
            'inicio' => $fecha_inicio,
            'fin' => $fecha_fin,
            'empleado' => $_SESSION['user_id']
        ]);
        $ventas = $stmt->fetchAll();

        echo json_encode($ventas);
        exit();
    }

    echo json_encode(['error' => 'Acción no válida: ' . $action]);
    exit();
}

echo json_encode(['error' => 'Método no permitido']);
exit();
?>