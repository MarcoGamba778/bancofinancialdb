<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id']) || $_SESSION['entidad'] !== 'NEQUI') {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ACCIÓN 1: CREAR BOLSILLO
    if ($accion === 'crear_bolsillo') {
        $nombre = trim($_POST['nombre'] ?? '');
        $monto_meta = floatval($_POST['monto_meta'] ?? 0);

        if (empty($nombre) || $monto_meta <= 0) {
            header('Location: home.php?error=Datos de bolsillo inválidos.');
            exit;
        }

        try {
            $sql = "INSERT INTO bolsillos (usuario_id, nombre, monto_meta, saldo_actual) VALUES (?, ?, ?, 0.00)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$usuario_id, $nombre, $monto_meta]);
            
            header('Location: home.php?success=¡Bolsillo "' . htmlspecialchars($nombre) . '" creado con éxito en la Base de Datos!');
            exit;
        } catch (Exception $e) {
            header('Location: home.php?error=Error SQL al crear bolsillo: ' . urlencode($e->getMessage()));
            exit;
        }
    }

    // ACCIÓN 2: AGREGAR PLATA (Con candado de fondos suficientes)
    if ($accion === 'agregar_plata') {
        $id_bolsillo = intval($_POST['id_bolsillo'] ?? 0);
        $monto_ahorrar = floatval($_POST['monto_ahorrar'] ?? 0);

        if ($id_bolsillo <= 0 || $monto_ahorrar <= 0) {
            header('Location: home.php?error=Monto de ahorro inválido.');
            exit;
        }

        try {
            // 🔒 CANDADO ANTES DE ENTRAR A LA TRANSACCIÓN
            $stmtC = $pdo->prepare("SELECT SUM(monto) as total FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? AND tipo_movimiento = 'CREDITO'");
            $stmtC->execute([$usuario_id]);
            $creditos = $stmtC->fetch()['total'] ?? 0;

            $stmtD = $pdo->prepare("SELECT SUM(monto) as total FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? AND tipo_movimiento = 'DEBITO'");
            $stmtD->execute([$usuario_id]);
            $debitos = $stmtD->fetch()['total'] ?? 0;

            $saldoBase = 1570000; 
            $saldoDisponibleActual = $saldoBase + ($creditos - $debitos);

            if ($monto_ahorrar > $saldoDisponibleActual) {
                header('Location: home.php?error=No tienes fondos suficientes en tu saldo disponible');
                exit;
            }

            // Si pasa el candado, procedemos a guardar
            $pdo->beginTransaction(); 

            $stmtBolsillo = $pdo->prepare("SELECT * FROM bolsillos WHERE id = ? FOR UPDATE");
            $stmtBolsillo->execute([$id_bolsillo]);
            $bolsillo = $stmtBolsillo->fetch();

            if (!$bolsillo) {
                throw new Exception("El bolsillo no existe.");
            }

            $stmtTransac = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, fecha) VALUES (?, 'NEQUI', ?, 'DEBITO', ?, NOW())");
            $stmtTransac->execute([$usuario_id, $monto_ahorrar, "Ahorro bolsillo: " . $bolsillo['nombre']]);

            $stmtUpdate = $pdo->prepare("UPDATE bolsillos SET saldo_actual = saldo_actual + ? WHERE id = ?");
            $stmtUpdate->execute([$monto_ahorrar, $id_bolsillo]);

            $pdo->commit();
            header('Location: home.php?success=¡Se agregaron $' . number_format($monto_ahorrar, 0, ',', '.') . ' al bolsillo con éxito!');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            header('Location: home.php?error=No se pudo ahorrar: ' . urlencode($e->getMessage()));
            exit;
        }
    }

    // ACCIÓN 3: ELIMINAR BOLSILLO (Regresa la plata acumulada y limpia el saldo negativo)
    if ($accion === 'eliminar_bolsillo') {
        $id_bolsillo = intval($_POST['id_bolsillo'] ?? 0);

        if ($id_bolsillo <= 0) {
            header('Location: home.php?error=Bolsillo inválido.');
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmtBolsillo = $pdo->prepare("SELECT * FROM bolsillos WHERE id = ? FOR UPDATE");
            $stmtBolsillo->execute([$id_bolsillo]);
            $bolsillo = $stmtBolsillo->fetch();

            if (!$bolsillo) {
                throw new Exception("Bolsillo no encontrado.");
            }

            $dinero_guardado = floatval($bolsillo['saldo_actual']);

            // Al eliminar, este CREDITO anulará el DEBITO de 5 millones que te dejó en negativo
            if ($dinero_guardado > 0) {
                $stmtTransac = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, fecha) VALUES (?, 'NEQUI', ?, 'CREDITO', ?, NOW())");
                $stmtTransac->execute([$usuario_id, $dinero_guardado, "Liquidación de bolsillo: " . $bolsillo['nombre']]);
            }

            $stmtDelete = $pdo->prepare("DELETE FROM bolsillos WHERE id = ?");
            $stmtDelete->execute([$id_bolsillo]);

            $pdo->commit();
            header('Location: home.php?success=¡Bolsillo eliminado! Los fondos acumulados regresaron a tu disponible.');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            header('Location: home.php?error=Error al eliminar bolsillo: ' . urlencode($e->getMessage()));
            exit;
        }
    }
}

header('Location: home.php');
exit;