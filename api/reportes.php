<?php
// api/reportes.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

session_start();

// VERIFICAR SESIÓN (Permite el paso tanto a cajeros como a administradores de Plaza Vea)
if ((!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) && 
    (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true)) {
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    // =====================================================
    // ===== RESUMEN DEL DÍA (Módulo del Cajero) =====
    // =====================================================
    if ($action === 'resumen_dia') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_ventas, 
                                      COALESCE(SUM(total_pagar), 0) as total_monto
                               FROM ventas 
                               WHERE DATE(fecha_emision) = CURDATE() 
                               AND id_empleado = :empleado");
        $stmt->execute(['empleado' => $_SESSION['user_id']]);
        $ventas = $stmt->fetch(PDO::FETCH_ASSOC);

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
        $top_productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ===== MONTO DE APERTURA Y MONTO ACTUAL EN CAJA =====
        $stmt = $pdo->prepare("SELECT monto_inicial, monto_final_sistema 
                              FROM control_caja 
                              WHERE id_empleado = :empleado AND estado = 'Abierto'
                              ORDER BY id_control_caja DESC LIMIT 1");
        $stmt->execute(['empleado' => $_SESSION['user_id']]);
        $caja = $stmt->fetch(PDO::FETCH_ASSOC);

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

    // =====================================================
    // ===== VENTAS POR PERÍODO =====
    // =====================================================
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
        $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($ventas);
        exit();
    }

    // =====================================================
    // ===== LOGS DE AUDITORÍA (Módulo del Admin) ======
    // =====================================================
    if ($action === 'logs_auditoria') {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            http_response_code(403);
            echo json_encode(['error' => 'Acceso denegado. Requiere privilegios de Administrador.']);
            exit();
        }

        $fecha = $_GET['fecha'] ?? '';
        $evento = $_GET['evento'] ?? 'TODOS'; 
        $buscar = $_GET['buscar'] ?? '';

        $queries = [];
        $params = [];

        // 1. Consulta corregida apuntando a 'logs_ingreso'
        if ($evento === 'TODOS' || $evento === 'INGRESO') {
            $sqlIngreso = "SELECT 
                            l.id_log, 
                            l.id_empleado, 
                            CONCAT(e.nombres, ' ', e.apellido_paterno, ' ', e.apellido_materno) AS empleado_nombre,
                            'INGRESO' AS tipo_evento, 
                            l.id_turno, 
                            l.fecha_ingreso AS fecha_evento
                           FROM logs_ingreso l
                           JOIN empleados e ON l.id_empleado = e.id_empleado 
                           WHERE 1=1";
            
            if (!empty($fecha)) {
                $sqlIngreso .= " AND DATE(l.fecha_ingreso) = :fecha_ing";
                $params['fecha_ing'] = $fecha;
            }
            if (!empty($buscar)) {
                $sqlIngreso .= " AND (CONCAT(e.nombres, ' ', e.apellido_paterno, ' ', e.apellido_materno) LIKE :buscar_ing OR l.id_empleado = :buscar_id_ing)";
                $params['buscar_ing'] = "%$buscar%";
                $params['buscar_id_ing'] = $buscar;
            }
            $queries[] = $sqlIngreso;
        }

        // 2. Consulta apuntando a 'logs_salida'
        if ($evento === 'TODOS' || $evento === 'SALIDA') {
            $sqlSalida = "SELECT 
                            s.id_log, 
                            s.id_empleado, 
                            CONCAT(e.nombres, ' ', e.apellido_paterno, ' ', e.apellido_materno) AS empleado_nombre,
                            'SALIDA' AS tipo_evento, 
                            s.id_turno, 
                            s.fecha_salida AS fecha_evento
                          FROM logs_salida s
                          JOIN empleados e ON s.id_empleado = e.id_empleado 
                          WHERE 1=1";
            
            if (!empty($fecha)) {
                $sqlSalida .= " AND DATE(s.fecha_salida) = :fecha_sal";
                $params['fecha_sal'] = $fecha;
            }
            if (!empty($buscar)) {
                $sqlSalida .= " AND (CONCAT(e.nombres, ' ', e.apellido_paterno, ' ', e.apellido_materno) LIKE :buscar_sal OR s.id_empleado = :buscar_id_sal)";
                $params['buscar_sal'] = "%$buscar%";
                $params['buscar_id_sal'] = $buscar;
            }
            $queries[] = $sqlSalida;
        }

        if (empty($queries)) {
            echo json_encode([]);
            exit();
        }

        try {
            // Combinación cronológica unificada
            $finalSql = implode(" UNION ALL ", $queries) . " ORDER BY fecha_evento DESC";
            
            $stmt = $pdo->prepare($finalSql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($logs);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error en la consulta de auditoría: ' . $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['error' => 'Acción no válida: ' . $action]);
    exit();
}

echo json_encode(['error' => 'Método no permitido']);
exit();
?>