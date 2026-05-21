<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔒 Control de acceso seguro (Se mantiene intacto)
if (!isset($_SESSION['usuario_id']) || $_SESSION['entidad'] !== 'NEQUI') {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$pdo = getDB();

$error_msg = "";

// === PROCESAMIENTO DEL FORMULARIO (Aquí está la modificación de SQL) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monto = floatval($_POST['monto'] ?? 0);
    $descripcion_form = trim($_POST['descripcion'] ?? '');
    
    // Si el usuario no escribe nada en el campo opcional, le asignamos un concepto por defecto
    $concepto_final = !empty($descripcion_form) ? $descripcion_form : "Recarga Billetera Nequi";

    if ($monto <= 0) {
        $error_msg = "El monto de la recarga debe ser mayor a cero.";
    } else {
        try {
            $pdo->beginTransaction();

            // 🌟 EXPLICACIÓN DEL CAMBIO SQL: 
            // Cambiamos la columna inexistente 'descripcion' por 'concepto', que sí existe en tu tabla transacciones.
            $sql = "INSERT INTO transacciones (producto_id, producto_tipo, monto, tipo_movimiento, concepto, fecha) 
                    VALUES (?, 'NEQUI', ?, 'CREDITO', ?, NOW())";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$usuario_id, $monto, $concepto_final]);

            $pdo->commit();
            
            // Redirección limpia al mismo archivo para actualizar el saldo en pantalla de inmediato
            header("Location: enviar.php?success=¡Recarga exitosa!");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Captura el error de forma segura en una variable en vez de romper la página
            $error_msg = "Error en la Base de Datos: " . $e->getMessage();
        }
    }
}

// === CONSULTA PARA PINTAR EL SALDO ACTUAL DE TU BILLETERA ===
$stmtC = $pdo->prepare("SELECT SUM(monto) as total FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? AND tipo_movimiento = 'CREDITO'");
$stmtC->execute([$usuario_id]);
$creditos = $stmtC->fetch()['total'] ?? 0;

$stmtD = $pdo->prepare("SELECT SUM(monto) as total FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? AND tipo_movimiento = 'DEBITO'");
$stmtD->execute([$usuario_id]);
$debitos = $stmtD->fetch()['total'] ?? 0;

$saldoBase = 1570000; 
$saldoBilletera = $saldoBase + ($creditos - $debitos);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recargar billetera — Nequi</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f0fa; margin: 0; padding: 0; }
        .navbar { background-color: #440066; padding: 15px 30px; color: white; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 24px; font-weight: bold; }
        .container { max-width: 1100px; margin: 40px auto; display: grid; grid-template-columns: 1fr 1fr; gap: 30px; padding: 0 20px; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .tabs { display: flex; gap: 10px; margin-bottom: 25px; }
        .tab-btn { padding: 10px 20px; border: 1px solid #440066; border-radius: 20px; background: none; color: #440066; cursor: pointer; font-weight: bold; text-decoration: none; }
        .tab-btn.active { background-color: #440066; color: white; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #333; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; font-size: 16px; }
        .btn-submit { width: 100%; background-color: #440066; color: white; border: none; padding: 14px; border-radius: 25px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        .btn-submit:hover { background-color: #330050; }
        .saldo-box { background: linear-gradient(135deg, #fff, #f9f6fc); border-left: 5px solid #440066; }
        .saldo-monto { font-size: 36px; font-weight: bold; color: #440066; margin: 15px 0 5px 0; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .btn-back { display: inline-block; text-align: center; width: 100%; margin-top: 20px; color: #440066; font-weight: bold; text-decoration: none; padding: 10px; border: 1px solid #440066; border-radius: 25px; box-sizing: border-box; }
        .btn-back:hover { background-color: #f4f0fa; }
        .btn-salir { background-color: #d31d5b; color: white; padding: 8px 18px; border-radius: 15px; text-decoration: none; font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>

    <div class="navbar">
        <h1>N Nequi</h1>
        <div>
            <span style="margin-right: 15px;">Hola, <strong><?php echo htmlspecialchars($_SESSION['nombre'] ?? 'Usuario'); ?></strong></span>
            <a href="logout.php" class="btn-salir">Salir</a>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="tabs">
                <a href="enviar.php" class="tab-btn active">💳 Recargar</a>
                <a href="#" class="tab-btn">🏧 Retirar</a>
                <a href="home.php" class="tab-btn">🔮 Enviar</a>
            </div>

            <h3 style="color: #666; margin-top: 0;">📩 RECARGAR BILLETERA</h3>
            <p style="color: #888; font-size: 14px; margin-bottom: 25px;">Añade saldo a tu billetera Nequi de forma segura.</p>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>

            <form action="enviar.php" method="POST">
                <div class="form-group">
                    <label>Monto (COP)</label>
                    <input type="number" name="monto" class="form-control" placeholder="Ej: 50000" required min="1">
                </div>

                <div class="form-group">
                    <label>Descripción (opcional)</label>
                    <input type="text" name="descripcion" class="form-control" placeholder="Ej: Para el mercado">
                </div>

                <button type="submit" class="btn-submit">💳 Confirmar Recarga</button>
            </form>
        </div>

        <div class="card saldo-box">
            <h4 style="color: #666; margin: 0; text-transform: uppercase; letter-spacing: 1px;">Tu Billetera</h4>
            <div class="saldo-monto">$ <?php echo number_format($saldoBilletera, 0, ',', '.'); ?></div>
            <p style="color: #888; margin: 0; font-size: 14px;">📱 Celular: <?php echo htmlspecialchars($_SESSION['celular'] ?? '3110000001'); ?></p>
            
            <a href="home.php" class="btn-back">← Volver al inicio</a>
        </div>
    </div>

</body>
</html>