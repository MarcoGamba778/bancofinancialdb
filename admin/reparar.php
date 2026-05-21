<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDB();
    
    // 1. Limpiar cualquier administrador previo para evitar duplicados
    $pdo->exec("DELETE FROM usuarios WHERE id = 1 OR rol = 'ADMIN'");
    
    // 2. Generar el hash de forma nativa en PHP (Clave: admin123)
    $password_plana = 'admin123';
    $hash_nativo = password_hash($password_plana, PASSWORD_BCRYPT);
    
    // 3. Insertar el usuario limpio con el hash perfecto recién creado
    $sql = "INSERT INTO usuarios (id, nombre, documento, celular, email, password_hash, rol) 
            VALUES (1, 'Administrador Global', '12345', '3000000000', 'admin@banco.com', ?, 'ADMIN')";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hash_nativo]);
    
    echo "<h2>¡Éxito Total! 🎉</h2>";
    echo "El usuario administrador ha sido reinstalado con un hash nativo perfecto.<br>";
    echo "Contraseña encriptada guardada: <b>" . htmlspecialchars($hash_nativo) . "</b><br><br>";
    echo "<a href='login.php'>Volver al Login e ingresar</a>";

} catch (Exception $e) {
    echo "<h2>❌ Error al reparar:</h2> " . $e->getMessage();
}
?>