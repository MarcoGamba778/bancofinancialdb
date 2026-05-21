<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔒 Control de acceso seguro exclusivo para clientes Nequi
if (!isset($_SESSION['usuario_id']) || $_SESSION['entidad'] !== 'NEQUI') {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ACCIÓN 1: REGISTRAR LLAVE PROPIA EN EL SISTEMA BRE-B (IF-12)
    if ($accion === 'registrar_llave') {
        $tipo_llave = trim($_POST['tipo_llave'] ?? '');
        $valor_llave = trim($_POST['valor_llave'] ?? '');

        if (empty($tipo_llave) || empty($valor_llave)) {
            header('Location: home.php?error=Campos de la llave Bre-B incompletos.');
            exit;
        }

        try {
            // Verificar si la llave ya existe registrada en el sistema por cualquier otro usuario
            $stmtCheck = $pdo->prepare("SELECT id FROM llaves_breb WHERE tipo_llave = ? AND valor_llave = ?");
            $stmtCheck->execute([$tipo_llave, $valor_llave]);
            if ($stmtCheck->fetch()) {
                header('Location: home.php?error=Esta llave Bre-B ya se encuentra registrada en el sistema.');
                exit;
            }

            // Insertar la nueva llave asociándola al usuario actual de Nequi
            $sql = "INSERT INTO llaves_breb (usuario_id, tipo_llave, valor_llave, fecha_registro) VALUES (?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$usuario_id, $tipo_llave, $valor_llave]);

            header('Location: home.php?success=¡Llave Bre-B registrada exitosamente!');
            exit;
        } catch (Exception $e) {
            header('Location: home.php?error=Error al registrar la llave: ' . urlencode($e->getMessage()));
            exit;
        }
    }

    // ACCIÓN 2: ELIMINAR / DESVINCULAR LLAVE PROPIA (IF-12)
    if ($accion === 'eliminar_llave') {
        $id_llave = intval($_POST['id_llave'] ?? 0);

        if ($id_llave <= 0) {
            header('Location: home.php?error=ID de llave inválido.');
            exit;
        }

        try {
            // Eliminar asegurando que pertenezca al usuario en sesión para que no altere datos ajenos
            $stmtDelete = $pdo->prepare("DELETE FROM llaves_breb WHERE id = ? AND usuario_id = ?");
            $stmtDelete->execute([$id_llave, $usuario_id]);

            header('Location: home.php?success=Llave Bre-B eliminada y liberada correctamente.');
            exit;
        } catch (Exception $e) {
            header('Location: home.php?error=Error al eliminar la llave Bre-B: ' . urlencode($e->getMessage()));
            exit;
        }
    }
}

// Si entran de forma directa por URL, se regresa al Home
header('Location: home.php');
exit;