<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si ya tiene sesión activa como cliente en Nequi, saltar directo al home
if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'CLIENTE' && $_SESSION['entidad'] === 'NEQUI') {
    header('Location: home.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        try {
            $pdo = getDB();
            
            // Consultar el cliente por correo
            $stmt = $pdo->prepare("SELECT id, nombre, password_hash, rol FROM usuarios WHERE email = ? AND rol = 'CLIENTE'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Verificar existencia y validar hash de contraseña
            if ($user && password_verify($password, $user['password_hash'])) {
                // Configurar sesión específica para Nequi
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['nombre'] = $user['nombre'];
                $_SESSION['rol'] = $user['rol'];
                $_SESSION['entidad'] = 'NEQUI';

                header('Location: home.php');
                exit;
            } else {
                $error = 'Número/Correo o clave incorrecta.';
            }
        } catch (Exception $e) {
            $error = 'Error de conexión con la plataforma de pagos.';
        }
    } else {
        $error = 'Por favor, rellena todos los datos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nequi — Entra a tu cuenta</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #11001c; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .nequi-container { background: #fff; width: 100%; max-width: 400px; padding: 40px 30px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.5); text-align: center; }
        .logo-nequi { font-size: 32px; font-weight: bold; color: #ff007f; margin-bottom: 5px; letter-spacing: -1px; }
        .logo-nequi span { color: #00e5ff; }
        h2 { font-size: 20px; color: #222; margin-bottom: 24px; font-weight: 600; }
        .alert { background: #ffe6f0; border-left: 4px solid #ff007f; color: #800040; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: left; }
        .form-group { text-align: left; margin-bottom: 22px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 6px; text-transform: uppercase; }
        .form-group input { width: 100%; padding: 14px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 15px; outline: none; background: #f9f9f9; transition: all 0.3s; }
        .form-group input:focus { border-color: #ff007f; background: #fff; }
        .btn-nequi { width: 100%; padding: 14px; background: #ff007f; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; color: #fff; cursor: pointer; transition: background 0.2s; margin-top: 10px; box-shadow: 0 4px 12px rgba(255, 0, 127, 0.3); }
        .btn-nequi:hover { background: #e60072; }
        .back-link { display: inline-block; margin-top: 24px; font-size: 14px; color: #aaa; text-decoration: none; }
        .back-link:hover { color: #ff007f; }
    </style>
</head>
<body>

<div class="nequi-container">
    <div class="logo-nequi">nequi<span>.</span></div>
    <h2>¿Listo(a) para manejar tu plata?</h2>

    <?php if (!empty($error)): ?>
        <div class="alert">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form action="login.php" method="POST">
        <div class="form-group">
            <label for="email">Celular o Correo</label>
            <input type="email" id="email" name="email" required placeholder="Ingresa tu correo" value="<?php echo htmlspecialchars($email ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="password">Tu Clave</label>
            <input type="password" id="password" name="password" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn-nequi">Entrar</button>
    </form>

    <a href="../index.html" class="back-link">← Volver al inicio</a>
</div>

</body>
</html>