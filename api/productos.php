<?php
// api/productos.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// CONFIGURACIÓN
require_once 'db.php';

// OBTENER PARÁMETROS (GET o POST)
$codigo = '';
$descripcion = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data) {
        $codigo = trim($data['codigo'] ?? '');
        $descripcion = trim($data['descripcion'] ?? '');
    } else {
        $codigo = trim($_POST['codigo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
    }
} else {
    $codigo = trim($_GET['codigo'] ?? '');
    $descripcion = trim($_GET['descripcion'] ?? '');
}

// ============================================
// BÚSQUEDA POR CÓDIGO
// ============================================
if (!empty($codigo)) {
    try {
        $stmt = $pdo->prepare("SELECT codigo_producto, descripcion, precio_regular 
                               FROM productos 
                               WHERE codigo_producto = :codigo");
        $stmt->execute(['codigo' => $codigo]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($producto) {
            echo json_encode([
                'success' => true,
                'codigo' => $producto['codigo_producto'],
                'descripcion' => $producto['descripcion'],
                'precio' => floatval($producto['precio_regular']),
                'precio_regular' => floatval($producto['precio_regular']),
                'descuento' => 0,
                'es_oferta' => false
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// BÚSQUEDA POR DESCRIPCIÓN (CORREGIDA)
// ============================================
if (!empty($descripcion)) {
    try {
        $busqueda = '%' . $descripcion . '%';
        $stmt = $pdo->prepare("SELECT codigo_producto, descripcion, precio_regular 
                               FROM productos 
                               WHERE descripcion LIKE :descripcion
                               LIMIT 10");
        $stmt->execute(['descripcion' => $busqueda]);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Siempre devolver un array, incluso si está vacío
        $resultados = [];
        foreach ($productos as $p) {
            $resultados[] = [
                'codigo' => $p['codigo_producto'],
                'descripcion' => $p['descripcion'],
                'precio' => floatval($p['precio_regular']),
                'precio_regular' => floatval($p['precio_regular']),
                'descuento' => 0,
                'es_oferta' => false
            ];
        }
        echo json_encode($resultados);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['error' => 'Ingrese un código o descripción']);
exit();
?>