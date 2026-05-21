<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDB();
    
    // Configura los datos profesionales para Diego Samir
    $nuevo_correo = 'samdiego@mail.com'; 
    $nueva_clave_plana = 'diego2026'; // Una clave personalizada para él
    $usuario_id = 4; // El ID de Diego Samir en tu tabla usuarios
    
    // 1. Generar el hash nativo seguro
    $hash_nativo = password_hash($nueva_clave_plana, PASSWORD_BCRYPT);
    
    // 2. Actualizar el email y el password_hash usando su ID único
    $sql = "UPDATE usuarios SET email = ?, password_hash = ? WHERE id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nuevo_correo, $hash_nativo, $usuario_id]);
    
    echo "<h2>¡Datos de Diego Samir Actualizados con Éxito! 👤✨</h2>";
    echo "Los datos ahora tienen total sentido para el proyecto.<br><br>";
    echo "<b>Nuevo Correo:</b> " . htmlspecialchars($nuevo_correo) . "<br>";
    echo "<b>Nueva Clave:</b> " . htmlspecialchars($nueva_clave_plana) . "<br><br>";
    echo "<a href='login.php'>Ir al Login de Nequi</a>";

} catch (Exception $e) {
    echo "<h2>❌ Error al actualizar los datos:</h2> " . $e->getMessage();
}
?>