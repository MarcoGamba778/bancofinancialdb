<?php
// bancolombia/transaccion.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/funciones.php';

requireAuth('BANCOLOMBIA');

$pdo        = getDB();
$usuario_id = $_SESSION['usuario_id'];
$tipo       = $_GET['tipo'] ?? 'consignacion'; // consignacion | retiro | transferencia

// Validar tipo
if (!in_array($tipo, ['consignacion', 'retiro', 'transferencia'])) {
    $tipo = 'consignacion';
}

// Obtener cuenta del usuario autenticado
$stmt = $pdo->prepare(
    "SELECT * FROM cuentas_bancolombia WHERE usuario_id = ? LIMIT 1"
);
$stmt->execute([$usuario_id]);
$cuenta = $stmt->fetch();

if (!$cuenta) {
    die("No tienes una cuenta Bancolombia asociada.");
}

$resultado = null; // ['exito' => bool, 'mensaje' => string]

// ──────────────────────────────────────────
//  PROCESAMIENTO DEL FORMULARIO
// ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monto       = (float)($_POST['monto'] ?? 0);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $destino_num = trim($_POST['numero_destino'] ?? ''); // solo en transferencia

    // Validaciones básicas
    if ($monto <= 0) {
        $resultado = ['exito' => false, 'mensaje' => 'El monto debe ser mayor a cero.'];
    } elseif ($cuenta['estado'] !== 'ACTIVA') {
        $resultado = ['exito' => false, 'mensaje' => 'Tu cuenta está bloqueada o cerrada.'];
    } else {

        // ── CONSIGNACIÓN ──────────────────────────────
        if ($tipo === 'consignacion') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE cuentas_bancolombia SET saldo_actual = saldo_actual + ? WHERE id = ?"
                )->execute([$monto, $cuenta['id']]);

                $nuevo_saldo = $cuenta['saldo_actual'] + $monto;

                $pdo->prepare(
                    "INSERT INTO transacciones
                     (concepto, monto, saldo_despues, descripcion, tipo_movimiento,
                      cuenta_bancolombia_id, fecha)
                     VALUES ('CONSIGNACION', ?, ?, ?, 'CREDITO', ?, NOW())"
                )->execute([$monto, $nuevo_saldo, $descripcion ?: 'Consignación', $cuenta['id']]);

                $pdo->commit();
                $resultado = ['exito' => true, 'mensaje' => 'Consignación realizada correctamente por ' . formatoPeso($monto)];

                // Actualizar saldo en memoria para mostrarlo
                $cuenta['saldo_actual'] = $nuevo_saldo;

            } catch (Exception $e) {
                $pdo->rollBack();
                $resultado = ['exito' => false, 'mensaje' => 'Error al procesar: ' . $e->getMessage()];
            }

        // ── RETIRO ───────────────────────────────────
        } elseif ($tipo === 'retiro') {
            $saldo_disponible = $cuenta['saldo_actual'] + ($cuenta['cupo_sobregiro'] ?? 0);

            if ($monto > $saldo_disponible) {
                $resultado = ['exito' => false, 'mensaje' => 'Saldo insuficiente. Disponible: ' . formatoPeso($saldo_disponible)];
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        "UPDATE cuentas_bancolombia SET saldo_actual = saldo_actual - ? WHERE id = ?"
                    )->execute([$monto, $cuenta['id']]);

                    $nuevo_saldo = $cuenta['saldo_actual'] - $monto;

                    $pdo->prepare(
                        "INSERT INTO transacciones
                         (concepto, monto, saldo_despues, descripcion, tipo_movimiento,
                          cuenta_bancolombia_id, fecha)
                         VALUES ('RETIRO', ?, ?, ?, 'DEBITO', ?, NOW())"
                    )->execute([$monto, $nuevo_saldo, $descripcion ?: 'Retiro en efectivo', $cuenta['id']]);

                    $pdo->commit();
                    $resultado = ['exito' => true, 'mensaje' => 'Retiro exitoso de ' . formatoPeso($monto)];
                    $cuenta['saldo_actual'] = $nuevo_saldo;

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $resultado = ['exito' => false, 'mensaje' => 'Error al procesar: ' . $e->getMessage()];
                }
            }

        // ── TRANSFERENCIA INTERNA ─────────────────────
        } elseif ($tipo === 'transferencia') {
            if (!$destino_num) {
                $resultado = ['exito' => false, 'mensaje' => 'Ingresa el número de cuenta destino.'];
            } else {
                // Buscar cuenta destino
                $stmtD = $pdo->prepare(
                    "SELECT * FROM cuentas_bancolombia WHERE numero_cuenta = ? LIMIT 1"
                );
                $stmtD->execute([$destino_num]);
                $destino = $stmtD->fetch();

                if (!$destino) {
                    $resultado = ['exito' => false, 'mensaje' => 'Cuenta destino no encontrada.'];
                } elseif ($destino['id'] === $cuenta['id']) {
                    $resultado = ['exito' => false, 'mensaje' => 'No puedes transferirte a ti mismo.'];
                } elseif ($destino['estado'] !== 'ACTIVA') {
                    $resultado = ['exito' => false, 'mensaje' => 'La cuenta destino está bloqueada.'];
                } else {
                    $saldo_disponible = $cuenta['saldo_actual'] + ($cuenta['cupo_sobregiro'] ?? 0);

                    if ($monto > $saldo_disponible) {
                        $resultado = ['exito' => false, 'mensaje' => 'Saldo insuficiente. Disponible: ' . formatoPeso($saldo_disponible)];
                    } else {
                        $pdo->beginTransaction();
                        try {
                            // Débito en origen (con bloqueo FOR UPDATE)
                            $pdo->prepare(
                                "UPDATE cuentas_bancolombia SET saldo_actual = saldo_actual - ?
                                 WHERE id = ?"
                            )->execute([$monto, $cuenta['id']]);

                            $nuevo_saldo_origen = $cuenta['saldo_actual'] - $monto;

                            $pdo->prepare(
                                "INSERT INTO transacciones
                                 (concepto, monto, saldo_despues, descripcion, tipo_movimiento,
                                  cuenta_bancolombia_id, fecha)
                                 VALUES ('TRANSFERENCIA_INTERNA', ?, ?, ?, 'DEBITO', ?, NOW())"
                            )->execute([
                                $monto,
                                $nuevo_saldo_origen,
                                'Transferencia a ' . $destino['numero_cuenta'],
                                $cuenta['id']
                            ]);

                            // Crédito en destino
                            $pdo->prepare(
                                "UPDATE cuentas_bancolombia SET saldo_actual = saldo_actual + ?
                                 WHERE id = ?"
                            )->execute([$monto, $destino['id']]);

                            $stmtSD = $pdo->prepare(
                                "SELECT saldo_actual FROM cuentas_bancolombia WHERE id = ?"
                            );
                            $stmtSD->execute([$destino['id']]);
                            $nuevo_saldo_destino = $stmtSD->fetchColumn();

                            $pdo->prepare(
                                "INSERT INTO transacciones
                                 (concepto, monto, saldo_despues, descripcion, tipo_movimiento,
                                  cuenta_bancolombia_id, fecha)
                                 VALUES ('TRANSFERENCIA_INTERNA', ?, ?, ?, 'CREDITO', ?, NOW())"
                            )->execute([
                                $monto,
                                $nuevo_saldo_destino,
                                'Transferencia de ' . $cuenta['numero_cuenta'],
                                $destino['id']
                            ]);

                            $pdo->commit();
                            $resultado = ['exito' => true,
                                'mensaje' => 'Transferencia de ' . formatoPeso($monto) . ' realizada a la cuenta ' . $destino['numero_cuenta']];
                            $cuenta['saldo_actual'] = $nuevo_saldo_origen;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $resultado = ['exito' => false, 'mensaje' => 'Error al procesar: ' . $e->getMessage()];
                        }
                    }
                }
            }
        }
    }
}

// Títulos e iconos por tipo
$titulos = [
    'consignacion' => ['titulo' => 'Consignación', 'icono' => '💰', 'sub' => 'Deposita dinero en tu cuenta'],
    'retiro'       => ['titulo' => 'Retiro',        'icono' => '💳', 'sub' => 'Retira dinero de tu cuenta'],
    'transferencia'=> ['titulo' => 'Transferencia', 'icono' => '🔄', 'sub' => 'Transfiere a otra cuenta Bancolombia'],
];
$info = $titulos[$tipo];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $info['titulo'] ?> — Bancolombia</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<nav class="navbar">
    <a href="dashboard.php" class="navbar-brand">
        <div class="logo-icon">B</div>
        <span>Bancolombia</span>
    </a>
    <div class="navbar-right">
        <span class="navbar-user">Hola, <strong><?= htmlspecialchars($_SESSION['nombre']) ?></strong></span>
        <a href="../includes/logout.php" class="btn-logout">Cerrar sesión</a>
    </div>
</nav>

<div class="container">
    <!-- Tabs de tipo de transacción -->
    <div style="display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap;">
        <a href="?tipo=consignacion" class="btn <?= $tipo==='consignacion'?'btn-primary':'btn-secondary' ?>">💰 Consignación</a>
        <a href="?tipo=retiro"       class="btn <?= $tipo==='retiro'      ?'btn-primary':'btn-secondary' ?>">💳 Retiro</a>
        <a href="?tipo=transferencia"class="btn <?= $tipo==='transferencia'?'btn-primary':'btn-secondary' ?>">🔄 Transferencia</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="grid-responsive">

        <!-- Formulario -->
        <div class="card">
            <div class="card-title"><?= $info['icono'] ?> <?= $info['titulo'] ?></div>
            <p style="font-size:13px;color:#888;margin-bottom:20px;"><?= $info['sub'] ?></p>

            <?php if ($resultado): ?>
                <div class="msg <?= $resultado['exito'] ? 'msg-exito' : 'msg-error' ?>">
                    <?= htmlspecialchars($resultado['mensaje']) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?php if ($tipo === 'transferencia'): ?>
                <div class="form-group">
                    <label>Número de cuenta destino</label>
                    <input type="text" name="numero_destino"
                           placeholder="Ej: 1234567890"
                           value="<?= htmlspecialchars($_POST['numero_destino'] ?? '') ?>"
                           required>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Monto (COP)</label>
                    <input type="number" name="monto" min="1000" step="1000"
                           placeholder="Ej: 500000"
                           value="<?= htmlspecialchars($_POST['monto'] ?? '') ?>"
                           required>
                </div>
                <div class="form-group">
                    <label>Descripción (opcional)</label>
                    <input type="text" name="descripcion" maxlength="200"
                           placeholder="Ej: Pago arriendo"
                           value="<?= htmlspecialchars($_POST['descripcion'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-full">
                    Confirmar <?= $info['titulo'] ?>
                </button>
            </form>
        </div>

        <!-- Info de cuenta -->
        <div>
            <div class="card">
                <div class="card-title">Tu cuenta</div>
                <div style="font-size:28px;font-weight:700;color:#1A1A1A;margin-bottom:4px;">
                    <?= formatoPeso($cuenta['saldo_actual']) ?>
                </div>
                <div style="font-size:13px;color:#888;margin-bottom:16px;">Saldo disponible</div>
                <div style="font-size:13px;color:#555;">
                    <div style="margin-bottom:6px;">
                        <strong>Cuenta:</strong> <?= htmlspecialchars($cuenta['numero_cuenta']) ?>
                    </div>
                    <div style="margin-bottom:6px;">
                        <strong>Tipo:</strong> <?= htmlspecialchars($cuenta['tipo']) ?>
                    </div>
                    <?php if ($cuenta['cupo_sobregiro'] > 0): ?>
                    <div style="margin-bottom:6px;">
                        <strong>Cupo sobregiro:</strong> <?= formatoPeso($cuenta['cupo_sobregiro']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <a href="dashboard.php" class="btn btn-secondary btn-full">← Volver al inicio</a>
        </div>
    </div>
</div>

<style>
@media(max-width:640px){
  .grid-responsive{grid-template-columns:1fr!important;}
}
</style>
</body>
</html>