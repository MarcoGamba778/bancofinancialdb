<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// IF-07: Control de acceso seguro exclusivo para clientes Nequi
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['entidad']) || $_SESSION['entidad'] !== 'NEQUI') {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['nombre'] ?? 'Usuario Nequi';

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$bolsillos = [];
$movimientos = [];
$llaves_propias = [];
$saldoDisponible = 0;

try {
    $pdo = getDB();

    // 1. IF-08: Cálculo dinámico del saldo real disponible (CREDITOS - DEBITOS)
    $stmtC = $pdo->prepare("SELECT SUM(monto) as total FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? AND tipo_movimiento = 'CREDITO'");
    $stmtC->execute([$usuario_id]);
    $creditos = $stmtC->fetch()['total'] ?? 0;

    $stmtD = $pdo->prepare("SELECT SUM(monto) as total FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? AND tipo_movimiento = 'DEBITO'");
    $stmtD->execute([$usuario_id]);
    $debitos = $stmtD->fetch()['total'] ?? 0;

    $saldoBase = 1570000; 
    $saldoDisponible = $saldoBase + ($creditos - $debitos);

    // 2. IF-10: Consulta de bolsillos vinculada a las columnas reales de tu BD
    try {
        $stmtBolsillos = $pdo->prepare("SELECT id, usuario_id, nombre, monto_meta, saldo_actual, fecha_creacion FROM bolsillos WHERE usuario_id = ?");
        $stmtBolsillos->execute([$usuario_id]);
        $bolsillos = $stmtBolsillos->fetchAll();
    } catch (Exception $e) {
        $bolsillos = [];
    }

    // 3. IF-11: Historial de movimientos completo de la billetera
    try {
        $stmtMovimientos = $pdo->prepare("SELECT id, monto, tipo_movimiento, concepto, fecha FROM transacciones WHERE producto_tipo = 'NEQUI' AND producto_id = ? ORDER BY fecha DESC, id DESC");
        $stmtMovimientos->execute([$usuario_id]);
        $movimientos = $stmtMovimientos->fetchAll();
    } catch (Exception $e) {
        $movimientos = [];
    }

    // 4. IF-12: Consulta de llaves Bre-B registradas por este usuario
    try {
        $stmtLlaves = $pdo->prepare("SELECT id, tipo_llave, valor_llave, fecha_registro FROM llaves_breb WHERE usuario_id = ? ORDER BY fecha_registro DESC");
        $stmtLlaves->execute([$usuario_id]);
        $llaves_propias = $stmtLlaves->fetchAll();
    } catch (Exception $e) {
        $llaves_propias = [];
    }

} catch (Exception $e) {
    die("Error crítico en el Home de Nequi: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nequi - Tu plata a tu ritmo</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        .bg-nequi-dark { background-color: #1e002e; }
        .bg-nequi-purple { background-color: #2d0044; }
        .bg-nequi-nav { background-color: #5c0080; }
        .brand-pink { color: #ff007f; }
        .bg-pink-nequi { background-color: #ff007f; }
    </style>
</head>
<body class="bg-nequi-dark text-white min-h-screen">

    <nav class="bg-nequi-nav px-8 py-4 flex justify-between items-center shadow-xl">
        <div class="text-2xl font-bold tracking-wider">NEQUI<span class="text-green-400">✓</span></div>
        <div class="flex items-center gap-4">
            <span class="text-sm">Hola, <b class="capitalize"><?php echo htmlspecialchars($nombre_usuario); ?></b></span>
            <a href="../admin/logout.php" class="bg-pink-nequi hover:bg-pink-600 px-4 py-1.5 rounded-full text-xs font-bold transition-all shadow-md">Salir</a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 mt-6">
        <?php if (!empty($success)): ?>
            <div class="bg-emerald-950 border-l-4 border-emerald-500 text-emerald-300 p-4 rounded-r-lg mb-4 text-sm shadow-md">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="bg-rose-950 border-l-4 border-rose-500 text-rose-300 p-4 rounded-r-lg mb-4 text-sm shadow-md">✗ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
    </div>

    <main class="max-w-7xl mx-auto px-4 grid grid-cols-1 lg:grid-cols-3 gap-8 pb-12">
        
        <div class="lg:col-span-2 space-y-8">
            <div class="bg-nequi-purple rounded-2xl p-6 shadow-2xl border border-white/5">
                
                <div class="flex border-b border-purple-900/50 mb-6 gap-2">
                    <button id="tab-enviar" onclick="switchTab('enviar')" class="py-2 px-4 border-b-2 border-pink-500 text-white font-medium text-sm transition-all cursor-pointer">📱 Enviar Plata</button>
                    <button id="tab-bolsillo" onclick="switchTab('bolsillo')" class="py-2 px-4 text-purple-300 hover:text-white font-medium text-sm transition-all cursor-pointer">💼 Crear Bolsillo</button>
                    <button id="tab-breb" onclick="switchTab('breb')" class="py-2 px-4 text-purple-300 hover:text-white font-medium text-sm transition-all cursor-pointer">⚡ Transferencia Bre-B</button>
                </div>

                <div id="content-enviar" class="block space-y-4">
                    <form action="enviar.php" method="POST" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-purple-200 mb-1">Celular Destino *</label>
                            <input type="text" name="destino" required class="w-full bg-nequi-dark border border-purple-900 rounded-lg p-3 text-sm focus:outline-none focus:border-pink-500" placeholder="Ej: 3101234567">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-purple-200 mb-1">Monto a enviar (COP) *</label>
                            <input type="number" name="monto" required class="w-full bg-nequi-dark border border-purple-900 rounded-lg p-3 text-sm focus:outline-none focus:border-pink-500" placeholder="¿Cuánto deseas enviar?">
                        </div>
                        <button type="submit" class="w-full bg-pink-nequi hover:bg-pink-600 py-3 rounded-full text-sm font-bold shadow-lg transition-all">Enviar Plata</button>
                    </form>
                </div>

                <div id="content-bolsillo" class="hidden space-y-4">
                    <form action="bolsillos.php" method="POST" class="space-y-4">
                        <input type="hidden" name="accion" value="crear_bolsillo">
                        <div>
                            <label class="block text-xs font-semibold text-purple-200 mb-1">Nombre del Bolsillo *</label>
                            <input type="text" name="nombre" required class="w-full bg-nequi-dark border border-purple-900 rounded-lg p-3 text-sm focus:outline-none focus:border-pink-500" placeholder="Ej: Computador, Viaje...">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-purple-200 mb-1">Meta de Ahorro Final (COP) *</label>
                            <input type="number" name="monto_meta" required class="w-full bg-nequi-dark border border-purple-900 rounded-lg p-3 text-sm focus:outline-none focus:border-pink-500" placeholder="Ej: 2000000">
                        </div>
                        <button type="submit" class="w-full bg-pink-nequi hover:bg-pink-600 py-3 rounded-full text-sm font-bold shadow-lg transition-all">Crear Bolsillo en BD</button>
                    </form>
                </div>

                <div id="content-breb" class="hidden space-y-4">
                    <form action="procesar_breb.php" method="POST" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-purple-200 mb-1">Tipo de Llave Bre-B Destino *</label>
                            <select name="tipo_llave" class="w-full bg-nequi-dark border border-purple-900 rounded-lg p-3 text-sm focus:outline-none focus:border-pink-500 text-white">
                                <option value="CELULAR">Número de Celular</option>
                                <option value="CEDULA">Cédula de Ciudadanía</option>
                                <option value="EMAIL">Correo Electrónico</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-purple-200 mb-1">Escribe la Llave Destino *</label>
                            <input type="text" name="llave_destino" required class="w-full bg-nequi-dark border border-purple-900 rounded-lg p-3 text-sm focus:outline-none focus:border-pink-500" placeholder="Ej: 3127654321 o 1045223">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-purple-200 mb-1">Monto a transferir (COP) *</label>
                            <input type="number" name="monto" required class="w-full bg-nequi-dark border border-purple-900 rounded-lg p-3 text-sm focus:outline-none focus:border-pink-500" placeholder="Monto por interoperabilidad">
                        </div>
                        <button type="submit" class="w-full bg-pink-nequi hover:bg-pink-600 py-3 rounded-full text-sm font-bold shadow-lg transition-all">⚡ Ejecutar Transferencia Bre-B</button>
                    </form>
                </div>

            </div>

            <div class="bg-nequi-purple rounded-2xl p-6 shadow-2xl border border-white/5">
                <h2 class="text-lg font-bold text-white mb-4 flex items-center gap-2">📊 Historial de Movimientos Completo</h2>
                <?php if (count($movimientos) > 0): ?>
                    <div class="overflow-x-auto rounded-xl border border-purple-900/40">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-black/30 text-pink-500 font-semibold uppercase text-xs">
                                <tr>
                                    <th class="p-4">Fecha</th>
                                    <th class="p-4">Concepto</th>
                                    <th class="p-4">Tipo</th>
                                    <th class="p-4 text-right">Monto</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-purple-950/50">
                                <?php foreach ($movimientos as $mov): ?>
                                <tr class="hover:bg-white/5 transition-colors">
                                    <td class="p-4 text-purple-200 text-xs"><?php echo htmlspecialchars($mov['fecha']); ?></td>
                                    <td class="p-4 font-medium text-white"><?php echo htmlspecialchars($mov['concepto']); ?></td>
                                    <td class="p-4">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php echo strtolower($mov['tipo_movimiento']) === 'credito' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400'; ?>">
                                            <?php echo $mov['tipo_movimiento']; ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-right font-bold <?php echo strtolower($mov['tipo_movimiento']) === 'credito' ? 'text-emerald-400' : 'text-rose-400'; ?>">
                                        <?php echo strtolower($mov['tipo_movimiento']) === 'credito' ? '+' : '-'; ?> $<?php echo number_format($mov['monto'], 0, ',', '.'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-purple-300 text-sm text-center py-4">No hay transacciones registradas en esta billetera.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="space-y-6">
            
            <div class="bg-nequi-purple rounded-2xl p-6 border-l-4 border-pink-500 shadow-2xl relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 opacity-10 text-6xl font-bold">$$</div>
                <p class="text-xs uppercase font-bold tracking-wider text-purple-300">Disponible Real</p>
                <div class="text-4xl font-extrabold mt-2 text-white">$ <?php echo number_format($saldoDisponible, 0, ',', '.'); ?></div>
            </div>

            <div class="bg-nequi-purple rounded-2xl p-6 shadow-2xl border border-white/5">
                <h3 class="text-md font-bold text-pink-500 mb-4 flex items-center gap-2">💼 Mis Bolsillos Activos</h3>
                <?php if (!empty($bolsillos)): ?>
                    <div class="space-y-4">
                    <?php foreach ($bolsillos as $pocket): 
                        $p_id = $pocket['id'];
                        $p_nombre = $pocket['nombre'];
                        $p_meta = floatval($pocket['monto_meta']);
                        $p_saldo = floatval($pocket['saldo_actual']);
                        $porcentaje = $p_meta > 0 ? min(100, round(($p_saldo / $p_meta) * 100)) : 0;
                    ?>
                        <div class="bg-nequi-dark p-4 rounded-xl border-l-4 border-pink-500/80 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="font-bold capitalize text-white"><?php echo htmlspecialchars($p_nombre); ?></span>
                                <span class="text-pink-500 font-bold"><?php echo $porcentaje; ?>%</span>
                            </div>
                            <div class="text-xs text-purple-300">
                                Guardado: <b>$<?php echo number_format($p_saldo, 0, ',', '.'); ?></b> / Meta: $<?php echo number_format($p_meta, 0, ',', '.'); ?>
                            </div>
                            <div class="w-full bg-white/10 h-2 rounded-full overflow-hidden">
                                <div class="bg-pink-500 h-full transition-all duration-300" style="width: <?php echo $porcentaje; ?>%;"></div>
                            </div>
                            <div class="flex gap-2 pt-2">
                                <form action="bolsillos.php" method="POST" class="flex gap-1 items-center w-2/3">
                                    <input type="hidden" name="accion" value="agregar_plata">
                                    <input type="hidden" name="id_bolsillo" value="<?php echo $p_id; ?>">
                                    <input type="number" name="monto_ahorrar" required min="1000" placeholder="$" class="w-20 bg-nequi-purple border border-pink-500 rounded p-1 text-center text-xs text-white">
                                    <button type="submit" class="bg-purple-900 border border-pink-500 px-2 py-1 rounded text-xs font-bold text-white flex-1 hover:bg-pink-500 transition-all">+ Ahorrar</button>
                                </form>
                                <form action="bolsillos.php" method="POST" class="w-1/3" onsubmit="return confirm('¿Seguro que deseas eliminar este bolsillo? El dinero regresará al Disponible.');">
                                    <input type="hidden" name="accion" value="eliminar_bolsillo">
                                    <input type="hidden" name="id_bolsillo" value="<?php echo $p_id; ?>">
                                    <button type="submit" class="w-full bg-rose-950/50 border border-rose-500 text-rose-300 py-1 rounded text-xs font-medium hover:bg-rose-600 hover:text-white transition-all">Cerrar</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-purple-300 text-xs text-center">No tienes bolsillos activos en tu cuenta.</p>
                <?php endif; ?>
            </div>

            <div class="bg-nequi-purple rounded-2xl p-6 shadow-2xl border border-white/5 space-y-4">
                <h3 class="text-md font-bold text-pink-500 flex items-center gap-2">🔑 Mis Llaves Bre-B</h3>
                
                <form action="llaves_breb.php" method="POST" class="bg-nequi-dark p-3 rounded-xl border border-purple-900/60 space-y-3">
                    <input type="hidden" name="accion" value="registrar_llave">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-purple-300 mb-1">Tipo</label>
                            <select name="tipo_llave" class="w-full bg-nequi-purple text-white rounded p-1.5 text-xs border border-purple-900">
                                <option value="CELULAR">Celular</option>
                                <option value="CEDULA">Cédula</option>
                                <option value="EMAIL">Email</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-purple-300 mb-1">Valor Llave</label>
                            <input type="text" name="valor_llave" required placeholder="Ej: 310..." class="w-full bg-nequi-purple text-white rounded p-1.5 text-xs border border-purple-900 focus:outline-none">
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-purple-900 border border-pink-500 hover:bg-pink-500 text-white text-xs py-1.5 font-bold rounded transition-all">+ Registrar Llave Propia</button>
                </form>

                <div class="space-y-2">
                    <?php if (!empty($llaves_propias)): ?>
                        <?php foreach ($llaves_propias as $llave): ?>
                            <div class="flex justify-between items-center bg-nequi-dark/60 p-3 rounded-lg border border-white/5 text-xs">
                                <div>
                                    <span class="font-bold text-pink-400 text-[10px] uppercase block"><?php echo htmlspecialchars($llave['tipo_llave']); ?></span>
                                    <span class="text-white font-mono"><?php echo htmlspecialchars($llave['valor_llave']); ?></span>
                                </div>
                                <form action="llaves_breb.php" method="POST" onsubmit="return confirm('¿Deseas desvincular esta llave Bre-B del sistema?');">
                                    <input type="hidden" name="accion" value="eliminar_llave">
                                    <input type="hidden" name="id_llave" value="<?php echo $llave['id']; ?>">
                                    <button type="submit" class="text-rose-400 hover:text-rose-600 font-bold px-2 py-1">✕</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-purple-300 text-xs text-center py-2">No tienes llaves interoperables vinculadas.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <script>
        function switchTab(tab) {
            document.getElementById('content-enviar').classList.replace('block', 'hidden');
            document.getElementById('content-bolsillo').classList.replace('block', 'hidden');
            document.getElementById('content-breb').classList.replace('block', 'hidden');
            
            document.getElementById('tab-enviar').className = "py-2 px-4 text-purple-300 hover:text-white font-medium text-sm transition-all cursor-pointer";
            document.getElementById('tab-bolsillo').className = "py-2 px-4 text-purple-300 hover:text-white font-medium text-sm transition-all cursor-pointer";
            document.getElementById('tab-breb').className = "py-2 px-4 text-purple-300 hover:text-white font-medium text-sm transition-all cursor-pointer";
            
            if(tab === 'enviar') {
                document.getElementById('content-enviar').classList.replace('hidden', 'block');
                document.getElementById('tab-enviar').className = "py-2 px-4 border-b-2 border-pink-500 text-white font-medium text-sm transition-all cursor-pointer";
            } else if(tab === 'bolsillo') {
                document.getElementById('content-bolsillo').classList.replace('hidden', 'block');
                document.getElementById('tab-bolsillo').className = "py-2 px-4 border-b-2 border-pink-500 text-white font-medium text-sm transition-all cursor-pointer";
            } else if(tab === 'breb') {
                document.getElementById('content-breb').classList.replace('hidden', 'block');
                document.getElementById('tab-breb').className = "py-2 px-4 border-b-2 border-pink-500 text-white font-medium text-sm transition-all cursor-pointer";
            }
        }
    </script>
</body>
</html>