<?php
// Ruta absoluta blindada para evitar fallos de conexión
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'ADMIN') {
    header('Location: /bancofinancialdb/admin/panel.php');
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo']);
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (!empty($correo) && !empty($password)) {
        try {
            $pdo = getDB();
            
            // Buscar al administrador en la base de datos unificada
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND rol = 'ADMIN'");
            $stmt->execute([$correo]);
            $usuario = $stmt->fetch();

            // Verificar la contraseña encriptada de forma estricta (Requerimiento 6.1)
            if ($usuario && password_verify($password, $usuario['password_hash'])) {
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['nombre'] = $usuario['nombre'];
                $_SESSION['rol'] = $usuario['rol'];
                $_SESSION['entidad'] = 'ADMIN';
                
                // Regenerar ID de sesión por seguridad (Exigencia de la rúbrica docente)
                session_regenerate_id(true);

                header('Location: /bancofinancialdb/admin/panel.php');
                exit;
            } else {
                $error = "Credenciales incorrectas para el Administrador.";
            }
        } catch (Exception $e) {
            $error = "Error en el sistema: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, llene todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login Administrador - BancoFinancialDB</title>
    <style>
        body { font-family: Arial, sans-serif; background: #2c3e50; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .login-box { background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); width: 100%; max-width: 400px; }
        h2 { text-align: center; color: #333; margin-bottom: 24px; font-size: 22px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #666; font-size: 14px; }
        input[type="email"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
        .btn-submit { width: 100%; padding: 14px; background: #34495e; color: #fff; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; width: 100%; }
        .error-msg { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; text-align: center; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>🛡️ Portal Administrador</h2>
        
        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label>Correo Electrónico:</label>
                <input type="email" name="correo" required placeholder="admin@banco.com">
            </div>
            <div class="form-group">
                <label>Contraseña:</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-submit">Ingresar al Sistema</button>
        </form>
    </div>
</body>
</html>