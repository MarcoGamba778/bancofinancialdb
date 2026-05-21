<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'ADMIN') {
    header('Location: /bancofinancialdb/admin/login.php');
    exit;
}

try {
    $pdo = getDB();
    // Consultar el registro unificado de transacciones para el monitor interbancario Bre-B
    $stmt = $pdo->query("SELECT id, producto_tipo, monto, tipo_movimiento, concepto, fecha FROM transacciones ORDER BY fecha DESC LIMIT 15");
    $movimientos = $stmt->fetchAll();
} catch (Exception $e) {
    die("Error al cargar el monitor Bre-B: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Monitor Bre-B - BancoFinancialDB</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; color: #333; display: flex; }
        .sidebar { width: 260px; background: #1e293b; min-height: 100vh; padding: 24px; color: #fff; position: fixed; }
        .sidebar h2 { font-size: 20px; margin-bottom: 32px; text-align: center; color: #38bdf8; border-bottom: 1px solid #334155; padding-bottom: 10px; }
        .sidebar a { display: block; color: #94a3b8; padding: 14px 16px; text-decoration: none; border-radius: 6px; margin-bottom: 8px; font-size: 14px; }
        .sidebar a:hover { background: #334155; color: #f8fafc; }
        .sidebar a.active { background: #7c3aed; color: #fff; }
        .sidebar .logout { background: #ef4444; color: #fff; margin-top: 40px; text-align: center; font-weight: bold; }
        .sidebar .logout:hover { background: #dc2626; }
        .main-content { flex: 1; margin-left: 260px; padding: 40px; }
        .header { margin-bottom: 30px; }
        .header h1 { font-size: 28px; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        th, td { padding: 14px 20px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #334155; color: #fff; font-weight: bold; }
        tr:hover { background: #f9f9f9; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; color: #fff; }
        .badge.nequi { background: #701c7c; }
        .badge.bancolombia { background: #fdc300; color: #000; }
        .badge.credito { background: #16a34a; }
        .badge.debito { background: #dc2626; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin Portal</h2>
        <a href="panel.php">📊 Panel Principal</a>
        <a href="clientes.php">👥 Gestión de Clientes</a>
        <a href="monitor_breb.php" class="active">🛡️ Monitor Bre-B</a>
        <a href="logout.php" class="logout">Cerrar Sesión</a>
    </div>
    <div class="main-content">
        <div class="header">
            <h1>🛡️ Monitor de Enrutamiento Interbancario (Bre-B)</h1>
            <p>Auditoría en tiempo real de transacciones cruzadas entre plataformas y entidades vinculadas.</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID Ref</th>
                    <th>Entidad Origen</th>
                    <th>Tipo Movimiento</th>
                    <th>Monto Procesado</th>
                    <th>Concepto de Operación</th>
                    <th>Fecha de Registro</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimientos as $mov): ?>
                <tr>
                    <td>#<b><?php echo htmlspecialchars($mov['id']); ?></b></td>
                    <td>
                        <span class="badge <?php echo strtolower($mov['producto_tipo']) === 'nequi' ? 'nequi' : 'bancolombia'; ?>">
                            <?php echo htmlspecialchars($mov['producto_tipo']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo strtolower($mov['tipo_movimiento']) === 'credito' ? 'credito' : 'debito'; ?>">
                            <?php echo htmlspecialchars($mov['tipo_movimiento']); ?>
                        </span>
                    </td>
                    <td><b>$ <?php echo number_format($mov['monto'], 0, ',', '.'); ?></b></td>
                    <td><?php echo htmlspecialchars($mov['concepto']); ?></td>
                    <td><?php echo htmlspecialchars($mov['fecha']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>