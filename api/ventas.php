<?php
// api/ventas.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// CONFIGURACIÓN
require_once 'db.php';

session_start();

// VERIFICAR SESIÓN
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================
// POST - PROCESAR VENTA
// ============================================
if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $action = $data['action'] ?? '';

    if ($action === 'procesar') {
        $productos = $data['productos'] ?? [];
        $metodo_pago = intval($data['metodo_pago'] ?? 1);
        $tipo_comprobante = intval($data['tipo_comprobante'] ?? 1);
        $id_cliente = intval($data['id_cliente'] ?? 1);

        if (empty($productos)) {
            echo json_encode(['error' => 'No hay productos en la venta']);
            exit();
        }

        // Verificar caja abierta
        $stmt = $pdo->prepare("SELECT id_control_caja FROM control_caja 
                              WHERE id_empleado = :empleado AND estado = 'Abierto'");
        $stmt->execute(['empleado' => $_SESSION['user_id']]);
        $caja = $stmt->fetch();

        if (!$caja) {
            echo json_encode(['error' => 'La caja no está abierta']);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // ============================================
            // Calcular totales EN EL SERVIDOR.
            // Nunca se confía en el precio enviado por el cliente: se vuelve
            // a consultar el precio real del producto y la promoción vigente
            // (según el método de pago elegido) para evitar manipulación.
            // ============================================
            $subtotal = 0;
            $detalleCalculado = [];

            foreach ($productos as $item) {
                $codigo = $item['codigo'];
                $cantidad = intval($item['cantidad']);

                if ($cantidad <= 0) {
                    continue;
                }

                $stmtProd = $pdo->prepare("SELECT precio_regular FROM productos WHERE codigo_producto = :codigo");
                $stmtProd->execute(['codigo' => $codigo]);
                $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);

                if (!$prod) {
                    throw new Exception("El producto $codigo ya no existe en el catálogo");
                }

                $precio_regular = floatval($prod['precio_regular']);

                // Promoción vigente aplicable al método de pago elegido
                // (id_metodo_pago NULL en la promoción = aplica a cualquier método)
                $stmtPromo = $pdo->prepare("SELECT descuento_valor FROM promociones
                                           WHERE codigo_producto = :codigo
                                           AND NOW() BETWEEN fecha_inicio AND fecha_fin
                                           AND (id_metodo_pago IS NULL OR id_metodo_pago = :metodo)
                                           ORDER BY descuento_valor DESC LIMIT 1");
                $stmtPromo->execute(['codigo' => $codigo, 'metodo' => $metodo_pago]);
                $promo = $stmtPromo->fetch(PDO::FETCH_ASSOC);
                $descuento = $promo ? floatval($promo['descuento_valor']) : 0;

                $precio_final = max($precio_regular - $descuento, 0);
                $subtotal += $precio_final * $cantidad;

                $detalleCalculado[] = [
                    'codigo' => $codigo,
                    'cantidad' => $cantidad,
                    'precio_unitario_historico' => $precio_regular,
                    'descuento_aplicado' => $descuento
                ];
            }

            if (empty($detalleCalculado)) {
                throw new Exception('No hay productos válidos en la venta');
            }

            $igv = $subtotal * 0.18;
            $total = $subtotal + $igv;

            $serie = ($tipo_comprobante === 2) ? 'F001' : 'B001';

            // Obtener correlativo
            $stmt = $pdo->prepare("SELECT MAX(correlativo) as ultimo FROM ventas WHERE serie_comprobante = :serie");
            $stmt->execute(['serie' => $serie]);
            $correlativo = $stmt->fetch();
            $correlativo_num = ($correlativo['ultimo'] ?? 0) + 1;

            // Insertar venta
            $stmt = $pdo->prepare("INSERT INTO ventas 
                                  (serie_comprobante, correlativo, fecha_emision, id_empleado, 
                                   id_cliente, id_tipo_comprobante, id_metodo_pago, subtotal, igv, total_pagar)
                                  VALUES (:serie, :correlativo, NOW(), :empleado, :cliente, :tipo, :pago, :subtotal, :igv, :total)");
            $stmt->execute([
                'serie' => $serie,
                'correlativo' => $correlativo_num,
                'empleado' => $_SESSION['user_id'],
                'cliente' => $id_cliente,
                'tipo' => $tipo_comprobante,
                'pago' => $metodo_pago,
                'subtotal' => $subtotal,
                'igv' => $igv,
                'total' => $total
            ]);

            $venta_id = $pdo->lastInsertId();

            // Insertar detalles (usando los precios/descuentos calculados en servidor)
            foreach ($detalleCalculado as $item) {
                $stmt = $pdo->prepare("INSERT INTO detalle_ventas 
                                      (id_venta, codigo_producto, cantidad, precio_unitario_historico, descuento_aplicado)
                                      VALUES (:venta, :codigo, :cantidad, :precio, :descuento)");
                $stmt->execute([
                    'venta' => $venta_id,
                    'codigo' => $item['codigo'],
                    'cantidad' => $item['cantidad'],
                    'precio' => $item['precio_unitario_historico'],
                    'descuento' => $item['descuento_aplicado']
                ]);
            }

            // Actualizar caja
            $stmt = $pdo->prepare("UPDATE control_caja 
                                  SET monto_final_sistema = monto_final_sistema + :monto
                                  WHERE id_control_caja = :caja AND estado = 'Abierto'");
            $stmt->execute([
                'monto' => $total,
                'caja' => $caja['id_control_caja']
            ]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'venta_id' => $venta_id,
                'serie' => $serie,
                'correlativo' => $correlativo_num,
                'total' => $total,
                'subtotal' => $subtotal,
                'igv' => $igv,
                'fecha' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Error al procesar la venta: ' . $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['error' => 'Acción no válida: ' . $action]);
    exit();
}

// ============================================
// GET - ÚLTIMA VENTA
// ============================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'ultima' || $action === 'detalle') {
        // 'ultima' trae la venta más reciente del cajero; 'detalle' trae una
        // venta puntual por id (usado para reimprimir el comprobante en PDF
        // desde el historial de reportes).
        if ($action === 'detalle') {
            $venta_id = intval($_GET['venta_id'] ?? 0);
            if (!$venta_id) {
                echo json_encode(['error' => 'venta_id requerido']);
                exit();
            }
            $where = "v.id_venta = :venta_id AND v.id_empleado = :empleado";
            $params = ['venta_id' => $venta_id, 'empleado' => $_SESSION['user_id']];
            $orderLimit = "";
        } else {
            $where = "v.id_empleado = :empleado";
            $params = ['empleado' => $_SESSION['user_id']];
            $orderLimit = "ORDER BY v.fecha_emision DESC LIMIT 1";
        }

        $stmt = $pdo->prepare("SELECT v.*, 
                                      c.nombres as cliente_nombres,
                                      c.apellido_paterno as cliente_apellido_paterno,
                                      c.apellido_materno as cliente_apellido_materno,
                                      c.id_tipo_documento as cliente_id_tipo_documento,
                                      c.numero_documento as cliente_numero_documento,
                                      tc.nombre_comprobante,
                                      mp.nombre_metodo as metodo_pago
                              FROM ventas v
                              LEFT JOIN clientes c ON v.id_cliente = c.id_cliente
                              LEFT JOIN tipos_comprobante tc ON v.id_tipo_comprobante = tc.id_tipo_comprobante
                              LEFT JOIN metodos_pago mp ON v.id_metodo_pago = mp.id_metodo_pago
                              WHERE $where
                              $orderLimit");
        $stmt->execute($params);
        $venta = $stmt->fetch();

        if (!$venta) {
            echo json_encode(['error' => 'No hay ventas']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT dv.*, p.descripcion
                              FROM detalle_ventas dv
                              JOIN productos p ON dv.codigo_producto = p.codigo_producto
                              WHERE dv.id_venta = :venta");
        $stmt->execute(['venta' => $venta['id_venta']]);
        $detalles = $stmt->fetchAll();

        echo json_encode(['venta' => $venta, 'detalles' => $detalles]);
        exit();
    }

    echo json_encode(['error' => 'Acción no válida: ' . $action]);
    exit();
}

echo json_encode(['error' => 'Método no permitido']);
exit();
?>