<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: Token CSRF inválido o ausente.");
    }

    $empresa = $_POST['empresa'] ?? '';
    $servicio = $_POST['servicio'] ?? '';
    
    if (empty($empresa) || empty($servicio)) {
        die("Error: Faltan datos.");
    }
    
    // API Call
    $url = "http://infra_api:8000/deploy/" . urlencode($empresa) . "/" . urlencode($servicio);
    $token = getenv('API_TOKEN');
    if (!$token) { die("Error: API_TOKEN not configured in environment."); }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'token: ' . $token
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);

    // Si es una petición AJAX (XMLHttpRequest), devolver JSON
    if (!empty($_SERVER['HTTP_X_REQUEST_WITH']) && strtolower($_SERVER['HTTP_X_REQUEST_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        if ($http_code == 200 && isset($data['status']) && $data['status'] == 'success') {
            echo json_encode(['status' => 'success', 'message' => 'Despliegue completado', 'stdout' => $data['stdout']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => $data['stderr'] ?? $data['detail'] ?? 'Error desconocido']);
        }
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Despliegue - TenSaaS</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = { darkMode: 'class' }
            if (localStorage.theme === 'light' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: light)').matches)) {
                document.documentElement.classList.add('light')
            } else {
                document.documentElement.classList.remove('light')
            }
        </script>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Plus Jakarta Sans', sans-serif; transition: background-color 0.3s ease; }
            .light body { background-color: #f1f5f9; color: #0f172a; }
            .dark body { background-color: #0b0f1a; color: #f8fafc; }
            .glass { background: rgba(30, 41, 59, 0.4); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.05); }
            .light .glass { background: rgba(255, 255, 255, 0.7); border: 1px solid rgba(0, 0, 0, 0.05); }
        </style>
    </head>
    <body class="flex items-center justify-center min-h-screen p-6">
        <div class="w-full max-w-2xl glass rounded-[2.5rem] p-12 shadow-2xl relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-indigo-500 to-purple-500"></div>
            <div class="mb-10 text-center">
                <div class="inline-flex p-5 rounded-2xl bg-indigo-500/10 text-indigo-400 mb-6 shadow-xl shadow-indigo-500/10">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                </div>
                <h1 class="text-4xl font-black tracking-tight mb-2">Estado del Despliegue</h1>
                <p class="text-slate-500 text-xs font-bold uppercase tracking-widest opacity-60">Operación en curso para <?php echo htmlspecialchars($empresa); ?></p>
            </div>

            <?php if ($http_code == 200 && isset($data['status']) && $data['status'] == 'success'): ?>
                <div class="bg-green-500/10 border border-green-500/20 p-6 rounded-2xl mb-6">
                    <div class="flex items-center text-green-400 mb-2">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span class="font-bold text-lg">Despliegue completado</span>
                    </div>
                    <p class="text-green-300/80 text-sm">El contenedor se ha iniciado correctamente y ya es accesible desde el panel corporativo.</p>
                </div>
                <div class="bg-black/20 rounded-xl p-4 mb-8">
                    <p class="text-xs font-bold text-slate-500 uppercase mb-2">Salida del Sistema:</p>
                    <pre class="text-[10px] text-slate-300 overflow-x-auto"><?php echo htmlspecialchars($data['stdout'] ?? 'Sin salida disponible.'); ?></pre>
                </div>
            <?php else: ?>
                <div class="bg-red-500/10 border border-red-500/20 p-6 rounded-2xl mb-6">
                    <div class="flex items-center text-red-400 mb-2">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        <span class="font-bold text-lg">Error en el proceso</span>
                    </div>
                    <p class="text-red-300/80 text-sm">No se ha podido completar el despliegue. Por favor, revisa la salida de error a continuación.</p>
                </div>
                <div class="bg-black/20 rounded-xl p-4 mb-8">
                    <p class="text-xs font-bold text-slate-500 uppercase mb-2">Detalles del Error:</p>
                    <pre class="text-[10px] text-red-300/80 overflow-x-auto"><?php echo htmlspecialchars($data['stderr'] ?? $data['detail'] ?? 'Error desconocido.'); ?></pre>
                </div>
            <?php endif; ?>

            <a href="index.php" class="block w-full text-center py-4 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl font-bold transition-all transform hover:-translate-y-1">
                Volver al Dashboard
            </a>
        </div>
    </body>
    </html>
    <?php
}
?>
