<?php
// Inicia sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Verifica que el usuario esté autenticado y pertenezca
 * a la entidad correcta. Si no, redirige al login correspondiente.
 */
function requireAuth(string $entidadRequerida): void {
    if (!isset($_SESSION['usuario_id'])) {
        // No hay sesión → redirigir al login de la entidad
        redirigirLogin($entidadRequerida);
    }

    $entidadUsuario = $_SESSION['entidad'] ?? '';
    $rol = $_SESSION['rol'] ?? '';

    // El admin puede entrar a todo
    if ($rol === 'ADMIN') return;

    // Si la entidad no coincide → intento de acceso cruzado
    if ($entidadUsuario !== $entidadRequerida) {
        redirigirLogin($entidadUsuario);
    }
}

function redirigirLogin(string $entidad): void {
    if ($entidad === 'NEQUI') {
        header('Location: /bancofinancialdb/nequi/login.php');
    } else {
        header('Location: /bancofinancialdb/bancolombia/login.php');
    }
    exit;
}

function cerrarSesion(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: /bancofinancialdb/index.php');
    exit;
}