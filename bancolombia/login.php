<?php
require_once __DIR__ . '/../config/database.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        try {
            $pdo = getDB();
            // Ajustado a tus columnas reales de la captura: email, password_hash, rol
            $stmt = $pdo->prepare("SELECT id, nombre, password_hash, rol FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['rol'] === 'CLIENTE' || $user['rol'] === 'ADMIN') {
                    $_SESSION['usuario_id'] = $user['id'];
                    $_SESSION['nombre'] = $user['nombre'];
                    $_SESSION['rol'] = $user['rol'];
                    $_SESSION['entidad'] = 'BANCOLOMBIA'; // Control de acceso por portal

                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = "Acceso denegado para esta entidad.";
                }
            } else {
                $error = "Credenciales incorrectas. Intenta de nuevo.";
            }
        } catch (Exception $e) {
            $error = "Error al conectar con la base de datos.";
        }
    } else {
        $error = "Por favor, llena todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bancolombia — Iniciar sesión</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #1a1a1a; display: flex; justify-content: center; align-items: center; height: 100vh; margin:0; }
        .login-box { background: #fff; padding: 40px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .logo { background: #ffd100; color: #000; width: 50px; height: 50px; line-height: 50px; border-radius: 8px; font-weight: bold; font-size: 24px; margin: 0 auto 15px; }
        h2 { color: #000; margin-bottom: 20px; font-size: 22px; }
        .alert { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; text-align: left; border-left: 4px solid #ef4444; }
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px; color: #333; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
        .btn-submit { width: 100%; padding: 12px; background: #ffd100; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-submit:hover { background: #e0ad00; }
        a { color: #666; text-decoration: none; font-size: 13px; display: inline-block; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="logo">B</div>
        <h2>Bancolombia</h2>
        <p style="color:#666; font-size:14px; margin-bottom:20px;">Ingresa a tu cuenta personal</p>
        
        <?php if (!empty($error)): ?>
            <div class="alert">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label>Correo electrónico</label>
                <input type="email" name="email" required placeholder="ejemplo@mail.com" value="<?php echo htmlspecialchars($email ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-submit">Iniciar sesión</button>
        </form>
        <a href="../index.php">← Volver al inicio</a>
    </div>
</body>
</html>