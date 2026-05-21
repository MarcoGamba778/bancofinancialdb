<?php
// bancolombia/llaves_breb.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/funciones.php';

requireAuth('BANCOLOMBIA');

$pdo        = getDB();
$usuario_id = $_SESSION['usuario_id'];

$stmt = $pdo->prepare("SELECT * FROM cuentas_bancolombia WHERE usuario_id = ? LIMIT 1");
$stmt->execute([$usuario_id]);
$cuenta = $stmt->fetch();

$resultado = null;

// Registrar nueva llave
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion     = $_POST['accion'] ?? '';
    $tipo_llave = $_POST['tipo_llave'] ?? '';
    $valor      = trim($_POST['valor_llave'] ?? '');

    if ($accion === 'agregar') {
        if (!$tipo_llave || !$valor) {
            $resultado = ['exito' => false, 'mensaje' => 'Completa todos los campos.'];
        } else {
            try {
                // Verificar que no exista esa llave
                $stmtCheck = $pdo->prepare(
                    "SELECT id FROM llaves_breb WHERE valor_llave = ?"
                );
                $stmtCheck->execute([$valor]);
                if ($stmtCheck->fetch()) {
                    $resultado = ['exito' => false, 'mensaje' => 'Esa llave ya está registrada en el sistema.'];
                } else {
                    $pdo->prepare(
                        "INSERT INTO llaves_breb
                         (usuario_id, tipo_llave, valor_llave, cuenta_bancolombia_id, billetera_nequi_id, activa)
                         VALUES (?, ?, ?, ?, NULL, 1)"
                    )->execute([$usuario_id, $tipo_llave, $valor, $cuenta['id']]);
                    $resultado = ['exito' => true, 'mensaje' => 'Llave Bre-B registrada correctamente.'];
                }
            } catch (PDOException $e) {
                $resultado = ['exito' => false, 'mensaje' => 'Error al guardar.'];
            }
        }
    }

    if ($accion === 'desactivar') {
        $llave_id = (int)($_POST['llave_id'] ?? 0);
        $pdo->prepare(
            "UPDATE llaves_breb SET activa = 0 WHERE id = ? AND usuario_id = ?"
        )->execute([$llave_id, $usuario_id]);
        $resultado = ['exito' => true, 'mensaje' => 'Llave desactivada.'];
    }
}

// Obtener llaves del usuario
$stmtK = $pdo->prepare(
    "SELECT * FROM llaves_breb WHERE usuario_id = ? ORDER BY activa DESC, id DESC"
);
$stmtK->execute([$usuario_id]);
$llaves = $stmtK->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mis llaves Bre-B — Bancolombia</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<nav class="navbar">
    <a href="dashboard.php" class="navbar-brand">
        <div class="logo-icon">B</div><span>Bancolombia</span>
    </a>
    <div class="navbar-right">
        <span class="navbar-user">Hola, <strong><?= htmlspecialchars($_SESSION['nombre']) ?></strong></span>
        <a href="../includes/logout.php" class="btn-logout">Cerrar sesión</a>
    </div>
</nav>
<div class="container">
    <div class="page-title">🔑 Mis llaves Bre-B</div>
    <div class="page-sub">Gestiona las llaves asociadas a tu cuenta Bancolombia</div>

    <?php if ($resultado): ?>
        <div class="msg <?= $resultado['exito'] ? 'msg-exito' : 'msg-error' ?>">
            <?= htmlspecialchars($resultado['mensaje']) ?>
        </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="grid-resp">
        <!-- Formulario nueva llave -->
        <div class="card">
            <div class="card-title">Registrar nueva llave</div>
            <form method="POST">
                <input type="hidden" name="accion" value="agregar">
                <div class="form-group">
                    <label>Tipo de llave</label>
                    <select name="tipo_llave" required>
                        <option value="">Selecciona...</option>
                        <option value="CELULAR">Celular</option>
                        <option value="DOCUMENTO">Número de documento</option>
                        <option value="CORREO">Correo electrónico</option>
                        <option value="CLAVE_PERSONALIZADA">Clave personalizada</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Valor de la llave</label>
                    <input type="text" name="valor_llave" placeholder="Ej: 3001234567">
                </div>
                <button type="submit" class="btn btn-primary btn-full">+ Registrar llave</button>
            </form>
        </div>

        <!-- Lista de llaves -->
        <div class="card">
            <div class="card-title">Mis llaves registradas</div>
            <?php if (empty($llaves)): ?>
                <p style="color:#999;font-size:14px;">No tienes llaves registradas aún.</p>
            <?php else: ?>
            <?php foreach ($llaves as $llave): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:12px 0;border-bottom:1px solid #f0f0f0;">
                <div>
                    <div style="font-weight:600;font-size:14px;">
                        <?= htmlspecialchars($llave['valor_llave']) ?>
                    </div>
                    <div style="font-size:12px;color:#888;">
                        <?= htmlspecialchars($llave['tipo_llave']) ?>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:12px;padding:3px 10px;border-radius:20px;
                        background:<?= $llave['activa'] ? '#d4edda' : '#f8d7da' ?>;
                        color:<?= $llave['activa'] ? '#155724' : '#721c24' ?>;">
                        <?= $llave['activa'] ? 'Activa' : 'Inactiva' ?>
                    </span>
                    <?php if ($llave['activa']): ?>
                    <form method="POST" style="margin:0;"
                          onsubmit="return confirm('¿Desactivar esta llave?');">
                        <input type="hidden" name="accion" value="desactivar">
                        <input type="hidden" name="llave_id" value="<?= $llave['id'] ?>">
                        <button type="submit" class="btn btn-danger"
                                style="padding:4px 10px;font-size:12px;">
                            Desactivar
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <a href="dashboard.php" class="btn btn-secondary" style="margin-top:8px;">← Volver</a>
</div>
<style>@media(max-width:640px){.grid-resp{grid-template-columns:1fr!important;}}</style>
</body>
</html>