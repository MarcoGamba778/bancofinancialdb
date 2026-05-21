<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validar que sea Administrador
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'ADMIN') {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$mensaje = '';
$error = '';

// --- PROCESAR ACCIONES DEL FORMULARIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. REGISTRAR O EDITAR USUARIO
    if (isset($_POST['accion']) && ($_POST['accion'] === 'crear' || $_POST['accion'] === 'editar')) {
        $id = $_POST['id'] ?? null;
        $nombre = trim($_POST['nombre'] ?? '');
        $documento = trim($_POST['documento'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = $_POST['rol'] ?? 'CLIENTE';
        $password = trim($_POST['password'] ?? '');

        if (!empty($nombre) && !empty($documento) && !empty($email)) {
            try {
                if ($_POST['accion'] === 'crear') {
                    // Validar que el correo o documento no existan
                    $chk = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? OR documento = ?");
                    $chk->execute([$email, $documento]);
                    if ($chk->fetch()) {
                        throw new Exception("El documento o el correo ya están registrados.");
                    }

                    // Hash por defecto o el ingresado
                    $clave_final = !empty($password) ? $password : '123456';
                    $hash = password_hash($clave_final, PASSWORD_BCRYPT);

                    $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, documento, celular, email, password_hash, rol, estado) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVO')");
                    $stmt->execute([$nombre, $documento, $celular, $email, $hash, $rol]);
                    $mensaje = "Cliente registrado con éxito. Clave temporal: " . $clave_final;
                } else {
                    // Editar usuario existente
                    if (!empty($password)) {
                        // Si el administrador cambió la contraseña
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, documento = ?, celular = ?, email = ?, password_hash = ?, rol = ? WHERE id = ?");
                        $stmt->execute([$nombre, $documento, $celular, $email, $hash, $rol, $id]);
                    } else {
                        // Conservar contraseña actual
                        $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, documento = ?, celular = ?, email = ?, rol = ? WHERE id = ?");
                        $stmt->execute([$nombre, $documento, $celular, $email, $rol, $id]);
                    }
                    $mensaje = "Datos del usuario actualizados correctamente.";
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = "Por favor, rellena los campos obligatorios (Nombre, Documento, Correo).";
        }
    }

    // 2. CAMBIAR ESTADO (SUSPENDER / ACTIVAR)
    if (isset($_POST['accion']) && $_POST['accion'] === 'cambiar_estado') {
        $id = $_POST['id'] ?? null;
        $nuevo_estado = $_POST['nuevo_estado'] ?? 'ACTIVO';
        
        if ($id) {
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id = ?");
                $stmt->execute([$nuevo_estado, $id]);
                $mensaje = "Estado del usuario modificado a: " . $nuevo_estado;
            } catch (Exception $e) {
                $error = "Error al cambiar el estado: " . $e->getMessage();
            }
        }
    }
}

// --- OBTENER LISTA ACTUALIZADA DE USUARIOS ---
$usuarios = [];
try {
    $stmt = $pdo->query("SELECT id, nombre, documento, celular, email, rol, estado FROM usuarios ORDER BY id DESC");
    $usuarios = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar la lista: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Gestión de Clientes</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; color: #333; display: flex; }
        
        /* Sidebar Estilo Admin unificado */
        .sidebar { width: 260px; height: 100vh; background: #1e293b; color: #fff; padding: 20px; position: fixed; }
        .sidebar h2 { font-size: 20px; margin-bottom: 30px; color: #38bdf8; text-align: center; font-weight: 700; }
        .sidebar a { display: block; color: #cbd5e1; padding: 12px 15px; text-decoration: none; border-radius: 8px; margin-bottom: 8px; font-weight: 500; }
        .sidebar a:hover, .sidebar a.active { background: #0f172a; color: #fff; }
        
        /* Contenido Principal */
        .main-content { margin-left: 260px; flex: 1; padding: 40px; }
        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        h1 { font-size: 26px; color: #0f172a; }
        
        /* Mensajes */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #dcfce7; color: #166534; border-left: 5px solid #22c55e; }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 5px solid #ef4444; }

        /* Formulario y Contenedores */
        .grid-layout { display: grid; grid-template-columns: 1fr; gap: 30px; }
        @media(min-width: 900px) { .grid-layout { grid-template-columns: 320px 1fr; } }
        
        .card { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .card h3 { font-size: 18px; margin-bottom: 15px; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; }
        
        /* Campos del Formulario */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: #475569; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; outline: none; }
        .form-group input:focus { border-color: #38bdf8; }
        
        .btn { width: 100%; padding: 12px; border: none; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-primary:hover { background: #0369a1; }
        .btn-secondary { background: #64748b; color: #fff; margin-top: 5px; }
        
        /* Tabla de Clientes */
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        th { background: #f8fafc; color: #64748b; font-weight: 600; }
        
        /* Badges de Estado */
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-activo { background: #dcfce7; color: #166534; }
        .badge-suspendido { background: #fee2e2; color: #991b1b; }
        .badge-admin { background: #e0f2fe; color: #0369a1; }

        /* Botones de acción en tabla */
        .btn-action { padding: 4px 8px; border-radius: 4px; font-size: 12px; border: none; cursor: pointer; font-weight: 600; margin-right: 5px; }
        .btn-edit { background: #fef08a; color: #854d0e; }
        .btn-susp { background: #fca5a5; color: #991b1b; }
        .btn-act { background: #86efac; color: #166534; }
        
        .action-flex { display: flex; }
    </style>
</head>
<body>

    <div class="sidebar">
        <h2>FinancialAdmin</h2>
        <a href="dashboard.php">📊 Panel Global</a>
        <a href="clientes.php" class="active">👥 Gestión de Clientes</a>
        <a href="monitor.php">🌐 Monitor Bre-B</a>
        <a href="logout.php" style="margin-top: 40px; color: #f87171;">🚪 Cerrar Sesión</a>
    </div>

    <div class="main-content">
        <div class="header-box">
            <h1>Gestión Unificada de Clientes (IF-14)</h1>
            <span style="font-size: 14px; color: #64748b;">Módulo CRUD Operacional</span>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="grid-layout">
            
            <div class="card">
                <h3 id="form-title">Registrar Nuevo Usuario</h3>
                <form action="clientes.php" method="POST" id="user-form">
                    <input type="hidden" name="accion" id="form-accion" value="crear">
                    <input type="hidden" name="id" id="user-id" value="">

                    <div class="form-group">
                        <label for="nombre">Nombre Completo *</label>
                        <input type="text" id="nombre" name="nombre" required placeholder="Ej: Carlos Pérez">
                    </div>

                    <div class="form-group">
                        <label for="documento">Documento de Identidad *</label>
                        <input type="text" id="documento" name="documento" required placeholder="Ej: 10023456">
                    </div>

                    <div class="form-group">
                        <label for="celular">Número de Celular</label>
                        <input type="text" id="celular" name="celular" placeholder="Ej: 3124567890">
                    </div>

                    <div class="form-group">
                        <label for="email">Correo Electrónico *</label>
                        <input type="email" id="email" name="email" required placeholder="correo@mail.com">
                    </div>

                    <div class="form-group">
                        <label for="rol">Rol de Sistema</label>
                        <select id="rol" name="rol">
                            <option value="CLIENTE">CLIENTE</option>
                            <option value="ADMIN">ADMINISTRADOR</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="password" id="pass-label">Contraseña (Opcional)</label>
                        <input type="password" id="password" name="password" placeholder="Por defecto: 123456">
                    </div>

                    <button type="submit" class="btn btn-primary" id="btn-submit">Guardar Registro</button>
                    <button type="button" class="btn btn-secondary" id="btn-cancel" style="display:none;" onclick="resetForm()">Cancelar Edición</button>
                </form>
            </div>

            <div class="card" style="overflow-x: auto;">
                <h3>Clientes y Administradores Registrados</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario / Datos</th>
                            <th>Documento</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Acciones Profesionales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><b>#<?php echo $u['id']; ?></b></td>
                            <td>
                                <b><?php echo htmlspecialchars($u['nombre']); ?></b><br>
                                <span style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($u['email']); ?></span><br>
                                <span style="font-size: 11px; color: #94a3b8;">📱 <?php echo htmlspecialchars($u['celular'] ?: 'N/A'); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($u['documento']); ?></td>
                            <td>
                                <span class="badge <?php echo $u['rol'] === 'ADMIN' ? 'badge-admin' : ''; ?>">
                                    <?php echo $u['rol']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo strtolower($u['estado']) === 'activo' ? 'badge-activo' : 'badge-suspendido'; ?>">
                                    <?php echo htmlspecialchars($u['estado'] ?: 'ACTIVO'); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-flex">
                                    <button class="btn-action btn-edit" onclick="cargarEdicion(<?php echo htmlspecialchars(json_encode($u)); ?>)">Editar</button>
                                    
                                    <form action="clientes.php" method="POST" style="display:inline;">
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                        <?php if (strtolower($u['estado']) === 'suspendido'): ?>
                                            <input type="hidden" name="nuevo_estado" value="ACTIVO">
                                            <button type="submit" class="btn-action btn-act">Activar</button>
                                        <?php else: ?>
                                            <input type="hidden" name="nuevo_estado" value="SUSPENDIDO">
                                            <button type="submit" class="btn-action btn-susp">Suspender</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <script>
        // Función interactiva para pasar datos de la tabla al formulario de edición
        function cargarEdicion(user) {
            document.getElementById('form-title').innerText = "Editar Usuario #" + user.id;
            document.getElementById('form-accion').value = "editar";
            document.getElementById('user-id').value = user.id;
            
            document.getElementById('nombre').value = user.nombre;
            document.getElementById('documento').value = user.documento;
            document.getElementById('celular').value = user.celular;
            document.getElementById('email').value = user.email;
            document.getElementById('rol').value = user.rol;
            
            document.getElementById('pass-label').innerText = "Cambiar Contraseña (Dejar vacío para conservar)";
            document.getElementById('password').placeholder = "••••••••";
            
            document.getElementById('btn-submit').innerText = "Actualizar Cambios";
            document.getElementById('btn-cancel').style.display = "block";
            window.scrollTo({top: 0, behavior: 'smooth'});
        }

        // Restaurar el formulario a modo de creación limpia
        function resetForm() {
            document.getElementById('form-title').innerText = "Registrar Nuevo Usuario";
            document.getElementById('form-accion').value = "crear";
            document.getElementById('user-id').value = "";
            document.getElementById('user-form').reset();
            
            document.getElementById('pass-label').innerText = "Contraseña (Opcional)";
            document.getElementById('password').placeholder = "Por defecto: 123456";
            
            document.getElementById('btn-submit').innerText = "Guardar Registro";
            document.getElementById('btn-cancel').style.display = "none";
        }
    </script>
</body>
</html>