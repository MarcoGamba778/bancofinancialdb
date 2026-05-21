<?php
// nequi/historial.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/funciones.php';

requireAuth('NEQUI');

$pdo        = getDB();
$usuario_id = $_SESSION['usuario_id'];

$stmt = $pdo->prepare("SELECT * FROM billeteras_nequi WHERE usuario_id = ? LIMIT 1");
$stmt->execute([$usuario_id]);
$billetera = $stmt->fetch();

$fecha_ini = $_GET['fecha_ini'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

$stmtM = $pdo->prepare(
    "SELECT t.*, lb.valor_llave, lb.tipo_llave
     FROM transacciones t
     LEFT JOIN llaves_breb lb ON lb.id = t.llave_breb_id
     WHERE t.billetera_nequi_id = ?
       AND DATE(t.fecha) BETWEEN ? AND ?
     ORDER BY t.fecha DESC"
);
$stmtM->execute([$billetera['id'], $fecha_ini, $fecha_fin]);
$movimientos = $stmtM->fetchAll();

$total_cred = array_sum(array_map(fn($m) => $m['tipo_movimiento']==='CREDITO' ? $m['monto'] : 0, $movimientos));
$total_deb  = array_sum(array_map(fn($m) => $m['tipo_movimiento']==='DEBITO'  ? $m['monto'] : 0, $movimientos));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial — Nequi</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header class="header">
    <a href="home.php" class="header-brand"><div class="n-icon">N</div><span>Nequi</span></a>
    <div class="header-right">
        <span class="header-user">Hola, <strong><?= htmlspecialchars($_SESSION['nombre']) ?></strong></span>
        <a href="../includes/logout.php" class="btn-logout">Salir</a>
    </div>
</header>
<div class="container">
    <div class="page-title">📋 Historial de movimientos</div>
    <div class="page-sub">Billetera <?= htmlspecialchars($billetera['numero_celular']) ?> — Saldo: <?= formatoPeso($billetera['saldo_actual']) ?></div>

    <div class="card">
        <form method="GET" class="filtros">
            <div class="form-group"><label>Fecha inicio</label>
                <input type="date" name="fecha_ini" value="<?= $fecha_ini ?>"></div>
            <div class="form-group"><label>Fecha fin</label>
                <input type="date" name="fecha_fin" value="<?= $fecha_fin ?>" max="<?= date('Y-m-d') ?>"></div>
            <button type="submit" class="btn btn-primary" style="align-self:flex-end;">Filtrar</button>
            <a href="historial.php" class="btn btn-secondary" style="align-self:flex-end;">Limpiar</a>
        </form>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:18px;" class="grid3">
        <div class="card" style="text-align:center;padding:16px;">
            <div style="font-size:11px;color:#aaa;text-transform:uppercase;font-weight:700;margin-bottom:6px;">Créditos</div>
            <div style="font-size:20px;font-weight:800;color:var(--verde);"><?= formatoPeso($total_cred) ?></div>
        </div>
        <div class="card" style="text-align:center;padding:16px;">
            <div style="font-size:11px;color:#aaa;text-transform:uppercase;font-weight:700;margin-bottom:6px;">Débitos</div>
            <div style="font-size:20px;font-weight:800;color:var(--rojo);"><?= formatoPeso($total_deb) ?></div>
        </div>
        <div class="card" style="text-align:center;padding:16px;">
            <div style="font-size:11px;color:#aaa;text-transform:uppercase;font-weight:700;margin-bottom:6px;">Movimientos</div>
            <div style="font-size:20px;font-weight:800;"><?= count($movimientos) ?></div>
        </div>
    </div>

    <div class="card">
        <?php if (empty($movimientos)): ?>
            <p style="color:#aaa;text-align:center;padding:20px;">Sin movimientos en este período.</p>
        <?php else: ?>
        <div class="tabla-wrap">
            <table>
                <thead>
                    <tr><th>Fecha</th><th>Concepto</th><th>Tipo</th><th>Monto</th><th>Saldo después</th></tr>
                </thead>
                <tbody>
                <?php foreach ($movimientos as $m): ?>
                <tr>
                    <td style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></td>
                    <td><?= htmlspecialchars($m['concepto']) ?>
                        <?php if ($m['valor_llave']): ?>
                        <br><small style="color:#aaa;">🔑 <?= htmlspecialchars($m['valor_llave']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge-<?= strtolower($m['tipo_movimiento']) ?>">
                        <?= $m['tipo_movimiento']==='DEBITO' ? '▼ Débito' : '▲ Crédito' ?>
                    </span></td>
                    <td style="font-weight:700;color:<?= $m['tipo_movimiento']==='DEBITO'?'var(--rojo)':'var(--verde)' ?>;">
                        <?= $m['tipo_movimiento']==='DEBITO' ? '-' : '+' ?><?= formatoPeso($m['monto']) ?>
                    </td>
                    <td><?= formatoPeso($m['saldo_despues']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <a href="home.php" class="btn btn-secondary">← Volver</a>
</div>
<style>@media(max-width:600px){.grid3{grid-template-columns:1fr!important;}}</style>
</body>
</html>