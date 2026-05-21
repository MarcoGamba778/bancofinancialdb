<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validar que el usuario tenga sesión activa y rol de ADMIN
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'ADMIN') {
    header('Location: /bancofinancialdb/admin/login.php');
    exit;
}

try {
    $pdo = getDB();

    // 1. Total de clientes registrados (Excluyendo al administrador)
    $stmtClientes = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'CLIENTE'");
    $totalClientes = $stmtClientes->fetchColumn();

    // 2. Total de transacciones históricas en el sistema
    $stmtTransacciones = $pdo->query("SELECT COUNT(*) FROM transacciones");
    $totalTransacciones = $stmtTransacciones->fetchColumn();

    // 3. Volumen total movilizado (Suma de todas las transacciones de hoy)
    $stmtVolumen = $pdo->query("SELECT SUM(monto) FROM transacciones WHERE DATE(fecha) = CURDATE()");
    $volumenHoy = $stmtVolumen->fetchColumn() ?? 0;

    // 4. Total de Débitos / Retiros en las últimas 24 horas
    $stmtDebitos = $pdo->query("SELECT COUNT(*) FROM transacciones WHERE tipo_movimiento = 'DEBITO' AND fecha >= NOW() - INTERVAL 1 DAY");
    $debitos24h = $stmtDebitos->fetchColumn();

} catch (Exception $e) {
    die("Error en el servidor de métricas: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - BancoFinancialDB</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; color: #333; display: flex; }
        
        /* Menú Lateral */
        .sidebar { width: 260px; background: #1e293b; min-height: 100vh; padding: 24px; color: #fff; position: fixed; }
        .sidebar h2 { font-size: 20px; margin-bottom: 32px; text-align: center; font-weight: 600; letter-spacing: 0.5px; color: #38bdf8; }
        .sidebar a { display: block; color: #94a3b8; padding: 14px 16px; text-decoration: none; border-radius: 6px; margin-bottom: 8px; font-size: 14px; transition: all 0.3s ease; }
        .sidebar a:hover { background: #334155; color: #f8fafc; }
        .sidebar a.active { background: #0284c7; color: #fff; font-weight: 500; }
        .sidebar .logout { background: #ef4444; color: #fff; margin-top: 40px; text-align: center; font-weight: bold; }
        .sidebar .logout:hover { background: #dc2626; }
        
        /* Contenedor Principal */
        .main-content { flex: 1; margin-left: 260px; padding: 40px; }
        .header { margin-bottom: 40px; }
        .header h1 { font-size: 28px; color: #0f172a; margin-bottom: 8px; }
        .header p { color: #64748b; font-size: 15px; }
        
        /* Rejilla de Tarjetas/Métricas */
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border-left: 5px solid #cbd5e1; transition: transform 0.2s; }
        .card:hover { transform: translateY(-2px); }
        .card.blue { border-left-color: #0284c7; }
        .card.green { border-left-color: #16a34a; }
        .card.purple { border-left-color: #7c3aed; }
        .card.red { border-left-color: #dc2626; }
        .card h3 { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; margin-bottom: 12px; }
        .card .value { font-size: 32px; font-weight: 700; color: #1e293b; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin Portal</h2>
        <a href="panel.php" class="active">📊 Panel Principal</a>
        <a href="clientes.php">👥 Gestión de Clientes</a>
        <a href="monitor_breb.php">🛡️ Monitor Bre-B</a>
        <a href="logout.php" class="logout">Cerrar Sesión</a>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h1>Métricas Globales del Sistema</h1>
            <p>Bienvenido al centro de monitoreo unificado, <b><?php echo htmlspecialchars($_SESSION['nombre']); ?></b>.</p>
        </div>
        
        <div class="metrics-grid">
            <div class="card blue">
                <h3>Clientes Registrados</h3>
                <div class="value"><?php echo $totalClientes; ?></div>
            </div>
            <div class="card purple">
                <h3>Transacciones Totales</h3>
                <div class="value"><?php echo $totalTransacciones; ?></div>
            </div>
            <div class="card green">
                <h3>Volumen Movilizado (Hoy)</h3>
                <div class="value">$ <?php echo number_format($volumenHoy, 0, ',', '.'); ?></div>
            </div>
            <div class="card red">
                <h3>Débitos / Retiros (24h)</h3>
                <div class="value"><?php echo $debitos24h; ?></div>
            </div>
        </div>
    </div>
</body>
</html>