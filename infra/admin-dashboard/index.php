<?php
session_start();

// Config DB
$db_host = getenv('DB_HOST') ?: 'infra_users_db';
$db_name = getenv('DB_NAME') ?: 'users_db';
$db_user = getenv('DB_USER') ?: 'users_user';
$db_pass = getenv('DB_PASSWORD') ?: 'users_pass';

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die("Error de conexión a BD: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");

$DB_DIR = "/var/www/scripts/databases";

// --- API ACTIONS ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['admin'])) { echo json_encode(['error' => 'Unauthorized']); exit; }

    if ($_GET['action'] === 'get_metrics') {
        $prometheus_url = "http://prometheus_global:9090/api/v1/query";
        $queries = [
            'cpu' => '100 - (avg by (instance) (irate(node_cpu_seconds_total{mode="idle"}[5m])) * 100)',
            'ram' => '(1 - (node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes)) * 100'
        ];
        $results = [];
        foreach ($queries as $key => $query) {
            $url = $prometheus_url . "?query=" . urlencode($query);
            $response = @file_get_contents($url);
            $results[$key] = $response ? round((float)(json_decode($response, true)['data']['result'][0]['value'][1] ?? 0), 2) : 0;
        }
        echo json_encode($results);
        exit;
    }

    if ($_GET['action'] === 'get_real_status') {
        $api_url = "http://infra_api:8000/api/v1/system/status";
        $token = getenv('API_TOKEN') ?: "d7f3e8b1a9c4d2e5f6a7b8c9d0e1f2a3";

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['token: ' . $token]);

        // ✅ FIX: Añadir timeouts para evitar que PHP cuelgue
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);  // Máx 3s para conectar
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);          // Máx 8s para respuesta total

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        header('Content-Type: application/json');

        if ($response === false || empty($response)) {
            // La API no respondió — devolver JSON de error en lugar de vacío
            // El JS sabrá interpretarlo y marcará los servicios como "Unreachable"
            echo json_encode([
                'status' => 'error',
                'message' => 'infra_api no disponible',
                'curl_error' => $curl_error,
                'http_code' => $http_code
            ]);
        } else {
            echo $response;
        }
        exit;
    }

    if ($_GET['action'] === 'destroy_service' && isset($_GET['empresa']) && isset($_GET['servicio'])) {
        $api_url = "http://infra_api:8000/destroy/" . urlencode($_GET['empresa']) . "/" . urlencode($_GET['servicio']);
        $token = getenv('API_TOKEN') ?: "d7f3e8b1a9c4d2e5f6a7b8c9d0e1f2a3";
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['token: ' . $token]);
        $response = curl_exec($ch); curl_close($ch);
        if (json_decode($response, true)['status'] === 'success') {
            $sql = "UPDATE servicios_contratados s JOIN empresas e ON s.empresa_id = e.id SET s.estado = 'eliminado' WHERE e.nombre = ? AND s.nombre_servicio = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ss", $_GET['empresa'], $_GET['servicio']);
            mysqli_stmt_execute($stmt);
        }
        echo $response; exit;
    }
}

// Obtener catálogo disponible
$catalogo_path = "/var/www/catalogo";
$servicios_catalogo = [];
// Obtener servicios ya contratados
$contratados = [];
if (file_exists("$DB_DIR/servicios.txt")) {
    $lines = file("$DB_DIR/servicios.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = explode(':', $line);
        if (count($parts) >= 2) {
            $contratados[] = $parts[1]; // nombre_servicio
        }
    }
}

if (is_dir($catalogo_path)) {
    $dirs = array_filter(glob($catalogo_path . '/*'), 'is_dir');
    foreach ($dirs as $dir) {
        $name = basename($dir);
        // Excluir carpetas internas o de sistema y servicios ya contratados
        if (!in_array($name, ['panel', 'nginx', 'monitorizacion', 'mariadb', 'users-db', 'node-exporter', 'prometheus', 'grafana']) && !in_array($name, $contratados)) {
            $servicios_catalogo[] = $name;
        }
    }
}

// Manejo de Logout
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

// Verificar autenticación
if (!isset($_SESSION['admin'])) {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $admin_pass = $_POST['admin_password'] ?? '';
        $admin_user = $_POST['admin_user'] ?? 'admin';
        $sql = "SELECT id, hash_password, empresa_id, es_admin FROM usuarios WHERE usuario = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $admin_user);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            if (password_verify($admin_pass, $row['hash_password'])) {
                $_SESSION['admin'] = 1; $_SESSION['admin_id'] = $row['id'];
                $_SESSION['empresa_id'] = $row['empresa_id']; $_SESSION['es_admin'] = $row['es_admin'];
                header("Location: index.php"); exit;
            }
        }
        $error = "Credenciales inválidas";
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Login - TenSaaS</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
        :root {
            --bg-mesh: #0b0f1a;
            --glass-bg: rgba(30, 41, 59, 0.4);
            --text-main: #e2e8f0;
        }
        .light {
            --bg-mesh: #f1f5f9;
            --glass-bg: rgba(255, 255, 255, 0.7);
            --text-main: #0f172a;
        }
        body { font-family: 'Plus Jakarta Sans', sans-serif; transition: all 0.3s ease; }
        .mesh-bg {
            background-color: var(--bg-mesh);
            background-image: 
                radial-gradient(at 0% 0%, rgba(79, 70, 229, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(124, 58, 237, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(79, 70, 229, 0.15) 0px, transparent 50%),
                radial-gradient(at 0% 100%, rgba(79, 70, 229, 0.15) 0px, transparent 50%);
        }
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }
        .float-icon { animation: float 6s ease-in-out infinite; }
        .glass { background: var(--glass-bg); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .light .text-white { color: #0f172a !important; }
        .light .text-slate-200 { color: #1e293b !important; }
        .light .text-slate-500 { color: #64748b !important; }
        .light .bg-white\/5 { background-color: rgba(0,0,0,0.05) !important; }
    </style>
    <script>
        // Theme initialization and global functions
        function applyTheme() {
            if (localStorage.theme === 'light' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: light)').matches)) {
                document.documentElement.classList.add('light')
            } else {
                document.documentElement.classList.remove('light')
            }
        }

        function toggleTheme() {
            const isLight = document.documentElement.classList.toggle('light');
            localStorage.theme = isLight ? 'light' : 'dark';
            updateThemeUI();
        }

        function updateThemeUI() {
            const isLight = document.documentElement.classList.contains('light');
            
            // Login icons
            const sunLogin = document.getElementById('sun-icon-login');
            const moonLogin = document.getElementById('moon-icon-login');
            if (sunLogin) sunLogin.classList.toggle('hidden', !isLight);
            if (moonLogin) moonLogin.classList.toggle('hidden', isLight);

            // Dashboard icons
            const sunDash = document.getElementById('sun-icon');
            const moonDash = document.getElementById('moon-icon');
            if (sunDash) sunDash.classList.toggle('hidden', !isLight);
            if (moonDash) moonDash.classList.toggle('hidden', isLight);
        }

        // Bridge for Dashboard object
        window.Dashboard = window.Dashboard || {};
        window.Dashboard.toggleTheme = toggleTheme;
        window.Dashboard.updateThemeUI = updateThemeUI;

        applyTheme();
        window.addEventListener('DOMContentLoaded', updateThemeUI);
    </script>
    <body class="mesh-bg flex items-center justify-center min-h-screen text-slate-200 p-6">
        <div class="absolute top-8 right-8">
            <button onclick="toggleTheme()" class="p-4 glass rounded-2xl text-slate-400 hover:text-indigo-400 transition-all border border-white/5 group shadow-xl">
                <svg id="sun-icon-login" class="w-6 h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 5a7 7 0 100 14 7 7 0 000-14z"></path></svg>
                <svg id="moon-icon-login" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
            </button>
        </div>
        <div class="relative w-full max-w-[480px]">
            <!-- Decorative elements -->
            <div class="absolute -top-20 -left-20 w-64 h-64 bg-indigo-600/20 rounded-full blur-[100px] animate-pulse"></div>
            <div class="absolute -bottom-20 -right-20 w-64 h-64 bg-purple-600/20 rounded-full blur-[100px] animate-pulse"></div>
            
            <div class="relative glass border border-white/10 rounded-[3rem] p-12 shadow-2xl overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500"></div>
                
                <div class="text-center mb-12">
                    <div class="inline-flex p-5 rounded-[2rem] bg-indigo-500/10 text-indigo-400 mb-8 ring-1 ring-indigo-500/20 float-icon">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                    </div>
                    <h1 class="text-5xl font-black text-white tracking-tighter mb-3 italic">TenSaaS</h1>
                    <div class="flex items-center justify-center gap-2">
                        <span class="h-px w-8 bg-slate-800"></span>
                        <p class="text-slate-500 font-bold text-[10px] uppercase tracking-[0.3em]">Infrastructure OS</p>
                        <span class="h-px w-8 bg-slate-800"></span>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="mb-8 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-2xl text-[10px] text-center font-black uppercase tracking-widest">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-8">
                    <div class="space-y-3">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] ml-2">Identificador</label>
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-6 flex items-center pointer-events-none text-slate-500 group-focus-within:text-indigo-400 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            </div>
                            <input type="text" name="admin_user" required 
                                class="w-full pl-14 pr-6 py-5 bg-white/5 border border-white/10 rounded-2xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all placeholder:text-slate-700 font-medium" 
                                placeholder="Usuario administrador">
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] ml-2">Contraseña Maestra</label>
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-6 flex items-center pointer-events-none text-slate-500 group-focus-within:text-indigo-400 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            </div>
                            <input type="password" name="admin_password" required 
                                class="w-full pl-14 pr-6 py-5 bg-white/5 border border-white/10 rounded-2xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all placeholder:text-slate-700 font-medium" 
                                placeholder="••••••••••••">
                        </div>
                    </div>

                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    
                    <button type="submit" class="group relative w-full py-6 bg-indigo-600 hover:bg-indigo-500 text-white font-black rounded-2xl shadow-2xl shadow-indigo-600/30 transition-all transform hover:-translate-y-1 active:scale-[0.98] uppercase tracking-[0.2em] text-[11px] overflow-hidden">
                        <span class="relative z-10 flex items-center justify-center gap-3">
                            Autenticar en el Sistema
                            <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                        </span>
                    </button>
                </form>
                
                <div class="mt-12 pt-8 border-t border-white/5 flex items-center justify-between opacity-40 grayscale hover:grayscale-0 transition-all cursor-default">
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">© 2026 ASIR Cloud</p>
                    <div class="flex gap-4">
                        <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                        <p class="text-[9px] font-bold text-emerald-500 uppercase tracking-widest">Global Node Online</p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php exit;
}

// Data fetching
$stats = [];
if ($_SESSION['es_admin'] == 1) {
    $stats_sql = "SELECT 
        (SELECT COUNT(*) FROM empresas WHERE estado = 'activa') as total_empresas,
        (SELECT COUNT(*) FROM usuarios WHERE estado = 'activo') as total_usuarios,
        (SELECT COUNT(*) FROM servicios_contratados WHERE estado = 'activo') as total_servicios";
    $stats = mysqli_fetch_assoc(mysqli_query($conn, $stats_sql));
}

$where_empresa = $_SESSION['es_admin'] != 1 ? " AND id = ?" : "";
$empresas_sql = "SELECT * FROM empresas WHERE estado = 'activa'" . $where_empresa . " ORDER BY nombre";
$empresas_stmt = mysqli_prepare($conn, $empresas_sql);
if ($_SESSION['es_admin'] != 1) mysqli_stmt_bind_param($empresas_stmt, "i", $_SESSION['empresa_id']);
mysqli_stmt_execute($empresas_stmt);
$empresas_result = mysqli_stmt_get_result($empresas_stmt);

$servicios_por_empresa = [];
$empresas = [];
while ($row = mysqli_fetch_assoc($empresas_result)) {
    $empresas[] = $row;
    $srv_sql = "SELECT * FROM servicios_contratados WHERE empresa_id = ? AND estado = 'activo'";
    $srv_stmt = mysqli_prepare($conn, $srv_sql);
    mysqli_stmt_bind_param($srv_stmt, "i", $row['id']);
    mysqli_stmt_execute($srv_stmt);
    $servicios_por_empresa[$row['id']] = mysqli_fetch_all(mysqli_stmt_get_result($srv_stmt), MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - TenSaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: {
                            bg: '#0b0f1a',
                            card: 'rgba(30, 41, 59, 0.4)',
                            border: 'rgba(255, 255, 255, 0.05)'
                        },
                        light: {
                            bg: '#f8fafc',
                            card: 'rgba(255, 255, 255, 0.8)',
                            border: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                }
            }
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f1a;
            --text-main: #f8fafc;
            --text-muted: #64748b;
            --glass-bg: rgba(30, 41, 59, 0.4);
            --glass-border: rgba(255, 255, 255, 0.05);
            --card-inner: #111827;
        }

        .light {
            --bg-color: #f1f5f9;
            --text-main: #0f172a;
            --text-muted: #475569;
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(0, 0, 0, 0.1);
            --card-inner: #ffffff;
        }

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-color); 
            color: var(--text-main); 
            overflow-x: hidden; 
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .glass { 
            background: var(--glass-bg); 
            backdrop-filter: blur(16px); 
            border: 1px solid var(--glass-border); 
        }
        
        .sidebar-active { 
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.15), transparent); 
            color: #818cf8; 
            border-left: 3px solid #6366f1; 
        }
        
        .service-card-inner { 
            background: var(--card-inner); 
            box-shadow: 0 20px 50px rgba(0,0,0,0.1); 
            border: 1px solid var(--glass-border); 
            transition: all 0.3s ease;
        }
        
        .service-card-inner:hover { border-color: rgba(99, 102, 241, 0.3); }
        
        @keyframes pulse-soft { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.7; transform: scale(0.95); } }
        .animate-pulse-soft { animation: pulse-soft 3s infinite ease-in-out; }
        
        .status-dot-active { background: #10b981; box-shadow: 0 0 10px #10b981; }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: rgba(129, 140, 248, 0.2); border-radius: 10px; }

        /* Dark mode specific overrides */
        .light .text-white { color: #0f172a !important; }
        .light .text-slate-200 { color: #1e293b !important; }
        .light .text-slate-400 { color: #475569 !important; }
        .light .text-slate-500 { color: #64748b !important; }
        .light .bg-white\/5 { background-color: rgba(0, 0, 0, 0.05) !important; }
        .light .border-white\/5 { border-color: rgba(0, 0, 0, 0.05) !important; }
        .light .border-white\/10 { border-color: rgba(0, 0, 0, 0.1) !important; }
    </style>
    <script>
        // Theme initialization and global functions
        function applyTheme() {
            if (localStorage.theme === 'light' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: light)').matches)) {
                document.documentElement.classList.add('light')
            } else {
                document.documentElement.classList.remove('light')
            }
        }

        function toggleTheme() {
            const isLight = document.documentElement.classList.toggle('light');
            localStorage.theme = isLight ? 'light' : 'dark';
            updateThemeUI();
            
            // Notification if Dashboard is ready
            if (window.Dashboard && typeof window.Dashboard.showNotification === 'function') {
                // window.Dashboard.showNotification('Tema Actualizado', `Modo ${isLight ? 'Claro' : 'Oscuro'} activado`, 'success');
            }

            // Update Chart if exists
            if (window.Dashboard && window.Dashboard.metricsChart) {
                const textColor = isLight ? '#475569' : '#94a3b8';
                const gridColor = isLight ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.05)';
                window.Dashboard.metricsChart.options.scales.y.ticks.color = textColor;
                window.Dashboard.metricsChart.options.scales.x.ticks.color = textColor;
                window.Dashboard.metricsChart.options.scales.y.grid.color = gridColor;
                window.Dashboard.metricsChart.options.plugins.legend.labels.color = textColor;
                window.Dashboard.metricsChart.update();
            }
        }

        function updateThemeUI() {
            const isLight = document.documentElement.classList.contains('light');
            
            // Login icons
            const sunLogin = document.getElementById('sun-icon-login');
            const moonLogin = document.getElementById('moon-icon-login');
            if (sunLogin) sunLogin.classList.toggle('hidden', !isLight);
            if (moonLogin) moonLogin.classList.toggle('hidden', isLight);

            // Dashboard icons
            const sunDash = document.getElementById('sun-icon');
            const moonDash = document.getElementById('moon-icon');
            if (sunDash) sunDash.classList.toggle('hidden', !isLight);
            if (moonDash) moonDash.classList.toggle('hidden', isLight);
        }

        // Bridge for Dashboard object
        window.Dashboard = window.Dashboard || {};
        window.Dashboard.toggleTheme = toggleTheme;
        window.Dashboard.updateThemeUI = updateThemeUI;

        applyTheme();
        window.addEventListener('DOMContentLoaded', updateThemeUI);
    </script>
</head>
<body class="h-full flex flex-col lg:flex-row">
    <!-- Sidebar -->
    <aside class="w-full lg:w-80 glass border-r border-white/5 flex flex-col z-20 h-screen sticky top-0">
        <div class="p-8 border-b border-white/5">
            <div class="flex items-center gap-4 group">
                <div class="p-3 bg-indigo-600 rounded-2xl shadow-xl shadow-indigo-600/30 group-hover:rotate-12 transition-transform">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                </div>
                <div>
                    <h2 class="text-xl font-black tracking-tighter">TenSaaS</h2>
                    <span class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Enterprise Platform</span>
                </div>
            </div>
        </div>
        
        <nav class="flex-1 p-6 space-y-8 overflow-y-auto">
            <div>
                <p class="px-4 mb-4 text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">Monitorización</p>
                <div class="space-y-1">
                    <a href="#" class="flex items-center px-4 py-3.5 sidebar-active rounded-xl font-bold text-sm transition-all group">
                        <svg class="w-5 h-5 mr-3 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        Vista General
                    </a>
                    <a href="/portainer/" target="_blank" class="flex items-center px-4 py-3.5 text-slate-400 hover:text-indigo-500 hover:bg-indigo-500/5 rounded-xl text-sm font-bold transition-all">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        Contenedores
                    </a>
                    <a href="/grafana/" target="_blank" class="flex items-center px-4 py-3.5 text-slate-400 hover:text-indigo-500 hover:bg-indigo-500/5 rounded-xl text-sm font-bold transition-all">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        Métricas
                    </a>
                </div>
            </div>

            <div class="p-6 bg-indigo-500/5 rounded-[2rem] border border-indigo-500/10 mt-auto">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></span>
                    <span class="text-[10px] font-black text-indigo-400 uppercase tracking-widest">Estado API</span>
                </div>
                <p class="text-[10px] text-slate-500 leading-relaxed font-medium">Núcleo conectado y operando con latencia de 12ms.</p>
            </div>
        </nav>

        <div class="p-6 border-t border-white/5">
            <a href="?logout=1" class="flex items-center px-6 py-4 bg-red-500/10 hover:bg-red-500/20 text-red-400 rounded-2xl transition-all font-bold text-xs uppercase tracking-widest justify-center border border-red-500/10">
                Cerrar Sesión
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto relative">
        <div class="absolute top-0 left-0 w-full h-96 bg-indigo-600/10 blur-[120px] pointer-events-none"></div>

        <!-- Header -->
        <header class="sticky top-0 z-30 p-8 flex justify-between items-center glass border-b border-white/5">
            <div class="flex items-center gap-8">
                <div>
                    <h1 class="text-3xl font-black tracking-tight text-white">Centro de Mando</h1>
                    <p class="text-slate-500 text-[10px] font-bold mt-1 uppercase tracking-widest opacity-60">Gestión de infraestructura SaaS</p>
                </div>
            </div>
            
            <div class="flex items-center gap-6">
                <!-- Theme Toggle -->
                <button onclick="toggleTheme()" class="p-3 glass rounded-xl text-slate-400 hover:text-indigo-400 transition-all border border-white/5 group">
                    <svg id="sun-icon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 5a7 7 0 100 14 7 7 0 000-14z"></path></svg>
                    <svg id="moon-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                </button>

                <div class="hidden md:flex items-center gap-4 pl-6 border-l border-white/10">
                    <div class="text-right">
                        <p class="text-[9px] font-black text-slate-500 uppercase tracking-[0.2em]">Administrador</p>
                        <p class="text-sm font-black text-indigo-400"><?php echo $_SESSION['es_admin'] ? 'Global Master' : 'Tenant Manager'; ?></p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-lg font-black text-white shadow-xl shadow-indigo-500/20">
                        <?php echo strtoupper(substr($_SESSION['es_admin'] ? 'A' : 'T', 0, 1)); ?>
                    </div>
                </div>
            </div>
        </header>

        <div class="p-10 space-y-16 max-w-[1600px] mx-auto relative">
            
            <!-- Global Stats (Admin Only) -->
            <?php if ($_SESSION['es_admin'] == 1): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="glass p-8 rounded-[2.5rem] relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-500/10 rounded-full blur-3xl -mr-10 -mt-10 group-hover:bg-indigo-500/20 transition-all"></div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Empresas Activas</p>
                            <h3 class="text-5xl font-black text-white"><?php echo $stats['total_empresas']; ?></h3>
                        </div>
                        <div class="p-4 bg-indigo-500/10 rounded-2xl text-indigo-400">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                        </div>
                    </div>
                </div>
                <div class="glass p-8 rounded-[2.5rem] relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-emerald-500/10 rounded-full blur-3xl -mr-10 -mt-10 group-hover:bg-emerald-500/20 transition-all"></div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Servicios Desplegados</p>
                            <h3 class="text-5xl font-black text-white"><?php echo $stats['total_servicios']; ?></h3>
                        </div>
                        <div class="p-4 bg-emerald-500/10 rounded-2xl text-emerald-400">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-7 0V4"></path></svg>
                        </div>
                    </div>
                </div>
                <div class="glass p-8 rounded-[2.5rem] relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-amber-500/10 rounded-full blur-3xl -mr-10 -mt-10 group-hover:bg-amber-500/20 transition-all"></div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Usuarios Totales</p>
                            <h3 class="text-5xl font-black text-white"><?php echo $stats['total_usuarios']; ?></h3>
                        </div>
                        <div class="p-4 bg-amber-500/10 rounded-2xl text-amber-400">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Global Infrastructure Section -->
            <section class="space-y-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-black tracking-tight flex items-center gap-3">
                            <span class="w-3 h-3 rounded-full bg-indigo-500"></span>
                            Nodos Críticos del Sistema
                        </h2>
                        <p class="text-slate-500 text-[10px] font-bold uppercase tracking-widest mt-1">Infraestructura Core y Servicios Base</p>
                    </div>
                    <div class="flex gap-2">
                        <div class="px-4 py-2 bg-indigo-500/10 rounded-full border border-indigo-500/20 text-indigo-400 text-[10px] font-black tracking-[0.2em]">CLUSTER CORE</div>
                    </div>
                </div>
                
                <div id="infra-list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    <!-- Dinámico vía JS -->
                    <div class="animate-pulse flex flex-col gap-2">
                        <div class="h-20 bg-white/5 rounded-2xl"></div>
                        <div class="h-20 bg-white/5 rounded-2xl"></div>
                        <div class="h-20 bg-white/5 rounded-2xl"></div>
                        <div class="h-20 bg-white/5 rounded-2xl"></div>
                    </div>
                </div>
            </section>

            <section class="glass p-10 rounded-[3rem] relative overflow-hidden group">
                <div class="flex items-center justify-between mb-10">
                    <div>
                        <h2 class="text-2xl font-black tracking-tight flex items-center gap-3">
                            <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                            Monitorización de Recursos
                        </h2>
                        <p class="text-slate-500 text-[10px] font-bold uppercase tracking-widest mt-1">Rendimiento en tiempo real del nodo principal</p>
                    </div>
                </div>
                <div class="h-80 w-full">
                    <canvas id="metricsChart"></canvas>
                </div>
            </section>
            <?php endif; ?>

            <!-- Tenant Fleet -->
            <section class="space-y-12">
                <div class="flex items-center justify-between">
                    <h2 class="text-3xl font-black tracking-tight">Flota de Servicios</h2>
                    <span class="px-5 py-2 glass rounded-full text-[10px] font-black text-slate-400 border border-white/5 uppercase tracking-widest"><?php echo count($empresas); ?> Entidades</span>
                </div>

                <div class="grid grid-cols-1 gap-16">
                    <?php foreach ($empresas as $empresa): ?>
                        <div class="relative">
                            <!-- Company Header Card -->
                            <div class="glass p-10 rounded-[3rem] flex flex-col md:flex-row items-center justify-between gap-8 mb-8 border-l-8 border-indigo-600">
                                <div class="flex items-center gap-8">
                                    <div class="w-20 h-20 rounded-[2rem] bg-indigo-600 flex items-center justify-center text-3xl font-black text-white shadow-2xl shadow-indigo-600/30">
                                        <?php echo strtoupper(substr($empresa['nombre'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <h4 class="text-3xl font-black tracking-tighter text-white"><?php echo htmlspecialchars($empresa['nombre']); ?></h4>
                                        <p class="text-indigo-400/60 text-sm font-bold mt-1 italic"><?php echo htmlspecialchars($empresa['descripcion']); ?></p>
                                    </div>
                                </div>
                                
                                <form class="deploy-form flex gap-3 p-3 bg-white/5 rounded-[2rem] border border-white/5 shadow-inner">
                                    <input type="hidden" name="empresa" value="<?php echo htmlspecialchars($empresa['nombre']); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                    <select name="servicio" class="bg-transparent text-xs font-black uppercase tracking-widest px-6 py-4 focus:outline-none appearance-none cursor-pointer text-slate-400">
                                        <option value="" class="bg-[#0b0f1a]">Elegir App...</option>
                                        <?php 
                                        $contratados = array_column($servicios_por_empresa[$empresa['id']] ?? [], 'nombre_servicio');
                                        foreach ($servicios_catalogo as $s): 
                                            if (in_array($s, $contratados)) continue;
                                        ?>
                                            <option value="<?php echo $s; ?>" class="bg-[#0b0f1a]"><?php echo strtoupper($s); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="px-8 py-4 bg-indigo-600 hover:bg-indigo-500 text-white rounded-[1.5rem] text-[10px] font-black uppercase tracking-[0.2em] transition-all shadow-xl shadow-indigo-600/20 active:scale-95">
                                        Desplegar
                                    </button>
                                </form>
                            </div>

                            <!-- Services Grid -->
                            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
                                <?php if (empty($servicios_por_empresa[$empresa['id']])): ?>
                                    <div class="col-span-full py-20 flex flex-col items-center justify-center glass rounded-[3rem] border-2 border-dashed border-white/10 opacity-30">
                                        <svg class="w-16 h-16 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                        <p class="text-lg font-black tracking-tight">Flota Vacía</p>
                                    </div>
                                <?php else: ?>
                                        <?php foreach ($servicios_por_empresa[$empresa['id']] as $servicio): ?>
                                            <?php 
                                                $s_name = strtolower($servicio['nombre_servicio']);
                                                // Mapeo de nombres para el CDN si son diferentes
                                                $cdn_map = [
                                                    'vpn' => 'wireguard',
                                                    'uptime-kuma' => 'uptime-kuma',
                                                    'vaultwarden' => 'bitwarden'
                                                ];
                                                $icon_name = $cdn_map[$s_name] ?? $s_name;
                                                $local_logo = "assets/images/logos/{$s_name}.png";
                                                $has_local = file_exists($local_logo);
                                                $cdn_url = "https://cdn.jsdelivr.net/gh/walkxcode/dashboard-icons/png/{$icon_name}.png";
                                                $service_url = "http://192.168.1.147:" . $servicio['puerto'];
                                            ?>
                                            <div class="service-card group relative" data-service-name="<?php echo $servicio['nombre_servicio']; ?>" data-empresa-name="<?php echo $empresa['nombre']; ?>">
                                                <div class="service-card-inner p-10 rounded-[3.5rem] transition-all transform hover:-translate-y-2 relative overflow-hidden h-full flex flex-col">
                                                    
                                                    <div class="absolute top-8 right-8 flex items-center gap-2 px-4 py-1.5 bg-black/40 rounded-full border border-white/5">
                                                        <span class="status-dot w-2 h-2 rounded-full bg-slate-600"></span>
                                                        <span class="status-badge text-[9px] font-black uppercase tracking-widest text-slate-500 italic">Polling...</span>
                                                    </div>

                                                    <div class="flex flex-col items-center flex-1">
                                                        <div class="w-28 h-28 mb-8 relative">
                                                            <div class="absolute inset-0 bg-indigo-600/20 blur-3xl rounded-full group-hover:bg-indigo-600/40 transition-all opacity-0 group-hover:opacity-100"></div>
                                                            <div class="relative w-full h-full bg-white/5 rounded-[2.5rem] border border-white/10 flex items-center justify-center p-6 group-hover:border-indigo-600/50 transition-all">
                                                                <img src="<?php echo $has_local ? $local_logo : $cdn_url; ?>" 
                                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                                                     class="max-w-full max-h-full object-contain filter drop-shadow-2xl">
                                                                <div class="hidden flex-col items-center">
                                                                    <span class="text-4xl font-black text-white/80"><?php echo strtoupper(substr($s_name,0,1)); ?></span>
                                                                    <span class="text-[9px] font-bold text-indigo-500 uppercase mt-1 tracking-widest">APP</span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <h5 class="text-2xl font-black tracking-tighter text-white mb-2"><?php echo strtoupper($servicio['nombre_servicio']); ?></h5>
                                                    <div class="flex items-center gap-3">
                                                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Endpoint Puerto</span>
                                                        <span class="px-3 py-1 bg-white/5 rounded-lg text-[10px] font-black text-indigo-400 border border-white/10"><?php echo $servicio['puerto']; ?></span>
                                                    </div>

                                                    <div class="w-full mt-10 space-y-4">
                                                        <div class="flex justify-between items-center text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                                            <span>Estabilidad</span>
                                                            <span class="text-emerald-400">99.9% Uptime</span>
                                                        </div>
                                                        <div class="w-full h-1.5 bg-white/5 rounded-full overflow-hidden">
                                                            <div class="h-full bg-indigo-600 w-full animate-pulse-soft opacity-60"></div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="mt-12 flex gap-4">
                                                    <a href="<?php echo $service_url; ?>" target="_blank" class="flex-1 py-5 bg-white/5 hover:bg-indigo-600 text-white rounded-[2rem] text-[10px] font-black uppercase tracking-[0.2em] transition-all border border-white/10 hover:border-indigo-600 hover:shadow-2xl hover:shadow-indigo-600/40 flex items-center justify-center gap-2 group/btn">
                                                        <span>Launch App</span>
                                                        <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                                                    </a>
                                                    <button onclick="Dashboard.destroyService('<?php echo $empresa['nombre']; ?>', '<?php echo $servicio['nombre_servicio']; ?>')" class="w-20 h-20 bg-white/5 hover:bg-red-500/10 hover:text-red-500 rounded-[2rem] flex items-center justify-center transition-all border border-white/10 hover:border-red-500/30">
                                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- Footer -->
        <footer class="p-16 text-center text-slate-700 text-[10px] font-black uppercase tracking-[0.5em] opacity-40">
            &copy; 2026 TenSaaS Infrastructure &bull; All Systems Operational
        </footer>
    </main>

    <script src="assets/js/dashboard.js"></script>
</body>
</html>
<?php mysqli_close($conn); ?>
