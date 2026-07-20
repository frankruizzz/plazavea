<?php

    $host = 'localhost';
    $dbname = 'plazavea';
    $user = 'root'; 
    $pass = '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
        // Si falla, corta la ejecución y avisa al sistema web el motivo exacto
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Error de conexión remota al servidor: ' . $e->getMessage()]);
        exit();
    }
?>