<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'CLIENTE' || $_SESSION['entidad'] !== 'NEQUI') {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        $pdo->beginTransaction();

        // Obtener Saldo Actual en Nequi
        $stmtSaldo = $pdo->prepare("SELECT saldo_despues FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
        $stmtSaldo->execute([$usuario_id]);
        $resSaldo = $stmtSaldo->fetch();
        $saldo_disponible = $resSaldo ? floatval($resSaldo['saldo_despues']) : 1500000;

        // ==========================================
        // IF-09: ENVIAR DINERO (A NEQUI O BRE-B)
        // ==========================================
        if ($accion === 'enviar_plata') {
            $monto = floatval($_POST['monto'] ?? 0);
            $destino_input = trim($_POST['destino'] ?? '');
            $tipo_envio = $_POST['tipo_envio'] ?? 'nequi';

            if ($monto <= 0) throw new Exception("El monto debe ser mayor a cero.");
            if ($saldo_disponible < $monto) throw new Exception("No tienes suficiente plata en tu Disponible.");

            if ($tipo_envio === 'nequi') {
                // Destinatario por celular en Nequi
                $stmtDest = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE celular = ? AND rol = 'CLIENTE'");
                $stmtDest = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE (celular = ? OR email = ?) AND rol = 'CLIENTE'");
                $stmtDest->execute([$destino_input, $destino_input]);
                $dest = $stmtDest->fetch();

                if (!$dest) throw new Exception("El usuario destino no está registrado en Nequi.");
                if ($dest['id'] == $usuario_id) throw new Exception("No puedes enviarte plata a ti mismo.");

                // Saldo actual destino
                $stmtSD = $pdo->prepare("SELECT saldo_despues FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
                $stmtSD->execute([$dest['id']]);
                $resSD = $stmtSD->fetch();
                $saldo_dest_actual = $resSD ? floatval($resSD['saldo_despues']) : 1500000;

                // Restar al origen
                $nuevo_origen = $saldo_disponible - $monto;
                $stmtO = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues) VALUES (?, 'NEQUI', ?, 'DEBITO', ?, ?)");
                $stmtO->execute([$usuario_id, $monto, "Envío a " . $dest['nombre'], $nuevo_origen]);

                // Sumar al destino
                $nuevo_dest = $saldo_dest_actual + $monto;
                $stmtD = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues) VALUES (?, 'NEQUI', ?, 'CREDITO', ?, ?)");
                $stmtD->execute([$dest['id'], $monto, "Regalo de " . $_SESSION['nombre'], $nuevo_dest]);

            } else {
                // Envío Interoperable Bre-B hacia Bancolombia
                $stmtBreb = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE (email = ? OR celular = ? OR documento = ?) AND rol = 'CLIENTE'");
                $stmtBreb->execute([$destino_input, $destino_input, $destino_input]);
                $destB = $stmtBreb->fetch();

                if (!$destB) throw new Exception("Llave Bre-B no encontrada en Bancolombia.");
                if ($destB['id'] == $usuario_id) throw new Exception("No puedes enviarte a tu propia cuenta mediante Bre-B.");

                // Saldo Bancolombia destino
                $stmtSBC = $pdo->prepare("SELECT saldo_despues FROM transacciones WHERE producto_tipo = 'BANCOLOMBIA' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
                $stmtSBC->execute([$destB['id']]);
                $resSBC = $stmtSBC->fetch();
                $saldo_bc_actual = $resSBC ? floatval($resSBC['saldo_despues']) : 3000000;

                // Descontar de Nequi
                $nuevo_origen = $saldo_disponible - $monto;
                $stmtO = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues) VALUES (?, 'NEQUI', ?, 'DEBITO', ?, ?)");
                $stmtO->execute([$usuario_id, $monto, "Envío Bre-B a Bancolombia (" . $destB['nombre'] . ")", $nuevo_origen]);

                // Sumar a Bancolombia
                $nuevo_dest_bc = $saldo_bc_actual + $monto;
                $stmtD = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues) VALUES (?, 'BANCOLOMBIA', ?, 'CREDITO', ?, ?)");
                $stmtD->execute([$destB['id'], $monto, "Recibido Bre-B desde Nequi", $nuevo_dest_bc]);
            }
            $msg = "¡Plata enviada con éxito! 🚀";

        // ==========================================
        // IF-10: GESTIÓN DE BOLSILLOS
        // ==========================================
        } elseif ($accion === 'crear_bolsillo') {
            $nombre = trim($_POST['nombre'] ?? '');
            $meta = floatval($_POST['meta'] ?? 0);

            if (empty($nombre) || $meta <= 0) throw new Exception("Nombre o meta inválidos.");

            $stmt = $pdo->prepare("INSERT INTO bolsillos (usuario_id, nombre, meta_monto, saldo_actual) VALUES (?, ?, ?, 0)");
            $stmt->execute([$usuario_id, $nombre, $meta]);
            $msg = "Bolsillo '$nombre' creado. ¡Empieza a ahorrar! 💰";

        } elseif ($accion === 'ahorrar_bolsillo') {
            $bolsillo_id = intval($_POST['bolsillo_id'] ?? 0);
            $monto = floatval($_POST['monto'] ?? 0);

            if ($monto <= 0) throw new Exception("Monto inválido.");
            if ($saldo_disponible < $monto) throw new Exception("No tienes suficiente plata en tu Disponible.");

            // Descontar del disponible de Nequi
            $nuevo_disponible = $saldo_disponible - $monto;
            $stmtTx = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues) VALUES (?, 'NEQUI', ?, 'DEBITO', ?, ?)");
            $stmtTx->execute([$usuario_id, $monto, "Plata guardada en bolsillo", $nuevo_disponible]);

            // Sumar al bolsillo
            $stmtB = $pdo->prepare("UPDATE bolsillos SET saldo_actual = saldo_actual + ? WHERE id = ? AND usuario_id = ?");
            $stmtB->execute([$monto, $bolsillo_id, $usuario_id]);
            $msg = "¡Plata guardada en tu bolsillo! 🎯";

        } elseif ($accion === 'cerrar_bolsillo') {
            $bolsillo_id = intval($_POST['bolsillo_id'] ?? 0);

            // Consultar saldo interno del bolsillo
            $stmtB = $pdo->prepare("SELECT nombre, saldo_actual FROM bolsillos WHERE id = ? AND usuario_id = ?");
            $stmtB->execute([$bolsillo_id, $usuario_id]);
            $bolsillo = $stmtB->fetch();

            if (!$bolsillo) throw new Exception("Bolsillo no encontrado.");

            $plata_retorno = floatval($bolsillo['saldo_actual']);

            if ($plata_retorno > 0) {
                // Devolver la plata al disponible de Nequi
                $nuevo_disponible = $saldo_disponible + $plata_retorno;
                $stmtTx = $pdo->prepare("INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, saldo_despues) VALUES (?, 'NEQUI', ?, 'CREDITO', ?, ?)");
                $stmtTx->execute([$usuario_id, $plata_retorno, "Bolsillo '" . $bolsillo['nombre'] . "' roto/cerrado", $nuevo_disponible]);
            }

            // Eliminar el bolsillo físicamente
            $stmtDel = $pdo->prepare("DELETE FROM bolsillos WHERE id = ? AND usuario_id = ?");
            $stmtDel->execute([$bolsillo_id, $usuario_id]);
            $msg = "Bolsillo cerrado. La plata volvió a tu Disponible.";
        }

        $pdo->commit();
        header("Location: home.php?success=" . urlencode($msg));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: home.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}