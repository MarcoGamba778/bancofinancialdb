<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar que el usuario esté logueado y sea un CLIENTE de BANCOLOMBIA
if (!isset($_SESSION['usuario_id']) || $_SESSION['entidad'] !== 'BANCOLOMBIA') {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['nombre'];

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

try {
    $pdo = getDB();

    // 1. Datos del usuario usando los nombres exactos de tus columnas (documento, celular, email)
    $stmtCuenta = $pdo->prepare("SELECT u.documento, u.celular, u.email FROM usuarios u WHERE u.id = ?");
    $stmtCuenta->execute([$usuario_id]);
    $datosUsuario = $stmtCuenta->fetch();

    // 2. Buscar el saldo disponible real basado en el último movimiento transaccional
    $stmtSaldo = $pdo->prepare("SELECT saldo_despues FROM transacciones WHERE producto_tipo = 'BANCOLOMBIA' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
    $stmtSaldo->execute([$usuario_id]);
    $saldoQuery = $stmtSaldo->fetch();
    
    // Si no tiene transacciones previas, le asignamos el saldo inicial por defecto
    $saldoDisponible = $saldoQuery ? $saldoQuery['saldo_despues'] : 3000000;

    // 3. Consultar si este cliente ya tiene una llave Bre-B vinculada y hacia dónde apunta (IF-06)
    $stmtLlave = $pdo->prepare("SELECT tipo_llave, valor_llave, entidad_destino FROM llaves_breb WHERE usuario_id = ?");
    $stmtLlave->execute([$usuario_id]);
    $llaveBreb = $stmtLlave->fetch();

    // 4. Obtener los últimos 5 movimientos para el historial rápido del panel
    $stmtMovimientos = $pdo->prepare("SELECT id, monto, tipo_movimiento, concepto, fecha FROM transacciones WHERE producto_tipo = 'BANCOLOMBIA' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 5");
    $stmtMovimientos->execute([$usuario_id]);
    $movimientos = $stmtMovimientos->fetchAll();

} catch (Exception $e) {
    die("Error crítico en el Dashboard de Bancolombia: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bancolombia - Sucursal Virtual Personas</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f4f7; color: #333; }
        
        .navbar { background: #ffd100; padding: 15px 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .navbar .brand { font-weight: bold; font-size: 20px; color: #000; display: flex; align-items: center; gap: 10px; }
        .navbar .brand .logo-square { background: #000; color: #ffd100; padding: 2px 8px; border-radius: 4px; }
        .navbar .btn-logout { background: #000; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: bold; }
        .navbar .btn-extracto { color: #000; font-weight: bold; text-decoration: none; background: #fff; padding: 7px 14px; border-radius: 6px; font-size: 13px; margin-right: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .navbar .btn-extracto:hover { background: #f8f9fa; }

        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; display: grid; grid-template-columns: 1fr; gap: 30px; }
        @media(min-width: 900px) { .container { grid-template-columns: 1fr 380px; } }
        
        .balance-card { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-top: 6px solid #ffd100; }
        .balance-card p { color: #666; font-size: 13px; text-transform: uppercase; font-weight: bold; }
        .balance-card .amount { font-size: 34px; font-weight: 700; color: #000; margin-top: 5px; }

        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #22c55e; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }

        /* Pestañas de operaciones */
        .tabs-container { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .tabs-header { display: flex; border-bottom: 2px solid #eee; margin-bottom: 20px; gap: 10px; overflow-x: auto; }
        .tab-btn { padding: 10px 15px; border: none; background: none; font-size: 14px; font-weight: 600; color: #666; cursor: pointer; padding-bottom: 12px; white-space: nowrap; }
        .tab-btn.active { color: #000; border-bottom: 3px solid #ffd100; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Formularios */
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; }
        .form-group input, .form-group select { width: 100%; padding: 11px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; outline: none; }
        .form-group input:focus { border-color: #ffd100; }
        
        .btn-submit { width: 100%; padding: 12px; background: #ffd100; border: none; border-radius: 6px; font-size: 14px; font-weight: bold; color: #000; cursor: pointer; }
        .btn-submit:hover { background: #e0ad00; }

        .history-box { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); grid-column: 1 / -1; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px 16px; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #fafafa; font-weight: 600; }
        .monto-credito { color: #16a34a; font-weight: bold; }
        .monto-debito { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>

    <div class="navbar">
        <div class="brand"><span class="logo-square">B</span> Bancolombia Personas</div>
        <div>
            <a href="extracto.php" class="btn-extracto">📄 Ver Extracto</a>
            <span style="margin-right: 15px; color: #000;">Cliente: <b><?php echo htmlspecialchars($nombre_usuario); ?></b></span>
            <a href="../admin/logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <div class="container">
        
        <div>
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="tabs-container">
                <div class="tabs-header">
                    <button class="tab-btn active" onclick="switchTab('consignar')">💵 Consignar</button>
                    <button class="tab-btn" onclick="switchTab('retirar')">🏧 Retirar</button>
                    <button class="tab-btn" onclick="switchTab('transferir')">🔄 Transf. Interna</button>
                    <button class="tab-btn" style="color: #ff007f;" onclick="switchTab('breb')">📱 Enviar Bre-B</button>
                </div>

                <div id="consignar" class="tab-content active">
                    <form action="transar.php" method="POST">
                        <input type="hidden" name="tipo_operacion" value="consignacion">
                        <div class="form-group">
                            <label>Monto a Consignar (COP) *</label>
                            <input type="number" name="monto" required min="1000" placeholder="Ej: 50000">
                        </div>
                        <div class="form-group">
                            <label>Concepto / Descripción</label>
                            <input type="text" name="concepto" placeholder="Ej: Abono de ahorros">
                        </div>
                        <button type="submit" class="btn-submit">Ejecutar Consignación</button>
                    </form>
                </div>

                <div id="retirar" class="tab-content">
                    <form action="transar.php" method="POST">
                        <input type="hidden" name="tipo_operacion" value="retiro">
                        <div class="form-group">
                            <label>Monto a Retirar (COP) *</label>
                            <input type="number" name="monto" required min="1000" placeholder="Ej: 20000">
                        </div>
                        <button type="submit" class="btn-submit">Confirmar Retiro en Cajero</button>
                    </form>
                </div>

                <div id="transferir" class="tab-content">
                    <form action="transar.php" method="POST">
                        <input type="hidden" name="tipo_operacion" value="transferencia_interna">
                        <div class="form-group">
                            <label>Documento del Destinatario (Mismo Banco) *</label>
                            <input type="text" name="documento_destino" required placeholder="Ej: 10023456">
                        </div>
                        <div class="form-group">
                            <label>Monto a Transferir *</label>
                            <input type="number" name="monto" required min="1000" placeholder="Ej: 150000">
                        </div>
                        <div class="form-group">
                            <label>Concepto</label>
                            <input type="text" name="concepto" placeholder="Ej: Pago de cuota">
                        </div>
                        <button type="submit" class="btn-submit">Enviar Transferencia Bancolombia</button>
                    </form>
                </div>

                <div id="breb" class="tab-content">
                    <div style="background: #fff5f8; border-left: 4px solid #ff007f; padding: 10px; font-size: 12px; color: #b91c1c; margin-bottom: 15px; border-radius: 4px;">
                        <b>Ecosistema Interoperable Bre-B:</b> Envía dinero instantáneamente hacia Nequi utilizando la llave del usuario (Celular, Correo o Cédula).
                    </div>
                    <form action="transar.php" method="POST">
                        <input type="hidden" name="tipo_operacion" value="transferencia_breb">
                        <div class="form-group">
                            <label>Ingresa la Llave Bre-B del Destinatario *</label>
                            <input type="text" name="llave_breb" required placeholder="Celular, Correo o Cédula (Ej: diego@mail.com)">
                        </div>
                        <div class="form-group">
                            <label>Monto a Enviar vía Bre-B *</label>
                            <input type="number" name="monto" required min="1000" placeholder="Ej: 45000">
                        </div>
                        <button type="submit" class="btn-submit" style="background: #ff007f; color: #fff;">Enviar por Bre-B 🚀</button>
                    </form>
                </div>
            </div>

            <div class="tabs-container" style="margin-top: 25px; border: 1px solid #ffd100;">
                <h3 style="font-size: 16px; margin-bottom: 12px; color: #000;">🔑 Mi Llave Bre-B Propia</h3>
                
                <?php if ($llaveBreb): ?>
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 8px;">
                        <p style="font-size: 14px; color: #166534;">Tienes una llave activa vinculada a este banco:</p>
                        <div style="margin: 10px 0; font-size: 16px;">
                            <strong>Tipo:</strong> <?php echo htmlspecialchars($llaveBreb['tipo_llave']); ?> | 
                            <strong>Llave:</strong> <span style="background:#ffd100; padding: 2px 6px; border-radius:4px; font-weight:bold;"><?php echo htmlspecialchars($llaveBreb['valor_llave']); ?></span>
                        </div>
                        <p style="font-size: 12px; color: #666;">Cualquier persona que use esta llave en la red financiera te enviará el dinero directo a esta cuenta de Bancolombia.</p>
                        
                        <form action="../config/procesar_llaves.php" method="POST" style="margin-top: 12px;">
                            <input type="hidden" name="accion" value="eliminar_llave">
                            <button type="submit" style="background:#dc2626; color:#fff; border:none; padding:8px 14px; border-radius:6px; font-size:12px; font-weight:bold; cursor:pointer;">Desvincular Llave Bre-B</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px;">
                        <p style="font-size: 13px; color: #475569; margin-bottom: 12px;">Aún no has registrado ninguna llave Bre-B para recibir dinero directo en tu cuenta de Bancolombia.</p>
                        
                        <form action="../config/procesar_llaves.php" method="POST">
                            <input type="hidden" name="accion" value="vincular_llave">
                            <div class="form-group">
                                <label>Selecciona qué dato deseas usar como llave:</label>
                                <select name="tipo_llave">
                                    <option value="CELULAR">Mi Celular (<?php echo htmlspecialchars($datosUsuario['celular'] ?? ''); ?>)</option>
                                    <option value="CORREO">Mi Correo Electrónico (<?php echo htmlspecialchars($datosUsuario['email'] ?? ''); ?>)</option>
                                    <option value="DOCUMENTO">Mi Documento de Identidad (<?php echo htmlspecialchars($datosUsuario['documento'] ?? ''); ?>)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Confirma el valor exacto de tu llave:</label>
                                <input type="text" name="valor_llave" required placeholder="Escribe tu número o correo aquí para confirmar">
                            </div>
                            <button type="submit" class="btn-submit" style="background: #000; color: #fff;">Activar y Vincular en Bancolombia</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="balance-card" style="margin-bottom: 20px;">
                <p>Saldo Disponible</p>
                <div class="amount">$ <?php echo number_format($saldoDisponible, 0, ',', '.'); ?> COP</div>
            </div>
            <div class="balance-card" style="font-size: 13px; line-height: 1.6;">
                <p style="margin-bottom: 8px;">Mis Datos de Cuenta</p>
                <div><b>Cédula:</b> <?php echo htmlspecialchars($datosUsuario['documento'] ?? 'N/A'); ?></div>
                <div><b>Celular:</b> <?php echo htmlspecialchars($datosUsuario['celular'] ?: 'N/A'); ?></div>
                <div><b>Email:</b> <?php echo htmlspecialchars($datosUsuario['email'] ?? 'N/A'); ?></div>
            </div>
        </div>

        <div class="history-box">
            <h2>Transacciones Recientes (Últimos 5 movimientos)</h2>
            <?php if (count($movimientos) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Descripción / Concepto</th>
                            <th>Tipo</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimientos as $mov): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($mov['fecha']); ?></td>
                            <td><?php echo htmlspecialchars($mov['concepto']); ?></td>
                            <td><span style="font-size: 11px; font-weight: bold; text-transform: uppercase;"><?php echo $mov['tipo_movimiento']; ?></span></td>
                            <td>
                                <span class="<?php echo strtolower($mov['tipo_movimiento']) === 'credito' ? 'monto-credito' : 'monto-debito'; ?>">
                                    <?php echo strtolower($mov['tipo_movimiento']) === 'credito' ? '+' : '-'; ?> $<?php echo number_format($mov['monto'], 0, ',', '.'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: #666; font-size: 14px; text-align: center; padding: 20px 0;">No se registran movimientos recientes.</p>
            <?php endif; ?>
        </div>

    </div>

    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }
    </script>
</body>
</html>