<?php
/**
 * Muestra un mensaje de éxito o error en pantalla.
 * Lo usarás en todos los formularios.
 */
function mensaje(string $tipo, string $texto): string {
    $colores = [
        'exito' => '#d4edda',
        'error' => '#f8d7da',
        'info'  => '#d1ecf1',
    ];
    $color = $colores[$tipo] ?? '#fff';
    return "<div style='background:$color; padding:10px 16px;
                border-radius:6px; margin:12px 0; font-size:14px;'>
                $texto
            </div>";
}

/**
 * Redirige con un mensaje guardado en sesión.
 */
function redirigirCon(string $url, string $tipo, string $texto): void {
    $_SESSION['msg_tipo'] = $tipo;
    $_SESSION['msg_texto'] = $texto;
    header("Location: $url");
    exit;
}

/**
 * Muestra y limpia el mensaje de sesión si existe.
 */
function mostrarMensajeSesion(): string {
    if (!isset($_SESSION['msg_texto'])) return '';
    $html = mensaje($_SESSION['msg_tipo'], $_SESSION['msg_texto']);
    unset($_SESSION['msg_tipo'], $_SESSION['msg_texto']);
    return $html;
}

/**
 * Formatea un número como pesos colombianos.
 */
function formatoPeso(float $monto): string {
    return '$ ' . number_format($monto, 2, ',', '.');
}