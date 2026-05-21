<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validar sesión de cliente en Bancolombia
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'CLIENTE' || $_SESSION['entidad'] !== 'BANCOLOMBIA') {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['nombre'];
$pdo = getDB();

// Capturar fechas del filtro (Por defecto toma los últimos 30 días si están vacías)
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

// Ajustar formato para incluir todo el día en la consulta SQL (horas/minutos/segundos)
$fecha_desde_sql = $fecha_desde . ' 00:00:00';
$fecha_hasta_sql = $fecha_hasta . ' 23:59:59';

try {
    // 1. Consultar datos básicos del usuario utilizando tu columna exacta 'documento'
    $stmtUser = $pdo->prepare("SELECT documento, email, celular FROM usuarios WHERE id = ?");
    $stmtUser->execute([$usuario_id]);
    $datosUsuario = $stmtUser->fetch();

    // 2. Obtener el saldo actual de la cuenta (último movimiento registrado)
    $stmtSaldo = $pdo->prepare("SELECT saldo_despues FROM transacciones WHERE producto_tipo = 'BANCOLOMBIA' AND producto_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
    $stmtSaldo->execute([$usuario_id]);
    $resSaldo = $stmtSaldo->fetch();
    $saldo_actual = $resSaldo ? floatval($resSaldo['saldo_despues']) : 3000000;

    // 3. Consultar la lista de movimientos FILTRADA por el rango de fechas elegido
    $stmtMovs = $pdo->prepare("SELECT id, monto, tipo_movimiento, concepto, fecha, saldo_despues 
                               FROM transacciones 
                               WHERE producto_tipo = 'BANCOLOMBIA' AND producto_id = ? 
                               AND fecha BETWEEN ? AND ? 
                               ORDER BY fecha DESC, id DESC");
    $stmtMovs->execute([$usuario_id, $fecha_desde_sql, $fecha_hasta_sql]);
    $movimientos = $stmtMovs->fetchAll();

    // 4. Calcular métricas del periodo (Total Ingresos y Total Egresos) para darle nivel al reporte
    $total_ingresos = 0;
    $total_egresos = 0;
    foreach ($movimientos as $m) {
        if (strtoupper($m['tipo_movimiento']) === 'CREDITO') {
            $total_ingresos += floatval($m['monto']);
        } else {
            $total_egresos += floatval($m['monto']);
        }
    }

} catch (Exception $e) {
    die("Error al generar el extracto: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bancolombia - Extracto de Cuenta</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f4f7; color: #333; }
        
        /* Navbar Corporativo */
        .navbar { background: #ffd100; padding: 15px 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .navbar .brand { font-weight: bold; font-size: 20px; color: #000; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .navbar .brand .logo-square { background: #000; color: #ffd100; padding: 2px 8px; border-radius: 4px; }
        .navbar .btn-back { background: #000; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: bold; }

        .container { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        
        .card { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card h2 { font-size: 18px; color: #000; margin-bottom: 15px; border-bottom: 2px solid #ffd100; padding-bottom: 6px; }

        /* Formulario de Filtros */
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .form-group { flex: 1; min-width: 180px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px; color: #666; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; outline: none; }
        .form-group input:focus { border-color: #ffd100; }
        
        .btn-filter { padding: 11px 24px; background: #ffd100; border: none; border-radius: 6px; font-size: 14px; font-weight: bold; color: #000; cursor: pointer; transition: background 0.2s; }
        .btn-filter:hover { background: #e0ad00; }
        .btn-print { padding: 11px 24px; background: #64748b; border: none; border-radius: 6px; font-size: 14px; font-weight: bold; color: #fff; cursor: pointer; text-decoration: none; display: inline-block; text-align: center; }

        /* Resumen Informativo */
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .summary-item { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-left: 5px solid #64748b; }
        .summary-item.in { border-left-color: #16a34a; }
        .summary-item.out { border-left-color: #dc2626; }
        .summary-item p { color: #666; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .summary-item .value { font-size: 22px; font-weight: bold; color: #000; margin-top: 5px; }

        /* Tabla de Extracto */
        table { width: 100%; border-collapse: collapse; text-align: left; margin-top: 10px; }
        th, td { padding: 12px 16px; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #fafafa; font-weight: 600; color: #555; }
        .monto-credito { color: #16a34a; font-weight: bold; }
        .monto-debito { color: #dc2626; font-weight: bold; }
        
        /* Ocultar elementos al imprimir */
        @media print {
            .navbar, .filter-form, .btn-print, .btn-back { display: none !important; }
            body { background: #fff; }
            .card { box-shadow: none; padding: 0; margin-bottom: 20px; }
        }
    </style>
</head>
<body>

    <div class="navbar">
        <a href="dashboard.php" class="brand"><span class="logo-square">B</span> Bancolombia — Extractos</a>
        <div>
            <a href="dashboard.php" class="btn-back"> Volver al Panel</a>
        </div>
    </div>

    <div class="container">
        
        <div class="card">
            <h2>Filtro de Movimientos por Periodo</h2>
            <form action="extracto.php" method="GET" class="filter-form">
                <div class="form-group">
                    <label>Fecha Inicial (Desde)</label>
                    <input type="date" name="fecha_desde" value="<?php echo htmlspecialchars($fecha_desde); ?>" required>
                </div>
                <div class="form-group">
                    <label>Fecha Final (Hasta)</label>
                    <input type="date" name="fecha_hasta" value="<?php echo htmlspecialchars($fecha_hasta); ?>" required>
                </div>
                <button type="submit" class="btn-filter">Aplicar Filtro</button>
                <a href="#" onclick="window.print();" class="btn-print">🖨️ Imprimir Reporte</a>
            </form>
        </div>

        <div class="card" style="border-top: 6px solid #ffd100;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div>
                    <h3 style="font-size: 22px; color: #000;"><?php echo htmlspecialchars($nombre_usuario); ?></h3>
                    <p style="font-size: 13px; color: #666; margin-top: 4px;"><b>Documento:</b> ID-<?php echo htmlspecialchars($datosUsuario['documento']); ?></p>
                    <p style="font-size: 13px; color: #666;"><b>Correo:</b> <?php echo htmlspecialchars($datosUsuario['email']); ?></p>
                </div>
                <div style="text-align: right;">
                    <p style="font-size: 12px; color: #666; font-weight: bold; text-transform: uppercase;">Periodo Consultado</p>
                    <p style="font-size: 14px; font-weight: bold; color: #000; margin-top: 2px;"><?php echo $fecha_desde; ?> al <?php echo $fecha_hasta; ?></p>
                </div>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-item">
                <p>Saldo al Cierre del Filtro</p>
                <div class="value">$ <?php echo number_format($saldo_actual, 0, ',', '.'); ?> COP</div>
            </div>
            <div class="summary-item in">
                <p>(+) Total Ingresos</p>
                <div class="value" style="color: #16a34a;">$ <?php echo number_format($total_ingresos, 0, ',', '.'); ?> COP</div>
            </div>
            <div class="summary-item out">
                <p>(-) Total Egresos</p>
                <div class="value" style="color: #dc2626;">$ <?php echo number_format($total_egresos, 0, ',', '.'); ?> COP</div>
            </div>
        </div>

        <div class="card">
            <h2>Relación de Operaciones Bancarias</h2>
            <?php if (count($movimientos) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha / Hora</th>
                            <th>Descripción del Concepto</th>
                            <th>Tipo</th>
                            <th>Valor Operación</th>
                            <th>Saldo Resultante</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimientos as $mov): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($mov['fecha']); ?></td>
                            <td><?php echo htmlspecialchars($mov['concepto']); ?></td>
                            <td>
                                <span style="font-size: 11px; font-weight: bold; text-transform: uppercase;">
                                    <?php echo $mov['tipo_movimiento']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="<?php echo strtolower($mov['tipo_movimiento']) === 'credito' ? 'monto-credito' : 'monto-debito'; ?>">
                                    <?php echo strtolower($mov['tipo_movimiento']) === 'credito' ? '+' : '-'; ?> $<?php echo number_format($mov['monto'], 0, ',', '.'); ?>
                                </span>
                            </td>
                            <td style="color: #475569; font-weight: 500;">$<?php echo number_format($mov['saldo_despues'], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: #666; font-size: 14px; text-align: center; padding: 30px 0;">No se registraron movimientos en el rango de fechas seleccionado.</p>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>