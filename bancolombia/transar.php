<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validar sesión activa de cliente en Bancolombia
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'CLIENTE' || $_SESSION['entidad'] !== 'BANCOLOMBIA') {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = $_POST['tipo_operacion'] ?? '';
    $monto = floatval($_POST['monto'] ?? 0);
    $concepto = trim($_POST['concepto'] ?? '');
    
    if ($monto <= 0) {
        header('Location: dashboard.php?error=El monto debe ser mayor a cero.');
        exit;
    }

    $pdo = getDB();

    try {
        // Iniciar transacción SQL para asegurar atomicidad
        $pdo->beginTransaction();

        // 1. Obtener el saldo actual del cliente en Bancolombia
        $stmtSaldo = $pdo->prepare("SELECT saldo_despues FROM transacciones WHERE producto_tipo = 'BANCOLOMBIA' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
        $stmtSaldo->execute([$usuario_id]);
        $resSaldo = $stmtSaldo->fetch();
        $saldo_actual = $resSaldo ? floatval($resSaldo['saldo_despues']) : 3000000; // Saldo base por defecto si está en 0

        if ($tipo === 'consignacion') {
            $nuevo_saldo = $saldo_actual + $monto;
            if (empty($concepto)) $concepto = 'Consignación en Efectivo Corresponsal';

            $stmt = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues, fecha) VALUES (?, 'BANCOLOMBIA', ?, 'CREDITO', ?, ?, NOW())");
            $stmt->execute([$usuario_id, $monto, $concepto, $nuevo_saldo]);

        } elseif ($tipo === 'retiro') {
            if ($saldo_actual < $monto) {
                throw new Exception("Fondos insuficientes para realizar el retiro.");
            }
            $nuevo_saldo = $saldo_actual - $monto;
            if (empty($concepto)) $concepto = 'Retiro en Cajero Automático';

            $stmt = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues, fecha) VALUES (?, 'BANCOLOMBIA', ?, 'DEBITO', ?, ?, NOW())");
            $stmt->execute([$usuario_id, $monto, $concepto, $nuevo_saldo]);

        } elseif ($tipo === 'transferencia_interna') {
            $documento_destino = trim($_POST['documento_destino'] ?? '');
            
            // Buscar al usuario destino en el mismo banco
            $stmtDestino = $pdo->prepare("SELECT id FROM usuarios WHERE documento = ? AND rol = 'CLIENTE'");
            $stmtDestino->execute([$documento_destino]);
            $destino = $stmtDestino->fetch();

            if (!$destino) {
                throw new Exception("La cuenta destino con el documento ingresado no existe.");
            }
            if ($destino['id'] == $usuario_id) {
                throw new Exception("No puedes transferirte a ti mismo mediante transferencia interna.");
            }
            if ($saldo_actual < $monto) {
                throw new Exception("Fondos insuficientes para la transferencia.");
            }

            // Obtener saldo actual del destino
            $stmtSaldoDest = $pdo->prepare("SELECT saldo_despues FROM transacciones WHERE producto_tipo = 'BANCOLOMBIA' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
            $stmtSaldoDest->execute([$destino['id']]);
            $resSaldoDest = $stmtSaldoDest->fetch();
            $saldo_dest_actual = $resSaldoDest ? floatval($resSaldoDest['saldo_despues']) : 0;

            // Restar al origen (Débito)
            $nuevo_saldo = $saldo_actual - $monto;
            if (empty($concepto)) $concepto = 'Transferencia enviada interna';
            $stmtDebito = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues, fecha) VALUES (?, 'BANCOLOMBIA', ?, 'DEBITO', ?, ?, NOW())");
            $stmtDebito->execute([$usuario_id, $monto, $concepto, $nuevo_saldo]);

            // Sumar al destino (Crédito)
            $nuevo_saldo_dest = $saldo_dest_actual + $monto;
            $concepto_dest = 'Transferencia recibida de Cuenta Principal';
            $stmtCredito = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues, fecha) VALUES (?, 'BANCOLOMBIA', ?, 'CREDITO', ?, ?, NOW())");
            $stmtCredito->execute([$destino['id'], $monto, $concepto_dest, $nuevo_saldo_dest]);

        } elseif ($tipo === 'transferencia_breb') {
            $llave_breb = trim($_POST['llave_breb'] ?? '');

            // Buscar en la base de datos a qué usuario le pertenece esa llave Bre-B (puede estar vinculada por correo, celular o cédula)
            $stmtLlave = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE (email = ? OR celular = ? OR documento = ?) AND rol = 'CLIENTE'");
            $stmtLlave->execute([$llave_breb, $llave_breb, $llave_breb]);
            $destBreb = $stmtLlave->fetch();

            if (!$destBreb) {
                throw new Exception("Llave Bre-B no encontrada en el ecosistema financiero.");
            }
            if ($destBreb['id'] == $usuario_id) {
                throw new Exception("No puedes realizar transferencias Bre-B a tu propia cuenta de origen.");
            }
            if ($saldo_actual < $monto) {
                throw new Exception("Fondos insuficientes para enviar por Bre-B.");
            }

            // Consultar saldo actual en NEQUI del destinatario
            $stmtSaldoNequi = $pdo->prepare("SELECT saldo_despues FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
            $stmtSaldoNequi->execute([$destBreb['id']]);
            $resSaldoNequi = $stmtSaldoNequi->fetch();
            $saldo_nequi_actual = $resSaldoNequi ? floatval($resSaldoNequi['saldo_despues']) : 1500000;

            // 1. Descontar de Bancolombia (Origen)
            $nuevo_saldo = $saldo_actual - $monto;
            $concepto_origen = "Envío Bre-B a " . $destBreb['nombre'];
            $stmtBancolombia = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues, fecha) VALUES (?, 'BANCOLOMBIA', ?, 'DEBITO', ?, ?, NOW())");
            $stmtBancolombia->execute([$usuario_id, $monto, $concepto_origen, $nuevo_saldo]);

            // 2. Abonar de inmediato en NEQUI (Destino Interoperable)
            $nuevo_saldo_nequi = $saldo_nequi_actual + $monto;
            $concepto_destino = "Recibido Bre-B desde Bancolombia";
            $stmtNequi = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues, fecha) VALUES (?, 'NEQUI', ?, 'CREDITO', ?, ?, NOW())");
            $stmtNequi->execute([$destBreb['id'], $monto, $concepto_destino, $nuevo_saldo_nequi]);
        }

        // Si todo anduvo perfecto, guardar cambios en la BD
        $pdo->commit();
        header('Location: dashboard.php?success=Operación ejecutada con éxito de forma segura.');
        exit;

    } catch (Exception $e) {
        // Si algo falla, revertir los saldos para que no se pierda dinero
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: dashboard.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}