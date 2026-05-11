<?php
session_start();

/* ── DB ───────────────────────────────────────────────────── */
$db_host = getenv('DB_HOST') ?: 'infra_users_db';
$db_name = getenv('DB_NAME') ?: 'users_db';
$db_user = getenv('DB_USER') ?: 'users_user';
$db_pass = getenv('DB_PASSWORD');
if (!$db_pass) die("DB Error: DB_PASSWORD not set");

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) die("DB Error: " . mysqli_connect_error());
mysqli_set_charset($conn, 'utf8mb4');

$DB_DIR = "/var/www/scripts/databases";

/* ── API ACTIONS ──────────────────────────────────────────── */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['admin'])) { echo json_encode(['error' => 'Unauthorized']); exit; }

    if ($_GET['action'] === 'get_metrics') {
        $base = "http://prometheus_global:9090/api/v1/query";
        $q = [
            'cpu' => '100-(avg by(instance)(irate(node_cpu_seconds_total{mode="idle"}[5m]))*100)',
            'ram' => '(1-(node_memory_MemAvailable_bytes/node_memory_MemTotal_bytes))*100'
        ];
        $r = [];
        foreach ($q as $k => $query) {
            $res = @file_get_contents($base . '?query=' . urlencode($query));
            $r[$k] = $res ? round((float)(json_decode($res, true)['data']['result'][0]['value'][1] ?? 0), 2) : 0;
        }
        echo json_encode($r); exit;
    }

    if ($_GET['action'] === 'get_real_status') {
        $token = getenv('API_TOKEN') ?: 'd7f3e8b1a9c4d2e5f6a7b8c9d0e1f2a3';
        $ch = curl_init('http://infra_api:8000/api/v1/system/status');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['token: ' . $token],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        header('Content-Type: application/json');
        echo $resp ?: json_encode(['status' => 'error', 'message' => 'API unreachable', 'curl_error' => $err]);
        exit;
    }

    if ($_GET['action'] === 'destroy_service' && isset($_GET['empresa'], $_GET['servicio'])) {
        $token = getenv('API_TOKEN');
        if (!$token) { echo json_encode(['status' => 'error', 'message' => 'API_TOKEN not configured']); exit; }
        $ch = curl_init('http://infra_api:8000/destroy/' . urlencode($_GET['empresa']) . '/' . urlencode($_GET['servicio']));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['token: ' . $token]]);
        $resp = curl_exec($ch); curl_close($ch);
        $d = json_decode($resp, true);
        if (($d['status'] ?? '') === 'success') {
            $sql  = "UPDATE servicios_contratados s JOIN empresas e ON s.empresa_id=e.id SET s.estado='eliminado' WHERE e.nombre=? AND s.nombre_servicio=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'ss', $_GET['empresa'], $_GET['servicio']);
            mysqli_stmt_execute($stmt);
        }
        echo $resp; exit;
    }
}

/* ── CATÁLOGO ─────────────────────────────────────────────── */
$catalogo_path    = "/var/www/catalogo";
$servicios_catalogo = [];
$skip = ['panel','nginx','monitorizacion','mariadb','users-db','node-exporter','prometheus','grafana'];
if (is_dir($catalogo_path)) {
    foreach (array_filter(glob($catalogo_path . '/*'), 'is_dir') as $dir) {
        $name = basename($dir);
        if (!in_array($name, $skip)) $servicios_catalogo[] = $name;
    }
}

if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

/* ── AUTH ─────────────────────────────────────────────────── */
if (!isset($_SESSION['admin'])) {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u  = $_POST['admin_user']     ?? '';
        $p  = $_POST['admin_password'] ?? '';
        $st = mysqli_prepare($conn, "SELECT id, hash_password, empresa_id, es_admin FROM usuarios WHERE usuario=?");
        mysqli_stmt_bind_param($st, 's', $u);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        if ($row && password_verify($p, $row['hash_password'])) {
            $_SESSION += ['admin' => 1, 'admin_id' => $row['id'], 'empresa_id' => $row['empresa_id'], 'es_admin' => $row['es_admin']];
            header("Location: index.php"); exit;
        }
        $error = "Credenciales inválidas";
    }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TenSaaS — Acceso</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#07090f;--surface:#0e1420;--border:rgba(255,255,255,0.08);
  --accent:#38bdf8;--accent-dim:rgba(56,189,248,0.1);
  --text:#e2e8f0;--muted:#4b5563;--danger:#fb7185;
  --fh:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'JetBrains Mono',monospace;
}
body{background:var(--bg);color:var(--text);font-family:var(--fb);min-height:100vh;display:flex;align-items:center;justify-content:center;overflow:hidden}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(56,189,248,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(56,189,248,.035) 1px,transparent 1px);background-size:52px 52px;pointer-events:none}
body::after{content:'';position:fixed;top:40%;left:50%;transform:translate(-50%,-50%);width:700px;height:500px;background:radial-gradient(ellipse,rgba(56,189,248,.06) 0%,transparent 65%);pointer-events:none}
.card{position:relative;z-index:1;width:100%;max-width:400px;background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:2.25rem}
.card::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:55%;height:1px;background:linear-gradient(90deg,transparent,var(--accent),transparent)}
.brand{text-align:center;margin-bottom:2rem}
.logo-wrap{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;background:var(--accent-dim);border:1px solid rgba(56,189,248,.2);border-radius:14px;margin-bottom:.875rem;position:relative}
.logo-wrap svg{width:26px;height:26px;color:var(--accent)}
.logo-wrap::after{content:'';position:absolute;inset:-5px;border:1px solid rgba(56,189,248,.1);border-radius:19px;animation:rpulse 3s ease-in-out infinite}
@keyframes rpulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0;transform:scale(1.12)}}
h1{font-family:var(--fh);font-size:1.85rem;font-weight:800;letter-spacing:-.03em;color:#fff}
.sub{font-family:var(--fm);font-size:10px;color:var(--muted);letter-spacing:.1em;margin-top:4px}
.err{padding:9px 13px;background:rgba(251,113,133,.08);border:1px solid rgba(251,113,133,.2);border-radius:9px;color:var(--danger);font-size:12px;margin-bottom:1.25rem;text-align:center}
.field{margin-bottom:.875rem}
.field label{display:block;font-family:var(--fm);font-size:9px;color:var(--muted);letter-spacing:.14em;text-transform:uppercase;margin-bottom:5px}
.field input{width:100%;padding:10px 13px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:var(--fb);font-size:13px;outline:none;transition:border-color .2s}
.field input:focus{border-color:rgba(56,189,248,.4);background:rgba(56,189,248,.03)}
.btn{width:100%;padding:12px;background:var(--accent);color:#050d18;border:none;border-radius:9px;font-family:var(--fh);font-size:13px;font-weight:700;letter-spacing:.06em;cursor:pointer;margin-top:.5rem;transition:opacity .15s,transform .1s}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.btn:active{transform:scale(.99)}
.foot{margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.foot span{font-family:var(--fm);font-size:9px;color:var(--muted);letter-spacing:.08em}
.online{display:flex;align-items:center;gap:5px;font-family:var(--fm);font-size:9px;color:#34d399}
.online::before{content:'';width:5px;height:5px;border-radius:50%;background:#34d399;animation:blink 2s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.25}}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="logo-wrap">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
    </div>
    <h1>TenSaaS</h1>
    <div class="sub">INFRASTRUCTURE CONTROL PLANE</div>
  </div>
  <?php if ($error): ?>
    <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="field">
      <label>Identificador</label>
      <input type="text" name="admin_user" placeholder="usuario" required autofocus>
    </div>
    <div class="field">
      <label>Contraseña</label>
      <input type="password" name="admin_password" placeholder="••••••••" required>
    </div>
    <button type="submit" class="btn">ACCEDER AL SISTEMA →</button>
  </form>
  <div class="foot">
    <span>© 2026 ASIR Cloud</span>
    <span class="online">NODO ONLINE</span>
  </div>
</div>
</body>
</html>
<?php exit; }

/* ── DATA ─────────────────────────────────────────────────── */
$stats = [];
if ($_SESSION['es_admin'] == 1) {
    $r = mysqli_query($conn,
        "SELECT (SELECT COUNT(*) FROM empresas WHERE estado='activa') as total_empresas,
                (SELECT COUNT(*) FROM usuarios WHERE estado='activo') as total_usuarios,
                (SELECT COUNT(*) FROM servicios_contratados WHERE estado='activo') as total_servicios");
    $stats = mysqli_fetch_assoc($r);
}

$where = $_SESSION['es_admin'] != 1 ? " AND id=?" : "";
$st    = mysqli_prepare($conn, "SELECT * FROM empresas WHERE estado='activa'" . $where . " ORDER BY nombre");
if ($_SESSION['es_admin'] != 1) mysqli_stmt_bind_param($st, 'i', $_SESSION['empresa_id']);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);

$empresas            = [];
$servicios_por_empresa = [];
while ($row = mysqli_fetch_assoc($res)) {
    $empresas[] = $row;
    $srv = mysqli_prepare($conn, "SELECT * FROM servicios_contratados WHERE empresa_id=? AND estado='activo'");
    mysqli_stmt_bind_param($srv, 'i', $row['id']);
    mysqli_stmt_execute($srv);
    $servicios_por_empresa[$row['id']] = mysqli_fetch_all(mysqli_stmt_get_result($srv), MYSQLI_ASSOC);
}

// Accent color per company (HSL hues)
$hues = [199, 142, 265, 25, 340, 39, 180, 310];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TenSaaS — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --bg:#07090f;
  --surface:#0d1321;
  --card:#111827;
  --border:rgba(255,255,255,0.07);
  --border-hi:rgba(255,255,255,0.13);
  --accent:#38bdf8;
  --accent-dim:rgba(56,189,248,0.1);
  --success:#34d399;
  --danger:#fb7185;
  --warning:#fbbf24;
  --text:#e2e8f0;
  --muted:#4b5563;
  --muted2:#64748b;
  --sw:260px;
  --fh:'Syne',sans-serif;
  --fb:'DM Sans',sans-serif;
  --fm:'JetBrains Mono',monospace;
}

.light{
  --bg:#f1f5f9;--surface:#ffffff;--card:#f8fafc;
  --border:rgba(0,0,0,0.07);--border-hi:rgba(0,0,0,0.13);
  --accent:#0284c7;--accent-dim:rgba(2,132,199,0.09);
  --text:#0f172a;--muted:#94a3b8;--muted2:#64748b;
}

html,body{height:100%}
body{background:var(--bg);color:var(--text);font-family:var(--fb);display:flex;transition:background .3s,color .3s;overflow:hidden}

/* Grid overlay */
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(56,189,248,.028) 1px,transparent 1px),linear-gradient(90deg,rgba(56,189,248,.028) 1px,transparent 1px);background-size:56px 56px;pointer-events:none;z-index:0}
.light body::before{background-image:linear-gradient(rgba(0,0,0,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,0,0,.04) 1px,transparent 1px)}

/* ── SIDEBAR ─────────────────────────────────────────────── */
.sidebar{
  width:var(--sw);height:100vh;position:sticky;top:0;
  display:flex;flex-direction:column;
  background:var(--surface);border-right:1px solid var(--border);
  flex-shrink:0;z-index:10;
  transition:background .3s;
}

.sb-top{padding:1.25rem 1rem;border-bottom:1px solid var(--border)}
.brand-row{display:flex;align-items:center;gap:9px}
.brand-ico{width:34px;height:34px;background:var(--accent-dim);border:1px solid rgba(56,189,248,.22);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.brand-ico svg{width:17px;height:17px;color:var(--accent)}
.brand-name{font-family:var(--fh);font-size:1rem;font-weight:800;letter-spacing:-.02em}
.brand-sub{font-family:var(--fm);font-size:8px;color:var(--muted);letter-spacing:.08em;display:block;margin-top:1px}

.sb-section{padding:.75rem .875rem;border-bottom:1px solid var(--border)}
.sb-label{font-family:var(--fm);font-size:8px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);padding:0 .375rem;margin-bottom:.35rem}

.nav-item{display:flex;align-items:center;gap:7px;padding:7px 10px;border-radius:7px;font-size:12.5px;font-weight:500;color:var(--muted2);text-decoration:none;transition:background .15s,color .15s;cursor:pointer}
.nav-item:hover{background:var(--accent-dim);color:var(--accent)}
.nav-item.active{background:var(--accent-dim);color:var(--accent);border-left:2px solid var(--accent);padding-left:8px}
.nav-item svg{width:14px;height:14px;flex-shrink:0}

.sb-companies{flex:1;overflow-y:auto;padding:.625rem .875rem}
.sb-companies::-webkit-scrollbar{width:3px}
.sb-companies::-webkit-scrollbar-thumb{background:var(--border-hi);border-radius:99px}

.co-nav{display:flex;align-items:center;gap:7px;padding:6px 8px;border-radius:7px;cursor:pointer;text-decoration:none;transition:background .15s}
.co-nav:hover{background:rgba(255,255,255,.04)}
.light .co-nav:hover{background:rgba(0,0,0,.04)}
.co-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.co-nav-name{font-size:11.5px;font-weight:500;color:var(--muted2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.co-count{font-family:var(--fm);font-size:9px;color:var(--muted);background:var(--border);padding:1px 6px;border-radius:99px}

.sb-foot{padding:.875rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.5rem}
.user-row{display:flex;align-items:center;gap:7px}
.u-avatar{width:28px;height:28px;border-radius:7px;background:var(--accent-dim);display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:11px;font-weight:700;color:var(--accent)}
.u-name{font-size:11px;color:var(--text);font-weight:500}
.u-role{font-family:var(--fm);font-size:8px;color:var(--muted);display:block}
.icon-btn{width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted2);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,color .15s;text-decoration:none;flex-shrink:0}
.icon-btn:hover{background:var(--accent-dim);color:var(--accent)}
.icon-btn svg{width:13px;height:13px}

/* ── MAIN ────────────────────────────────────────────────── */
.main{flex:1;overflow-y:auto;min-width:0;position:relative;z-index:1}

.topbar{
  position:sticky;top:0;z-index:20;
  background:rgba(7,9,15,.82);
  backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
  border-bottom:1px solid var(--border);
  padding:.75rem 1.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;
  transition:background .3s;
}
.light .topbar{background:rgba(241,245,249,.88)}

.page-title{font-family:var(--fh);font-size:1rem;font-weight:700;letter-spacing:-.02em}
.topbar-right{display:flex;align-items:center;gap:7px}
.refresh-chip{font-family:var(--fm);font-size:9px;color:var(--muted);padding:3px 10px;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:99px;white-space:nowrap}
.light .refresh-chip{background:rgba(0,0,0,.04)}

.content{padding:1.75rem;max-width:1440px;margin:0 auto;display:flex;flex-direction:column;gap:2rem}

/* ── STATS ───────────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.875rem}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:13px;padding:1.125rem 1.375rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;transition:border-color .2s}
.stat-card:hover{border-color:var(--border-hi)}
.stat-label{font-family:var(--fm);font-size:8px;letter-spacing:.13em;text-transform:uppercase;color:var(--muted);margin-bottom:5px}
.stat-val{font-family:var(--fh);font-size:2rem;font-weight:800;letter-spacing:-.04em;line-height:1;font-variant-numeric:tabular-nums}
.stat-ico{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.stat-ico svg{width:20px;height:20px}

/* ── SECTION HEADERS ─────────────────────────────────────── */
.sec-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.875rem}
.sec-title{font-family:var(--fh);font-size:.9rem;font-weight:700;display:flex;align-items:center;gap:7px}
.sec-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;margin-top:1px}
.sec-sub{font-family:var(--fm);font-size:9px;color:var(--muted);letter-spacing:.06em;margin-top:2px;padding-left:13px}
.pill{font-family:var(--fm);font-size:9px;padding:3px 9px;border-radius:99px;border:1px solid var(--border-hi);color:var(--muted2)}

/* ── INFRA GRID ──────────────────────────────────────────── */
#infra-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px}

/* Kept for compat */
.sk-box{height:90px;background:var(--surface);border:1px solid var(--border);border-radius:11px;animation:shimmer 1.4s ease-in-out infinite}
@keyframes shimmer{0%,100%{opacity:.5}50%{opacity:1}}

/* ── INFRA TABLE ─────────────────────────────────────────── */
.infra-table-wrap{
  background:var(--surface);border:1px solid var(--border);border-radius:13px;overflow:hidden;
}
.infra-table-head{
  display:grid;grid-template-columns:1.8fr 1.4fr .9fr .9fr;
  padding:.5rem 1rem;
  background:rgba(255,255,255,.025);border-bottom:1px solid var(--border);
  font-family:var(--fm);font-size:8px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);
}
.light .infra-table-head{background:rgba(0,0,0,.03)}
.infra-row{
  display:grid;grid-template-columns:1.8fr 1.4fr .9fr .9fr;align-items:center;
  padding:.55rem 1rem;border-bottom:1px solid var(--border);
  transition:background .15s;cursor:default;
}
.infra-row:last-child{border-bottom:none}
.infra-row:hover{background:rgba(56,189,248,.025)}
.infra-row-name{display:flex;align-items:center;gap:7px;min-width:0}
.infra-row-name svg{width:13px;height:13px;color:var(--muted);flex-shrink:0}
.infra-name-txt{font-family:var(--fm);font-size:10.5px;font-weight:500;color:var(--text);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.infra-image{font-family:var(--fm);font-size:9px;color:var(--muted);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.infra-state-badge{
  display:inline-flex;align-items:center;gap:4px;
  padding:2px 8px;border-radius:99px;font-family:var(--fm);font-size:8px;
  letter-spacing:.05em;white-space:nowrap;width:fit-content;
}
.infra-uptime{font-family:var(--fm);font-size:9px;color:var(--muted);text-align:right}

.infra-sk-row{
  display:grid;grid-template-columns:1.8fr 1.4fr .9fr .9fr;align-items:center;
  padding:.625rem 1rem;border-bottom:1px solid var(--border);gap:.5rem;
  animation:shimmer 1.4s ease-in-out infinite;
}
.infra-sk-row:last-child{border-bottom:none}
.sk-pill{height:10px;background:rgba(255,255,255,.06);border-radius:99px}
.light .sk-pill{background:rgba(0,0,0,.07)}

/* ── CHART ───────────────────────────────────────────────── */
.chart-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.375rem}
.chart-wrap{height:210px;position:relative}

/* ── FLEET ───────────────────────────────────────────────── */
.fleet{display:flex;flex-direction:column;gap:.875rem}

.co-section{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:border-color .2s}
.co-section:hover{border-color:var(--border-hi)}

.co-head{
  padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;
  border-bottom:1px solid var(--border);
}
.co-head-left{display:flex;align-items:center;gap:10px;min-width:0}
.co-avatar{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:1rem;font-weight:800;flex-shrink:0}
.co-name{font-family:var(--fh);font-size:.9rem;font-weight:700;letter-spacing:-.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.co-desc{font-size:11px;color:var(--muted2);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.btn-deploy{
  display:flex;align-items:center;gap:5px;
  padding:6px 12px;background:var(--accent-dim);
  border:1px solid rgba(56,189,248,.22);border-radius:7px;
  color:var(--accent);font-size:11.5px;font-weight:500;
  cursor:pointer;transition:background .15s;flex-shrink:0;white-space:nowrap;
}
.btn-deploy:hover{background:rgba(56,189,248,.18)}
.btn-deploy svg{width:12px;height:12px}

/* ── SERVICE GRID ────────────────────────────────────────── */
.svc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1px;background:var(--border)}

.service-card{background:var(--card);padding:1.125rem;transition:background .15s;position:relative}
.service-card:hover{background:rgba(56,189,248,.03)}

.svc-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.875rem}

.svc-logo{
  width:40px;height:40px;border-radius:9px;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;overflow:hidden;padding:5px;
}
.light .svc-logo{background:rgba(0,0,0,.04)}
.svc-logo img{width:100%;height:100%;object-fit:contain}
.svc-logo .fl{font-family:var(--fh);font-size:.9rem;font-weight:800;color:var(--muted2);display:none}

.status-wrap{display:flex;align-items:center;gap:4px;padding:2px 7px;border-radius:99px;background:rgba(255,255,255,.04);border:1px solid var(--border)}
.light .status-wrap{background:rgba(0,0,0,.04)}
.status-dot{width:5px;height:5px;border-radius:50%;background:var(--muted);flex-shrink:0}
.status-badge{font-family:var(--fm);font-size:8px;letter-spacing:.05em;color:var(--muted)}

.svc-name{font-family:var(--fh);font-size:.82rem;font-weight:700;letter-spacing:-.01em;margin-bottom:2px}
.svc-port{font-family:var(--fm);font-size:10px;color:var(--muted)}

.svc-actions{display:flex;gap:5px;margin-top:.875rem}
.btn-launch{
  flex:1;padding:6px 8px;background:rgba(255,255,255,.04);border:1px solid var(--border);
  border-radius:7px;color:var(--muted2);font-size:11.5px;font-weight:500;
  text-decoration:none;display:flex;align-items:center;justify-content:center;gap:4px;
  transition:background .15s,color .15s,border-color .15s;
}
.light .btn-launch{background:rgba(0,0,0,.04)}
.btn-launch:hover{background:var(--accent-dim);color:var(--accent);border-color:rgba(56,189,248,.3)}
.btn-launch svg{width:11px;height:11px}
.btn-del{
  width:30px;height:30px;background:transparent;border:1px solid var(--border);
  border-radius:7px;color:var(--muted);display:flex;align-items:center;justify-content:center;
  cursor:pointer;transition:background .15s,color .15s,border-color .15s;flex-shrink:0;
}
.btn-del:hover{background:rgba(251,113,133,.1);color:var(--danger);border-color:rgba(251,113,133,.3)}
.btn-del svg{width:12px;height:12px}

.empty-svc{padding:2.25rem;text-align:center;color:var(--muted);font-size:12px;display:flex;flex-direction:column;align-items:center;gap:7px}
.empty-svc svg{width:28px;height:28px;opacity:.3}

/* ── DEPLOY MODAL ────────────────────────────────────────── */
.overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.72);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
  z-index:100;align-items:center;justify-content:center;
}
.overlay.open{display:flex}
.modal{
  background:var(--surface);border:1px solid var(--border-hi);
  border-radius:15px;padding:1.5rem;width:100%;max-width:380px;position:relative;
  animation:slide-up .2s ease;
}
@keyframes slide-up{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.modal::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:48%;height:1px;background:linear-gradient(90deg,transparent,var(--accent),transparent)}
.modal-title{font-family:var(--fh);font-size:.925rem;font-weight:700;margin-bottom:1rem}
.modal select{
  width:100%;padding:9px 11px;background:rgba(255,255,255,.04);
  border:1px solid var(--border-hi);border-radius:8px;color:var(--text);
  font-family:var(--fb);font-size:13px;outline:none;margin-bottom:.875rem;cursor:pointer;
  appearance:none;-webkit-appearance:none;
}
.light .modal select{background:rgba(0,0,0,.04)}
.modal-footer{display:flex;gap:7px}
.btn-ok{flex:1;padding:9px;background:var(--accent);border:none;border-radius:8px;color:#050d18;font-family:var(--fh);font-size:13px;font-weight:700;cursor:pointer;transition:opacity .15s}
.btn-ok:hover{opacity:.9}
.btn-ok:disabled{opacity:.5;cursor:not-allowed}
.btn-cancel{padding:9px 14px;background:transparent;border:1px solid var(--border-hi);border-radius:8px;color:var(--muted2);font-size:13px;cursor:pointer;transition:background .15s}
.btn-cancel:hover{background:rgba(255,255,255,.04)}

/* ── ANIMATIONS ──────────────────────────────────────────── */
@keyframes fade-up{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.fu{animation:fade-up .35s ease both}
.fu1{animation-delay:.05s}.fu2{animation-delay:.1s}.fu3{animation-delay:.15s}.fu4{animation-delay:.2s}

/* ── RESPONSIVE ──────────────────────────────────────────── */
@media(max-width:768px){
  .sidebar{display:none}
  .stats-row{grid-template-columns:1fr}
}

/* ── API STATUS PANEL ────────────────────────────────────── */
.api-status-panel{
  border-top:1px solid var(--border);
  padding:.75rem .875rem;
  flex-shrink:0;
}
.api-status-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem}
.api-refresh-btn{
  width:18px;height:18px;border-radius:4px;border:1px solid var(--border);
  background:transparent;color:var(--muted);display:flex;align-items:center;justify-content:center;
  cursor:pointer;transition:color .15s,background .15s;
}
.api-refresh-btn:hover{color:var(--accent);background:var(--accent-dim)}
.api-rows{display:flex;flex-direction:column;gap:3px}
.api-row{
  display:flex;align-items:center;gap:5px;
  padding:4px 7px;border-radius:6px;
  background:rgba(255,255,255,.025);border:1px solid transparent;
  transition:border-color .15s,background .15s;
}
.light .api-row{background:rgba(0,0,0,.03)}
.api-row:hover{border-color:var(--border-hi)}
.api-row-label{font-family:var(--fm);font-size:9px;color:var(--muted2);flex:1;letter-spacing:.04em}
.api-dot{
  width:6px;height:6px;border-radius:50%;flex-shrink:0;
  background:var(--muted);transition:background .3s,box-shadow .3s;
}
.api-dot.up{background:#34d399;box-shadow:0 0 5px #34d399}
.api-dot.down{background:#fb7185}
.api-dot.checking{background:#fbbf24;animation:blink-dot 1s ease-in-out infinite}
@keyframes blink-dot{0%,100%{opacity:1}50%{opacity:.3}}
.api-badge{font-family:var(--fm);font-size:8px;letter-spacing:.04em;color:var(--muted);white-space:nowrap}
.api-badge.up{color:#34d399}
.api-badge.down{color:#fb7185}
.api-latency-row{
  display:flex;align-items:center;justify-content:space-between;
  margin-top:.5rem;padding-top:.5rem;border-top:1px solid var(--border);
}

/* hidden */
.hidden{display:none!important}
</style>
</head>
<body>

<!-- ── SIDEBAR ──────────────────────────────────────────── -->
<aside class="sidebar">
  <div class="sb-top">
    <div class="brand-row">
      <div class="brand-ico">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
      </div>
      <div>
        <div class="brand-name">TenSaaS</div>
        <span class="brand-sub">CONTROL PLANE v2</span>
      </div>
    </div>
  </div>

  <div class="sb-section">
    <p class="sb-label">Sistema</p>
    <a href="#" class="nav-item active">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
      Vista general
    </a>
    <a href="/portainer/" target="_blank" class="nav-item">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-7 0V4"/></svg>
      Portainer
    </a>
    <a href="/grafana/" target="_blank" class="nav-item">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
      Grafana
    </a>
  </div>

  <div class="sb-companies">
    <p class="sb-label">Empresas</p>
    <?php foreach ($empresas as $i => $emp):
      $h = $hues[$i % count($hues)];
      $c = "hsl({$h},75%,60%)";
    ?>
    <a href="#co-<?= $emp['id'] ?>" class="co-nav">
      <span class="co-dot" style="background:<?= $c ?>"></span>
      <span class="co-nav-name"><?= htmlspecialchars($emp['nombre']) ?></span>
      <span class="co-count"><?= count($servicios_por_empresa[$emp['id']]) ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- API Status Panel -->
  <div class="api-status-panel">
    <div class="api-status-header">
      <span class="sb-label" style="margin-bottom:0">Estado del Sistema</span>
      <span class="api-refresh-btn" onclick="Dashboard.updateContainersStatus()" title="Refrescar">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:10px;height:10px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      </span>
    </div>
    <div class="api-rows">
      <div class="api-row">
        <span class="api-row-label">infra_api</span>
        <span class="api-dot" id="dot-api"></span>
        <span class="api-badge" id="badge-api">—</span>
      </div>
      <div class="api-row">
        <span class="api-row-label">prometheus</span>
        <span class="api-dot" id="dot-prom"></span>
        <span class="api-badge" id="badge-prom">—</span>
      </div>
      <div class="api-row">
        <span class="api-row-label">grafana</span>
        <span class="api-dot" id="dot-graf"></span>
        <span class="api-badge" id="badge-graf">—</span>
      </div>
      <div class="api-row">
        <span class="api-row-label">authelia</span>
        <span class="api-dot" id="dot-auth"></span>
        <span class="api-badge" id="badge-auth">—</span>
      </div>
      <div class="api-row">
        <span class="api-row-label">portainer</span>
        <span class="api-dot" id="dot-port"></span>
        <span class="api-badge" id="badge-port">—</span>
      </div>
    </div>
    <div class="api-latency-row">
      <span style="font-family:var(--fm);font-size:8px;color:var(--muted)">latencia API</span>
      <span id="api-latency" style="font-family:var(--fm);font-size:8px;color:var(--accent)">—</span>
    </div>
  </div>

  <div class="sb-foot">
    <div class="user-row">
      <div class="u-avatar"><?= $_SESSION['es_admin'] ? 'A' : 'T' ?></div>
      <div>
        <div class="u-name"><?= $_SESSION['es_admin'] ? 'Global Admin' : 'Tenant' ?></div>
        <span class="u-role">#<?= $_SESSION['admin_id'] ?></span>
      </div>
    </div>
    <div style="display:flex;gap:5px">
      <button onclick="toggleTheme()" class="icon-btn" title="Tema">
        <svg id="sun-icon" class="hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 5a7 7 0 100 14 7 7 0 000-14z"/></svg>
        <svg id="moon-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
      </button>
      <a href="?logout=1" class="icon-btn" title="Salir">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      </a>
    </div>
  </div>
</aside>

<!-- ── MAIN ─────────────────────────────────────────────── -->
<main class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:.875rem">
      <h1 class="page-title">Centro de Mando</h1>
    </div>
    <div class="topbar-right">
      <span class="refresh-chip" id="last-refresh">—</span>
      <button onclick="Dashboard.updateAll()" class="icon-btn" title="Refrescar">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      </button>
    </div>
  </div>

  <div class="content">

    <?php if ($_SESSION['es_admin'] == 1): ?>

    <!-- Stats -->
    <div class="stats-row fu">
      <div class="stat-card">
        <div>
          <div class="stat-label">Empresas Activas</div>
          <div class="stat-val count-up" data-target="<?= $stats['total_empresas'] ?>">0</div>
        </div>
        <div class="stat-ico" style="background:rgba(56,189,248,.1)">
          <svg fill="none" stroke="#38bdf8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        </div>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-label">Servicios Activos</div>
          <div class="stat-val count-up" data-target="<?= $stats['total_servicios'] ?>">0</div>
        </div>
        <div class="stat-ico" style="background:rgba(52,211,153,.1)">
          <svg fill="none" stroke="#34d399" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-7 0V4"/></svg>
        </div>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-label">Usuarios Totales</div>
          <div class="stat-val count-up" data-target="<?= $stats['total_usuarios'] ?>">0</div>
        </div>
        <div class="stat-ico" style="background:rgba(251,191,36,.1)">
          <svg fill="none" stroke="#fbbf24" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        </div>
      </div>
    </div>

    <!-- Infra nodes -->
    <section class="fu fu1">
      <div class="sec-head">
        <div>
          <div class="sec-title"><span class="sec-dot" style="background:#38bdf8;margin-top:3px"></span>Infraestructura Core</div>
          <div class="sec-sub">Contenedores de plataforma — Estado en tiempo real</div>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem">
          <span id="infra-summary" class="pill" style="color:var(--muted2)">Cargando...</span>
          <span class="pill">LIVE</span>
        </div>
      </div>
      <div class="infra-table-wrap">
        <div class="infra-table-head">
          <span>Contenedor</span>
          <span>Imagen</span>
          <span>Estado</span>
          <span style="text-align:right">Uptime</span>
        </div>
        <div id="infra-list">
          <?php for ($i=0;$i<6;$i++): ?>
          <div class="infra-sk-row">
            <div class="sk-pill" style="width:110px"></div>
            <div class="sk-pill" style="width:80px"></div>
            <div class="sk-pill" style="width:55px"></div>
            <div class="sk-pill" style="width:60px;margin-left:auto"></div>
          </div>
          <?php endfor; ?>
        </div>
      </div>
    </section>

    <!-- Chart -->
    <div class="chart-card fu fu2">
      <div class="sec-head" style="margin-bottom:1.125rem">
        <div>
          <div class="sec-title"><span class="sec-dot" style="background:#34d399;margin-top:3px"></span>Recursos del Sistema</div>
          <div class="sec-sub">CPU & RAM — Tiempo real</div>
        </div>
      </div>
      <div class="chart-wrap"><canvas id="metricsChart"></canvas></div>
    </div>

    <?php endif; ?>

    <!-- Fleet -->
    <section class="fu fu3">
      <div class="sec-head">
        <div>
          <div class="sec-title"><span class="sec-dot" style="background:#a78bfa;margin-top:3px"></span>Flota de Servicios</div>
          <div class="sec-sub"><?= count($empresas) ?> ENTIDADES ACTIVAS</div>
        </div>
      </div>

      <div class="fleet">
        <?php foreach ($empresas as $i => $empresa):
          $h = $hues[$i % count($hues)];
          $color = "hsl({$h},75%,60%)";
          $colorBg = "hsl({$h},75%,60%,0.12)";
          $contratados_arr = array_column($servicios_por_empresa[$empresa['id']] ?? [], 'nombre_servicio');
          $available = array_values(array_filter($servicios_catalogo, fn($s) => !in_array($s, $contratados_arr)));
        ?>
        <div class="co-section" id="co-<?= $empresa['id'] ?>" style="border-left:3px solid <?= $color ?>">
          <div class="co-head">
            <div class="co-head-left">
              <div class="co-avatar" style="background:<?= $colorBg ?>;color:<?= $color ?>;border:1px solid <?= $color ?>33">
                <?= strtoupper(substr($empresa['nombre'], 0, 1)) ?>
              </div>
              <div style="min-width:0">
                <div class="co-name"><?= htmlspecialchars($empresa['nombre']) ?></div>
                <div class="co-desc"><?= htmlspecialchars($empresa['descripcion'] ?? '') ?></div>
              </div>
            </div>
            <button class="btn-deploy" onclick="openModal('<?= htmlspecialchars(addslashes($empresa['nombre'])) ?>')">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
              Desplegar
            </button>
          </div>

          <?php if (empty($servicios_por_empresa[$empresa['id']])): ?>
            <div class="empty-svc">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
              Sin servicios desplegados
            </div>
          <?php else: ?>
            <div class="svc-grid">
              <?php foreach ($servicios_por_empresa[$empresa['id']] as $svc):
                $sn = strtolower($svc['nombre_servicio']);
                $cdn_map = ['vpn' => 'wireguard', 'vaultwarden' => 'bitwarden'];
                $icon = $cdn_map[$sn] ?? $sn;
                $local = "assets/images/logos/{$sn}.png";
                $src   = file_exists($local) ? $local : "https://cdn.jsdelivr.net/gh/walkxcode/dashboard-icons/png/{$icon}.png";
                $url   = "https://{$sn}.{$empresa['nombre']}.tensaas.es";
              ?>
              <div class="service-card"
                   data-service-name="<?= htmlspecialchars($svc['nombre_servicio']) ?>"
                   data-empresa-name="<?= htmlspecialchars($empresa['nombre']) ?>">
                <div class="svc-top">
                  <div class="svc-logo">
                    <img src="<?= $src ?>" alt="<?= $sn ?>"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <span class="fl"><?= strtoupper(substr($sn,0,1)) ?></span>
                  </div>
                  <div class="status-wrap">
                    <span class="status-dot"></span>
                    <span class="status-badge">Polling...</span>
                  </div>
                </div>
                <div class="svc-name"><?= strtoupper($svc['nombre_servicio']) ?></div>
                <div class="svc-port">:<?= $svc['puerto'] ?></div>
                <div class="svc-actions">
                  <a href="<?= $url ?>" target="_blank" class="btn-launch">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    Abrir
                  </a>
                  <button class="btn-del"
                    onclick="Dashboard.destroyService('<?= htmlspecialchars(addslashes($empresa['nombre'])) ?>','<?= htmlspecialchars(addslashes($svc['nombre_servicio'])) ?>')"
                    title="Eliminar">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Hidden form (used by dashboard.js bindEvents) -->
        <form class="deploy-form hidden" data-empresa="<?= htmlspecialchars($empresa['nombre']) ?>">
          <input type="hidden" name="empresa" value="<?= htmlspecialchars($empresa['nombre']) ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="servicio" class="svc-input">
        </form>
        <?php endforeach; ?>
      </div>
    </section>

  </div><!-- /content -->

  <footer style="padding:1.5rem 1.75rem;text-align:center;font-family:var(--fm);font-size:9px;color:var(--muted);letter-spacing:.1em;border-top:1px solid var(--border)">
    © 2026 TenSaaS Infrastructure &mdash; All Systems Monitored
  </footer>
</main>

<!-- ── DEPLOY MODAL ──────────────────────────────────────── -->
<div class="overlay" id="deploy-overlay">
  <div class="modal">
    <div class="modal-title" id="modal-title">Desplegar servicio</div>
    <select id="modal-select">
      <option value="">Seleccionar servicio...</option>
    </select>
    <div class="modal-footer">
      <button class="btn-ok" id="modal-ok">Desplegar</button>
      <button class="btn-cancel" onclick="closeModal()">Cancelar</button>
    </div>
  </div>
</div>

<script>
/* ── THEME ───────────────────────────────────────────────── */
function applyTheme(){
  const light = localStorage.theme==='light'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme:light)').matches);
  document.documentElement.classList.toggle('light',light);
  updateThemeUI();
}
function toggleTheme(){
  const l=document.documentElement.classList.toggle('light');
  localStorage.theme=l?'light':'dark';
  updateThemeUI();
  if(window.Dashboard?.metricsChart){
    const tc=l?'#475569':'#64748b',gc=l?'rgba(0,0,0,.06)':'rgba(255,255,255,.06)';
    const c=window.Dashboard.metricsChart;
    ['y','x'].forEach(ax=>{c.options.scales[ax].ticks.color=tc;if(ax==='y')c.options.scales[ax].grid.color=gc});
    c.options.plugins.legend.labels.color=tc;c.update();
  }
}
function updateThemeUI(){
  const l=document.documentElement.classList.contains('light');
  document.getElementById('sun-icon')?.classList.toggle('hidden',!l);
  document.getElementById('moon-icon')?.classList.toggle('hidden',l);
}
window.Dashboard=window.Dashboard||{};
window.Dashboard.toggleTheme=toggleTheme;
window.Dashboard.updateThemeUI=updateThemeUI;
applyTheme();

/* ── REFRESH CLOCK ───────────────────────────────────────── */
function tickRefresh(){
  const el=document.getElementById('last-refresh');
  if(el)el.textContent='Actualizado '+new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(tickRefresh,1000);

/* ── COUNT-UP ────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.count-up').forEach(el=>{
    const target=parseInt(el.dataset.target||0);
    let cur=0;const step=Math.max(1,Math.ceil(target/25));
    const t=setInterval(()=>{cur=Math.min(cur+step,target);el.textContent=cur;if(cur>=target)clearInterval(t)},35);
  });
});

/* ── DEPLOY MODAL ────────────────────────────────────────── */
const catalog=<?= json_encode(array_values($servicios_catalogo)) ?>;
const companies=<?= json_encode(array_map(fn($e)=>[
  'nombre'=>$e['nombre'],
  'contratados'=>array_column($servicios_por_empresa[$e['id']]??[],'nombre_servicio')
],$empresas)) ?>;

let activeEmpresa=null;

function openModal(nombre){
  activeEmpresa=nombre;
  document.getElementById('modal-title').textContent='Desplegar en '+nombre;
  const co=companies.find(c=>c.nombre===nombre);
  const taken=(co?.contratados||[]).map(s=>s.toLowerCase());
  const avail=catalog.filter(s=>!taken.includes(s.toLowerCase()));
  const sel=document.getElementById('modal-select');
  sel.innerHTML='<option value="">Seleccionar servicio...</option>';
  avail.forEach(s=>{const o=document.createElement('option');o.value=s;o.textContent=s.toUpperCase();sel.appendChild(o)});
  document.getElementById('deploy-overlay').classList.add('open');
}
function closeModal(){
  document.getElementById('deploy-overlay').classList.remove('open');
  activeEmpresa=null;
}
document.getElementById('deploy-overlay').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal()});

document.getElementById('modal-ok').onclick=async()=>{
  const svc=document.getElementById('modal-select').value;
  if(!svc||!activeEmpresa)return;
  const form=document.querySelector(`.deploy-form[data-empresa="${activeEmpresa}"]`);
  if(!form)return;
  form.querySelector('.svc-input').value=svc;
  const fd=new FormData(form);
  const btn=document.getElementById('modal-ok');
  btn.textContent='Desplegando...';btn.disabled=true;
  try{
    const r=await fetch('deploy_service.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d=await r.json();
    closeModal();
    if(d.status==='success'){window.Dashboard?.showNotification('Desplegado','Servicio iniciado correctamente','success');setTimeout(()=>location.reload(),1500)}
    else window.Dashboard?.showNotification('Error',d.message||'Fallo en el despliegue','error');
  }catch{window.Dashboard?.showNotification('Error','Error de conexión','error')}
  finally{btn.textContent='Desplegar';btn.disabled=false}
};
</script>

<script src="assets/js/dashboard.js"></script>
</body>
</html>
<?php mysqli_close($conn); ?>

