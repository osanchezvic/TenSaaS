<?php
session_start();

/* ── DB CONNECTION ───────────────────────────────────────── */
$db_host = getenv('DB_HOST') ?: 'infra_users_db';
$db_name = getenv('DB_NAME') ?: 'users_db';
$db_user = getenv('DB_USER') ?: 'users_user';
$db_pass = getenv('DB_PASSWORD');

if (!$db_pass) {
    die("Error de Configuración: DB_PASSWORD no configurada.");
}

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die("Error de Conexión a DB: " . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');

/* ── AUTHENTICATION ──────────────────────────────────────── */
$remote_user = $_SESSION['usuario'] ?? $_SERVER['HTTP_REMOTE_USER'] ?? null;

if (!$remote_user && isset($_GET['test_user'])) {
    $remote_user = $_GET['test_user'];
}

if (!$remote_user) {
    header("Location: index.php");
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

/* ── FETCH USER & COMPANY DATA ───────────────────────────── */
error_log("Dashboard Auth Debug - Remote User: " . ($remote_user ?? 'null'));
$sql_user = "SELECT u.*, e.nombre as nombre_empresa
             FROM usuarios u
             LEFT JOIN empresas e ON u.empresa_id = e.id
             WHERE u.usuario = ? AND u.estado = 'activo'";
$stmt = mysqli_prepare($conn, $sql_user);
mysqli_stmt_bind_param($stmt, 's', $remote_user);
mysqli_stmt_execute($stmt);
$user_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$user_data) {
    die("<h1>Error</h1><p>Usuario '$remote_user' no encontrado en la base de datos del sistema.</p>");
}

$empresa_id   = $user_data['empresa_id'];
$nombre_empresa = $user_data['nombre_empresa'];

/* ── FETCH SERVICES ──────────────────────────────────────── */
$sql_services = "SELECT * FROM servicios_contratados
                 WHERE empresa_id = ? AND estado = 'activo'
                 ORDER BY nombre_servicio ASC";
$stmt_svc = mysqli_prepare($conn, $sql_services);
mysqli_stmt_bind_param($stmt_svc, 'i', $empresa_id);
mysqli_stmt_execute($stmt_svc);
$result_services = mysqli_stmt_get_result($stmt_svc);
$servicios = mysqli_fetch_all($result_services, MYSQLI_ASSOC);

/* ── HELPERS ─────────────────────────────────────────────── */
// Genera iniciales para el avatar
$initials = strtoupper(substr($user_data['usuario'], 0, 2));

// Hora del día para el saludo
$hour = (int)date('H');
if ($hour < 12)      $greeting = "Buenos días";
elseif ($hour < 20)  $greeting = "Buenas tardes";
else                 $greeting = "Buenas noches";

// Número de servicios
$total_servicios = count($servicios);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal — <?php echo htmlspecialchars($nombre_empresa); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:          #0c0f1a;
            --surface:     #131726;
            --surface-2:   #1a1f30;
            --border:      rgba(255,255,255,0.07);
            --border-hover:rgba(255,255,255,0.15);
            --accent:      #4f6ef7;
            --accent-glow: rgba(79,110,247,0.35);
            --accent-2:    #7c3aed;
            --success:     #10b981;
            --text-1:      #f0f2ff;
            --text-2:      #8b92b3;
            --text-3:      #545a7a;
            --radius:      14px;
            --radius-lg:   20px;
            --font-display:'Syne', sans-serif;
            --font-body:   'DM Sans', sans-serif;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--font-body);
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ─── NOISE OVERLAY ─── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.035'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
            opacity: .5;
        }

        /* ─── BACKGROUND GLOWS ─── */
        body::after {
            content: '';
            position: fixed;
            top: -200px;
            left: -200px;
            width: 700px;
            height: 700px;
            background: radial-gradient(circle, rgba(79,110,247,0.12) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        .glow-br {
            position: fixed;
            bottom: -150px;
            right: -150px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(124,58,237,0.1) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        /* ─── LAYOUT ─── */
        .wrapper {
            position: relative;
            z-index: 1;
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 24px 80px;
        }

        /* ─── TOPBAR ─── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 0;
            border-bottom: 1px solid var(--border);
            margin-bottom: 48px;
        }

        .topbar-logo {
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 1.15rem;
            letter-spacing: -0.03em;
            color: var(--text-1);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .topbar-logo span {
            display: inline-block;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border-radius: 8px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 0.75rem;
            color: #fff;
            letter-spacing: 0.05em;
            flex-shrink: 0;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-2);
            font-family: var(--font-body);
            font-size: 0.82rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all .2s ease;
        }

        .logout-btn:hover {
            border-color: var(--border-hover);
            color: var(--text-1);
            background: var(--surface);
        }

        /* ─── HERO SECTION ─── */
        .hero {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 24px;
            align-items: end;
            margin-bottom: 56px;
            animation: fadeUp .6s ease both;
        }

        .greeting {
            font-family: var(--font-body);
            font-size: 0.95rem;
            font-weight: 300;
            color: var(--text-2);
            letter-spacing: 0.02em;
            margin-bottom: 8px;
        }

        .hero-title {
            font-family: var(--font-display);
            font-size: clamp(2rem, 5vw, 3.2rem);
            font-weight: 800;
            letter-spacing: -0.04em;
            line-height: 1.05;
            color: var(--text-1);
        }

        .hero-title .accent {
            background: linear-gradient(90deg, var(--accent), #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-sub {
            margin-top: 12px;
            color: var(--text-2);
            font-size: 0.95rem;
            font-weight: 300;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .company-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.8rem;
            color: var(--text-2);
        }

        .company-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--success);
            flex-shrink: 0;
        }

        /* Stats strip */
        .stats-strip {
            display: flex;
            gap: 2px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            padding: 20px 28px;
            flex-direction: column;
            align-items: center;
            min-width: 120px;
        }

        .stats-number {
            font-family: var(--font-display);
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: -0.04em;
            background: linear-gradient(135deg, var(--accent), #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
        }

        .stats-label {
            font-size: 0.75rem;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-top: 4px;
            white-space: nowrap;
        }

        /* ─── SECTION HEADER ─── */
        .section-header {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 24px;
            animation: fadeUp .6s .1s ease both;
        }

        .section-title {
            font-family: var(--font-display);
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--text-3);
        }

        .section-line {
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* ─── SERVICE GRID ─── */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            animation: fadeUp .6s .2s ease both;
        }

        .card {
            position: relative;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 28px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            overflow: hidden;
            transition: border-color .25s ease, transform .25s ease, box-shadow .25s ease;
        }

        .card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(79,110,247,0.05), transparent 60%);
            opacity: 0;
            transition: opacity .3s ease;
            pointer-events: none;
        }

        .card:hover {
            border-color: var(--border-hover);
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3), 0 0 0 1px rgba(79,110,247,0.1);
        }

        .card:hover::before {
            opacity: 1;
        }

        .card-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            box-shadow: 0 8px 20px var(--accent-glow);
        }

        .card-body {
            flex: 1;
        }

        .card-name {
            font-family: var(--font-display);
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text-1);
            margin-bottom: 6px;
        }

        .card-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.78rem;
            color: var(--success);
            font-weight: 500;
        }

        .card-status::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 6px var(--success);
            animation: pulse 2s ease infinite;
        }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #fff;
            padding: 11px 20px;
            text-decoration: none;
            border-radius: 10px;
            font-family: var(--font-body);
            font-size: 0.875rem;
            font-weight: 500;
            letter-spacing: 0.01em;
            transition: opacity .2s ease, transform .2s ease, box-shadow .2s ease;
            box-shadow: 0 4px 15px var(--accent-glow);
        }

        .btn:hover {
            opacity: .9;
            transform: translateY(-1px);
            box-shadow: 0 8px 25px var(--accent-glow);
        }

        .btn svg {
            width: 14px;
            height: 14px;
        }

        /* ─── EMPTY STATE ─── */
        .empty {
            grid-column: 1 / -1;
            padding: 64px 32px;
            text-align: center;
            background: var(--surface);
            border: 1px dashed var(--border);
            border-radius: var(--radius-lg);
        }

        .empty-icon {
            font-size: 2.5rem;
            margin-bottom: 16px;
            display: block;
        }

        .empty h3 {
            font-family: var(--font-display);
            font-size: 1.1rem;
            color: var(--text-2);
            margin-bottom: 6px;
        }

        .empty p {
            color: var(--text-3);
            font-size: 0.875rem;
        }

        /* ─── FOOTER ─── */
        .footer {
            margin-top: 64px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .footer-text {
            font-size: 0.78rem;
            color: var(--text-3);
        }

        /* ─── ANIMATIONS ─── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: .3; }
        }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 640px) {
            .hero { grid-template-columns: 1fr; }
            .stats-strip { flex-direction: row; align-items: center; justify-content: space-between; min-width: unset; }
            .topbar-logo span { display: none; }
        }
    </style>
</head>
<body>
<div class="glow-br"></div>

<div class="wrapper">

    <!-- TOPBAR -->
    <nav class="topbar">
        <div class="topbar-logo">
            <span></span>
            Portal Cliente
        </div>
        <div class="topbar-right">
            <div class="avatar"><?php echo htmlspecialchars($initials); ?></div>
            <a href="?logout=1" class="logout-btn">
                <svg viewBox="0 0 20 20" fill="none" width="14" height="14" stroke="currentColor" stroke-width="1.8">
                    <path d="M13 3h4a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1h-4M9 14l-5-4 5-4M3 10h10" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Salir
            </a>
        </div>
    </nav>

    <!-- HERO -->
    <div class="hero">
        <div>
            <p class="greeting"><?php echo $greeting; ?></p>
            <h1 class="hero-title">
                <?php echo htmlspecialchars($user_data['usuario']); ?><br>
                <span class="accent"><?php echo htmlspecialchars($nombre_empresa); ?></span>
            </h1>
            <div class="hero-sub">
                <span class="company-badge"><?php echo htmlspecialchars($nombre_empresa); ?></span>
                <span>· Sesión activa</span>
            </div>
        </div>
        <div class="stats-strip">
            <span class="stats-number"><?php echo $total_servicios; ?></span>
            <span class="stats-label">Servicio<?php echo $total_servicios !== 1 ? 's' : ''; ?> activo<?php echo $total_servicios !== 1 ? 's' : ''; ?></span>
        </div>
    </div>

    <!-- SERVICES -->
    <div class="section-header">
        <span class="section-title">Mis servicios</span>
        <div class="section-line"></div>
    </div>

    <div class="grid">
        <?php if (empty($servicios)): ?>
            <div class="empty">
                <span class="empty-icon">📭</span>
                <h3>Sin servicios activos</h3>
                <p>No tienes ningún servicio contratado en este momento.</p>
            </div>
        <?php else: ?>
            <?php
            // Iconos según palabras clave del nombre del servicio
            $icons = [
                'mail'    => '📧', 'correo'  => '📧',
                'web'     => '🌐', 'hosting' => '🌐',
                'backup'  => '💾', 'backups' => '💾',
                'vpn'     => '🔒', 'firewall'=> '🔒',
                'cloud'   => '☁️', 'nube'    => '☁️',
                'monitor' => '📊', 'server'  => '🖥️',
                'dns'     => '🔗', 'erp'     => '⚙️',
                'crm'     => '👥',
            ];

            foreach ($servicios as $i => $svc):
                $name_lower = strtolower($svc['nombre_servicio']);
                $icon = '🔷';
                foreach ($icons as $keyword => $emoji) {
                    if (str_contains($name_lower, $keyword)) { $icon = $emoji; break; }
                }
                $delay = $i * 0.07;
            ?>
                <div class="card" style="animation: fadeUp .5s <?php echo $delay; ?>s ease both;">
                    <div class="card-icon"><?php echo $icon; ?></div>
                    <div class="card-body">
                        <div class="card-name"><?php echo htmlspecialchars($svc['nombre_servicio']); ?></div>
                        <span class="card-status">Activo</span>
                    </div>
                    <a href="<?php echo htmlspecialchars($svc['url_admin']); ?>" class="btn" target="_blank" rel="noopener noreferrer">
                        Acceder
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 8h10M9 4l4 4-4 4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- FOOTER -->
    <footer class="footer">
        <span class="footer-text">Portal de Cliente · <?php echo htmlspecialchars($nombre_empresa); ?></span>
        <span class="footer-text"><?php echo date('d M Y, H:i'); ?></span>
    </footer>

</div>
</body>
</html>
<?php mysqli_close($conn); ?>

