<?php
require_once __DIR__ . '/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'CLIENTE') {
    header('Location: ../index.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$entidad_actual = $_SESSION['entidad']; // BANCOLOMBIA o NEQUI
$pdo = getDB();

$redirect_url = ($entidad_actual === 'BANCOLOMBIA') ? '../bancolombia/dashboard.php' : '../nequi/home.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'vincular_llave') {
            $tipo_llave = $_POST['tipo_llave'] ?? '';
            $valor_llave = trim($_POST['valor_llave'] ?? '');

            if (empty($valor_llave)) {
                throw new Exception("El valor de la llave no puede estar vacío.");
            }

            // Verificar si la llave ya está registrada por otro usuario
            $stmtCheck = $pdo->prepare("SELECT id FROM llaves_breb WHERE valor_llave = ? AND usuario_id <> ?");
            $stmtCheck->execute([$valor_llave, $usuario_id]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Esta llave ya se encuentra vinculada a otra cuenta en la red Bre-B.");
            }

            // Insertar o actualizar la llave del usuario (ON DUPLICATE KEY UPDATE)
            $stmt = $pdo->prepare("INSERT INTO llaves_breb (usuario_id, tipo_llave, valor_llave, entidad_destino) 
                                   VALUES (?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE tipo_llave = ?, valor_llave = ?, entidad_destino = ?");
            $stmt->execute([$usuario_id, $tipo_llave, $valor_llave, $entidad_actual, $tipo_llave, $valor_llave, $entidad_actual]);

            $msg = "¡Llave Bre-B configurada correctamente para recibir en $entidad_actual! 🚀";
            header("Location: " . $redirect_url . "?success=" . urlencode($msg));
            exit;

        } elseif ($accion === 'eliminar_llave') {
            $stmtDel = $pdo->prepare("DELETE FROM llaves_breb WHERE usuario_id = ?");
            $stmtDel->execute([$usuario_id]);

            $msg = "Llave Bre-B desvinculada. Ya no recibirás transferencias instantáneas por este medio.";
            header("Location: " . $redirect_url . "?success=" . urlencode($msg));
            exit;
        }
    } catch (Exception $e) {
        header("Location: " . $redirect_url . "?error=" . urlencode($e->getMessage()));
        exit;
    }
}