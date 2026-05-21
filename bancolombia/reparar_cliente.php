<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDB();
    
    $correo_cliente = 'marco@mail.com';
    $clave_plana = 'marc4412';
    
    // 1. Generar el hash nativo perfecto en PHP
    $hash_nativo = password_hash($clave_plana, PASSWORD_BCRYPT);
    
    // 2. Actualizar UNICAMENTE la contraseña del usuario existente usando su correo
    // De esta manera MySQL no arroja error de llave foránea
    $sql = "UPDATE usuarios SET password_hash = ? WHERE email = ? AND rol = 'CLIENTE'";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hash_nativo, $correo_cliente]);
    
    echo "<h2>¡Cuenta de Cliente Actualizada con Éxito! 🚀</h2>";
    echo "Se ha inyectado el hash correcto sobre el usuario existente de Marco sin alterar sus registros financieros.<br><br>";
    echo "<b>Correo:</b> " . htmlspecialchars($correo_cliente) . "<br>";
    echo "<b>Contraseña Nueva:</b> " . htmlspecialchars($clave_plana) . "<br><br>";
    echo "<a href='login.php'>Ir al Login de Bancolombia</a>";

} catch (Exception $e) {
    echo "<h2>❌ Error en la reparación:</h2> " . $e->getMessage();
}
?>