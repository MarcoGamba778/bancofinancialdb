<?php
session_start();

// Si ya hay sesión activa, llevar al portal correcto
if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['entidad'] === 'NEQUI') {
        header('Location: /bancofinancialdb/nequi/home.php');
    } elseif ($_SESSION['rol'] === 'ADMIN') {
        header('Location: /bancofinancialdb/admin/panel.php');
    } else {
        header('Location: /bancofinancialdb/bancolombia/dashboard.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>BancoFinancialDB</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            background: #f0f0f0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            gap: 20px;
        }
        h1 { font-size: 22px; color: #333; margin-bottom: 8px; }
        p  { font-size: 14px; color: #666; margin-bottom: 24px; }
        .portales { display: flex; gap: 20px; flex-wrap: wrap; justify-content: center; }
        .btn {
            padding: 16px 36px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            transition: opacity .2s;
        }
        .btn:hover { opacity: .85; }
        .btn-bancolombia { background: #FFD100; color: #000; }
        .btn-nequi       { background: #5C0080; color: #fff; }
        .btn-admin       { background: #333;    color: #fff; font-size: 13px; padding: 10px 24px; }
    </style>
</head>
<body>
    <h1>BancoFinancialDB</h1>
    <p>Selecciona tu portal para continuar</p>
    <div class="portales">
        <a href="/bancofinancialdb/bancolombia/login.php" class="btn btn-bancolombia">
            🏦 Bancolombia
        </a>
        <a href="/bancofinancialdb/nequi/login.php" class="btn btn-nequi">
            💜 Nequi
        </a>
    </div>
    <a href="/bancofinancialdb/admin/login.php" class="btn btn-admin">
        Acceso Administrador
    </a>
</body>
</html>