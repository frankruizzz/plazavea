<?php
// api/promociones.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// CONFIGURACIÓN
require_once 'db.php';

session_start();

// VERIFICAR SESIÓN
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

// ============================================
// GET - PROMOCIONES VIGENTES EN ESTE MOMENTO
// ============================================
try {
    $stmt = $pdo->prepare("SELECT id_promocion, codigo_producto, nombre_promocion, 
                                  descuento_valor, id_metodo_pago
                           FROM promociones
                           WHERE NOW() BETWEEN fecha_inicio AND fecha_fin");
    $stmt->execute();
    $promociones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = array_map(function ($p) {
        return [
            'id_promocion' => intval($p['id_promocion']),
            'codigo_producto' => (string)$p['codigo_producto'],
            'nombre_promocion' => $p['nombre_promocion'],
            'descuento_valor' => floatval($p['descuento_valor']),
            // null = aplica a cualquier método de pago
            'id_metodo_pago' => $p['id_metodo_pago'] !== null ? intval($p['id_metodo_pago']) : null
        ];
    }, $promociones);

    echo json_encode($resultado);
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
exit();
?>