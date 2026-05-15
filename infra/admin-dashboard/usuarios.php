<?php
session_start();
if (!isset($_SESSION['admin'])) { header("Location: index.php"); exit; }

$db_host = getenv('DB_HOST') ?: 'infra_users_db';
$db_name = getenv('DB_NAME') ?: 'users_db';
$db_user = getenv('DB_USER') ?: 'users_user';
$db_pass = getenv('DB_PASSWORD');
if (!$db_pass) die("DB Error: DB_PASSWORD not set");

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

mysqli_set_charset($conn, "utf8mb4");

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_usuario'])) {
    $u  = trim($_POST['usuario']  ?? '');
    $p  = $_POST['password']      ?? '';
    $ei = (int)($_POST['empresa_id'] ?? 0);
    $ea = (int)($_POST['es_admin']   ?? 0);

    if ($u && $p && $ei) {
        $hash = password_hash($p, PASSWORD_BCRYPT);
        $st   = mysqli_prepare($conn, "INSERT INTO usuarios (usuario, hash_password, empresa_id, es_admin, estado) VALUES (?, ?, ?, ?, 'activo')");
        mysqli_stmt_bind_param($st, 'ssii', $u, $hash, $ei, $ea);
        if (mysqli_stmt_execute($st)) {
            $flash = ['type' => 'success', 'msg' => "Usuario «{$u}» creado correctamente."];
        } else {
            $flash = ['type' => 'error', 'msg' => 'Error al crear el usuario: ' . mysqli_error($conn)];
        }
    } else {
        $flash = ['type' => 'error', 'msg' => 'Todos los campos son obligatorios.'];
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id !== (int)$_SESSION['admin_id']) {
        $st = mysqli_prepare($conn, "UPDATE usuarios SET estado='inactivo' WHERE id=?");
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        $flash = ['type' => 'success', 'msg' => 'Usuario desactivado correctamente.'];
    } else {
        $flash = ['type' => 'error', 'msg' => 'No puedes desactivar tu propia cuenta.'];
    }
}

if (isset($_GET['restore'])) {
    $id = (int)$_GET['restore'];
    $st = mysqli_prepare($conn, "UPDATE usuarios SET estado='activo' WHERE id=?");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $flash = ['type' => 'success', 'msg' => 'Usuario reactivado correctamente.'];
}

// Load data
$empresas = mysqli_fetch_all(mysqli_query($conn, "SELECT id, nombre FROM empresas WHERE estado='activa' ORDER BY nombre"), MYSQLI_ASSOC);

$filter_empresa = (int)($_GET['empresa'] ?? 0);
$filter_estado  = $_GET['estado'] ?? 'activo';
$filter_search  = trim($_GET['q'] ?? '');

$where_parts = ["1=1"];
$params      = [];
$types       = '';

if ($filter_empresa > 0) {
    $where_parts[] = "u.empresa_id = ?";
    $params[] = $filter_empresa; $types .= 'i';
}
if ($filter_estado !== 'todos') {
    $where_parts[] = "u.estado = ?";
    $params[] = $filter_estado; $types .= 's';
}
if ($filter_search !== '') {
    $where_parts[] = "u.usuario LIKE ?";
    $like = "%{$filter_search}%";
    $params[] = $like; $types .= 's';
}

$sql  = "SELECT u.*, e.nombre AS empresa_nombre FROM usuarios u LEFT JOIN empresas e ON u.empresa_id = e.id WHERE " . implode(' AND ', $where_parts) . " ORDER BY u.id DESC";
$st2  = mysqli_prepare($conn, $sql);
if ($params) mysqli_stmt_bind_param($st2, $types, ...$params);
mysqli_stmt_execute($st2);
$usuarios = mysqli_fetch_all(mysqli_stmt_get_result($st2), MYSQLI_ASSOC);

// Stats
$total_activos   = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM usuarios WHERE estado='activo'"))[0];
$total_inactivos = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM usuarios WHERE estado='inactivo'"))[0];
$total_admins    = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM usuarios WHERE es_admin=1 AND estado='activo'"))[0];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Usuarios — TenSaaS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --bg:#07090f;--surface:#0d1321;--card:#111827;
  --border:rgba(255,255,255,0.07);--border-hi:rgba(255,255,255,0.13);
  --accent:#38bdf8;--accent-dim:rgba(56,189,248,0.1);
  --success:#34d399;--danger:#fb7185;--warning:#fbbf24;
  --text:#e2e8f0;--muted:#4b5563;--muted2:#64748b;
  --fh:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'JetBrains Mono',monospace;
}
.light{
  --bg:#f1f5f9;--surface:#fff;--card:#f8fafc;
  --border:rgba(0,0,0,0.07);--border-hi:rgba(0,0,0,0.13);
  --accent:#0284c7;--accent-dim:rgba(2,132,199,0.09);
  --text:#0f172a;--muted:#94a3b8;--muted2:#64748b;
}

html,body{height:100%;min-height:100vh}
body{background:var(--bg);color:var(--text);font-family:var(--fb);transition:background .3s,color .3s}
body::before{content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(56,189,248,.028) 1px,transparent 1px),linear-gradient(90deg,rgba(56,189,248,.028) 1px,transparent 1px);
  background-size:56px 56px;pointer-events:none;z-index:0}
.light body::before{background-image:linear-gradient(rgba(0,0,0,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,0,0,.04) 1px,transparent 1px)}

/* ── TOPBAR ──────────────────────────────────────────────── */
.topbar{
  position:sticky;top:0;z-index:30;
  background:rgba(7,9,15,.88);backdrop-filter:blur(16px);
  border-bottom:1px solid var(--border);
  padding:.75rem 2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;
}
.light .topbar{background:rgba(241,245,249,.92)}
.tb-brand{display:flex;align-items:center;gap:9px}
.tb-ico{width:30px;height:30px;background:var(--accent-dim);border:1px solid rgba(56,189,248,.2);border-radius:8px;display:flex;align-items:center;justify-content:center}
.tb-ico svg{width:15px;height:15px;color:var(--accent)}
.tb-name{font-family:var(--fh);font-size:.9rem;font-weight:800;letter-spacing:-.02em}
.tb-sep{width:1px;height:18px;background:var(--border);flex-shrink:0}
.page-crumb{font-family:var(--fm);font-size:10px;color:var(--muted);letter-spacing:.06em}
.tb-right{display:flex;align-items:center;gap:7px}
.icon-btn{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted2);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,color .15s;text-decoration:none;flex-shrink:0}
.icon-btn:hover{background:var(--accent-dim);color:var(--accent)}
.icon-btn svg{width:14px;height:14px}

/* ── LAYOUT ──────────────────────────────────────────────── */
.page{max-width:1200px;margin:0 auto;padding:2rem;position:relative;z-index:1;display:flex;flex-direction:column;gap:1.75rem}

/* ── STATS ───────────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem}
.stat-card{
  background:var(--surface);border:1px solid var(--border);border-radius:12px;
  padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;
  transition:border-color .2s;
}
.stat-card:hover{border-color:var(--border-hi)}
.stat-label{font-family:var(--fm);font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.stat-val{font-family:var(--fh);font-size:1.75rem;font-weight:800;letter-spacing:-.04em;line-height:1}
.stat-ico{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.stat-ico svg{width:18px;height:18px}

/* ── FLASH ───────────────────────────────────────────────── */
.flash{
  padding:.75rem 1.125rem;border-radius:10px;
  font-family:var(--fm);font-size:11px;letter-spacing:.04em;
  display:flex;align-items:center;gap:.625rem;
}
.flash.success{background:rgba(52,211,153,.09);border:1px solid rgba(52,211,153,.25);color:var(--success)}
.flash.error  {background:rgba(251,113,133,.09);border:1px solid rgba(251,113,133,.25);color:var(--danger)}
.flash svg{width:14px;height:14px;flex-shrink:0}

/* ── SECTION HEADER ──────────────────────────────────────── */
.sec-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.875rem}
.sec-title{font-family:var(--fh);font-size:.875rem;font-weight:700;display:flex;align-items:center;gap:7px}
.sec-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}

/* ── CREATE FORM ─────────────────────────────────────────── */
.form-card{background:var(--surface);border:1px solid var(--border);border-radius:13px;overflow:hidden}
.form-card-head{
  padding:.75rem 1.25rem;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:8px;
  background:rgba(56,189,248,.03);
}
.form-card-head svg{width:14px;height:14px;color:var(--accent)}
.form-card-head span{font-family:var(--fm);font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted2)}
.form-body{padding:1.125rem 1.25rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr 1.4fr auto auto;gap:.625rem;align-items:end}
.field-group{display:flex;flex-direction:column;gap:4px}
.field-label{font-family:var(--fm);font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.field-input{
  padding:8px 11px;background:rgba(255,255,255,.04);border:1px solid var(--border);
  border-radius:8px;color:var(--text);font-family:var(--fb);font-size:12.5px;
  outline:none;transition:border-color .2s,background .2s;width:100%;
}
.light .field-input{background:rgba(0,0,0,.04)}
.field-input:focus{border-color:rgba(56,189,248,.4);background:rgba(56,189,248,.03)}
.field-input::placeholder{color:var(--muted)}
.field-select{appearance:none;-webkit-appearance:none;cursor:pointer}
.toggle-wrap{display:flex;align-items:center;gap:7px;padding:8px 11px;
  background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;cursor:pointer;height:36px}
.light .toggle-wrap{background:rgba(0,0,0,.04)}
.toggle-wrap input{accent-color:var(--accent);width:14px;height:14px;cursor:pointer}
.toggle-wrap span{font-size:12px;color:var(--muted2)}
.btn-create{
  padding:8px 16px;background:var(--accent);border:none;border-radius:8px;
  color:#050d18;font-family:var(--fh);font-size:12px;font-weight:700;
  cursor:pointer;transition:opacity .15s,transform .1s;white-space:nowrap;height:36px;
  display:flex;align-items:center;gap:5px;
}
.btn-create:hover{opacity:.9;transform:translateY(-1px)}
.btn-create:active{transform:scale(.98)}
.btn-create svg{width:12px;height:12px}

/* ── FILTER BAR ──────────────────────────────────────────── */
.filter-bar{display:flex;align-items:center;gap:.625rem;flex-wrap:wrap}
.filter-search{
  flex:1;min-width:160px;max-width:260px;
  padding:7px 11px;background:var(--surface);border:1px solid var(--border);
  border-radius:8px;color:var(--text);font-family:var(--fb);font-size:12.5px;
  outline:none;transition:border-color .2s;
}
.filter-search:focus{border-color:rgba(56,189,248,.4)}
.filter-search::placeholder{color:var(--muted)}
.filter-select{
  padding:7px 11px;background:var(--surface);border:1px solid var(--border);
  border-radius:8px;color:var(--muted2);font-family:var(--fm);font-size:10px;
  outline:none;cursor:pointer;appearance:none;-webkit-appearance:none;
  letter-spacing:.04em;
}
.filter-count{font-family:var(--fm);font-size:9px;color:var(--muted);margin-left:auto;white-space:nowrap}

/* ── TABLE ───────────────────────────────────────────────── */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:13px;overflow:hidden}
.tbl-head{
  display:grid;grid-template-columns:52px 1fr 1.1fr .8fr .7fr .7fr 90px;
  padding:.5rem 1rem;background:rgba(255,255,255,.025);border-bottom:1px solid var(--border);
  font-family:var(--fm);font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);
}
.light .tbl-head{background:rgba(0,0,0,.03)}
.tbl-row{
  display:grid;grid-template-columns:52px 1fr 1.1fr .8fr .7fr .7fr 90px;
  align-items:center;padding:.625rem 1rem;
  border-bottom:1px solid var(--border);transition:background .15s;
}
.tbl-row:last-child{border-bottom:none}
.tbl-row:hover{background:rgba(56,189,248,.025)}
.tbl-row.inactive{opacity:.5}
.cell-id{font-family:var(--fm);font-size:10px;color:var(--muted)}
.cell-user{display:flex;align-items:center;gap:7px;min-width:0}
.u-ava{
  width:26px;height:26px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-family:var(--fh);font-size:10px;font-weight:800;
}
.cell-username{font-size:12.5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cell-empresa{font-family:var(--fm);font-size:10px;color:var(--muted2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cell-admin{display:flex}
.badge{
  display:inline-flex;align-items:center;padding:2px 8px;border-radius:99px;
  font-family:var(--fm);font-size:8px;letter-spacing:.05em;
}
.badge-admin{background:rgba(56,189,248,.1);color:var(--accent);border:1px solid rgba(56,189,248,.2)}
.badge-user {background:rgba(255,255,255,.05);color:var(--muted2);border:1px solid var(--border)}
.badge-active  {background:rgba(52,211,153,.1);color:var(--success);border:1px solid rgba(52,211,153,.2)}
.badge-inactive{background:rgba(251,113,133,.1);color:var(--danger);border:1px solid rgba(251,113,133,.2)}
.cell-date{font-family:var(--fm);font-size:9px;color:var(--muted)}
.cell-actions{display:flex;align-items:center;justify-content:flex-end;gap:5px}
.act-btn{
  width:26px;height:26px;border-radius:6px;border:1px solid var(--border);
  background:transparent;color:var(--muted);display:flex;align-items:center;justify-content:center;
  cursor:pointer;transition:background .15s,color .15s,border-color .15s;text-decoration:none;
  flex-shrink:0;
}
.act-btn svg{width:11px;height:11px}
.act-btn.del:hover{background:rgba(251,113,133,.1);color:var(--danger);border-color:rgba(251,113,133,.3)}
.act-btn.restore:hover{background:rgba(52,211,153,.1);color:var(--success);border-color:rgba(52,211,153,.3)}

.tbl-empty{padding:3rem;text-align:center;color:var(--muted);display:flex;flex-direction:column;align-items:center;gap:.75rem}
.tbl-empty svg{width:28px;height:28px;opacity:.3}
.tbl-empty p{font-size:12.5px}

/* ── RESPONSIVE ──────────────────────────────────────────── */
@media(max-width:900px){
  .form-grid{grid-template-columns:1fr 1fr;gap:.5rem}
  .btn-create{width:100%}
  .tbl-head,.tbl-row{grid-template-columns:40px 1fr 1fr 70px}
  .hide-sm{display:none}
  .stats-row{grid-template-columns:1fr}
}

/* ── ANIM ────────────────────────────────────────────────── */
@keyframes fade-up{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}
.fu{animation:fade-up .3s ease both}
.fu1{animation-delay:.05s}.fu2{animation-delay:.1s}.fu3{animation-delay:.15s}
</style>
</head>
<body>

<!-- Topbar -->
<header class="topbar">
  <div style="display:flex;align-items:center;gap:.875rem">
    <a href="index.php" class="tb-brand" style="text-decoration:none;color:inherit">
      <div class="tb-ico">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
      </div>
      <span class="tb-name">TenSaaS</span>
    </a>
    <div class="tb-sep"></div>
    <span class="page-crumb">GESTIÓN DE USUARIOS</span>
  </div>
  <div class="tb-right">
    <button onclick="toggleTheme()" class="icon-btn" title="Cambiar tema">
      <svg id="sun-icon" class="hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 5a7 7 0 100 14 7 7 0 000-14z"/></svg>
      <svg id="moon-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
    </button>
    <a href="index.php" class="icon-btn" title="Volver al dashboard">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    </a>
  </div>
</header>

<div class="page">

  <?php if ($flash): ?>
  <div class="flash <?= $flash['type'] ?> fu">
    <?php if ($flash['type'] === 'success'): ?>
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    <?php else: ?>
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    <?php endif; ?>
    <?= htmlspecialchars($flash['msg']) ?>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row fu">
    <div class="stat-card">
      <div>
        <div class="stat-label">Usuarios Activos</div>
        <div class="stat-val" style="color:var(--success)"><?= $total_activos ?></div>
      </div>
      <div class="stat-ico" style="background:rgba(52,211,153,.1)">
        <svg fill="none" stroke="#34d399" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      </div>
    </div>
    <div class="stat-card">
      <div>
        <div class="stat-label">Administradores</div>
        <div class="stat-val" style="color:var(--accent)"><?= $total_admins ?></div>
      </div>
      <div class="stat-ico" style="background:rgba(56,189,248,.1)">
        <svg fill="none" stroke="#38bdf8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      </div>
    </div>
    <div class="stat-card">
      <div>
        <div class="stat-label">Inactivos</div>
        <div class="stat-val" style="color:var(--muted2)"><?= $total_inactivos ?></div>
      </div>
      <div class="stat-ico" style="background:rgba(255,255,255,.04)">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
      </div>
    </div>
  </div>

  <!-- Create form -->
  <div class="form-card fu fu1">
    <div class="form-card-head">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
      <span>Crear nuevo usuario</span>
    </div>
    <div class="form-body">
      <form method="POST" autocomplete="off">
        <div class="form-grid">
          <div class="field-group">
            <label class="field-label">Usuario</label>
            <input type="text" name="usuario" class="field-input" placeholder="nombre_usuario" required autocomplete="off">
          </div>
          <div class="field-group">
            <label class="field-label">Contraseña</label>
            <input type="password" name="password" class="field-input" placeholder="••••••••" required autocomplete="new-password">
          </div>
          <div class="field-group">
            <label class="field-label">Empresa</label>
            <select name="empresa_id" class="field-input field-select" required>
              <option value="">Seleccionar empresa...</option>
              <?php foreach ($empresas as $e): ?>
              <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-group">
            <label class="field-label">Rol</label>
            <label class="toggle-wrap">
              <input type="checkbox" name="es_admin" value="1">
              <span>Administrador</span>
            </label>
          </div>
          <div class="field-group">
            <label class="field-label">&nbsp;</label>
            <button type="submit" name="nuevo_usuario" class="btn-create">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
              Crear
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Users table -->
  <div class="fu fu2">
    <div class="sec-head">
      <div class="sec-title">
        <span class="sec-dot" style="background:var(--accent)"></span>
        Usuarios del Sistema
      </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar" style="margin-bottom:.875rem">
      <form method="GET" style="display:contents">
        <input type="text" name="q" class="filter-search" placeholder="Buscar usuario..." value="<?= htmlspecialchars($filter_search) ?>">
        <select name="empresa" class="filter-select" onchange="this.form.submit()">
          <option value="0" <?= $filter_empresa===0?'selected':'' ?>>Todas las empresas</option>
          <?php foreach ($empresas as $e): ?>
          <option value="<?= $e['id'] ?>" <?= $filter_empresa===$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="estado" class="filter-select" onchange="this.form.submit()">
          <option value="activo"  <?= $filter_estado==='activo' ?'selected':'' ?>>Activos</option>
          <option value="inactivo"<?= $filter_estado==='inactivo'?'selected':'' ?>>Inactivos</option>
          <option value="todos"   <?= $filter_estado==='todos'  ?'selected':'' ?>>Todos</option>
        </select>
        <button type="submit" class="icon-btn" style="width:32px;height:32px">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </button>
      </form>
      <span class="filter-count"><?= count($usuarios) ?> resultado<?= count($usuarios)!==1?'s':'' ?></span>
    </div>

    <div class="table-wrap">
      <div class="tbl-head">
        <span>#</span>
        <span>Usuario</span>
        <span>Empresa</span>
        <span class="hide-sm">Rol</span>
        <span class="hide-sm">Estado</span>
        <span class="hide-sm">Creado</span>
        <span style="text-align:right">Acciones</span>
      </div>

      <?php if (empty($usuarios)): ?>
      <div class="tbl-empty">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <p>No se encontraron usuarios con estos filtros.</p>
      </div>
      <?php else: ?>
      <?php
        // Generate a consistent hue per username for avatars
        $hues = [199,142,265,25,340,39,180,310,60,280];
        foreach ($usuarios as $i => $u):
          $h   = $hues[crc32($u['usuario']) % count($hues)];
          $col = "hsl({$h},65%,62%)";
          $bg  = "hsl({$h},65%,62%,0.12)";
          $inactive = $u['estado'] !== 'activo';
          $created  = isset($u['created_at']) ? date('d/m/y', strtotime($u['created_at'])) : '—';
      ?>
      <div class="tbl-row <?= $inactive ? 'inactive' : '' ?>">
        <div class="cell-id"><?= $u['id'] ?></div>
        <div class="cell-user">
          <div class="u-ava" style="background:<?= $bg ?>;color:<?= $col ?>;border:1px solid <?= $col ?>33">
            <?= strtoupper(substr($u['usuario'], 0, 1)) ?>
          </div>
          <span class="cell-username" style="color:<?= $col ?>"><?= htmlspecialchars($u['usuario']) ?></span>
        </div>
        <div class="cell-empresa"><?= htmlspecialchars($u['empresa_nombre'] ?? $u['empresa'] ?? '—') ?></div>
        <div class="cell-admin hide-sm">
          <?php if ($u['es_admin']): ?>
            <span class="badge badge-admin">Admin</span>
          <?php else: ?>
            <span class="badge badge-user">Usuario</span>
          <?php endif; ?>
        </div>
        <div class="hide-sm">
          <?php if (!$inactive): ?>
            <span class="badge badge-active">Activo</span>
          <?php else: ?>
            <span class="badge badge-inactive">Inactivo</span>
          <?php endif; ?>
        </div>
        <div class="cell-date hide-sm"><?= $created ?></div>
        <div class="cell-actions">
          <?php if (!$inactive): ?>
          <a href="?delete=<?= $u['id'] ?>&estado=<?= $filter_estado ?>&empresa=<?= $filter_empresa ?>&q=<?= urlencode($filter_search) ?>"
             class="act-btn del" title="Desactivar"
             onclick="return confirm('¿Desactivar al usuario <?= htmlspecialchars(addslashes($u['usuario'])) ?>?')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
          </a>
          <?php else: ?>
          <a href="?restore=<?= $u['id'] ?>&estado=<?= $filter_estado ?>&empresa=<?= $filter_empresa ?>&q=<?= urlencode($filter_search) ?>"
             class="act-btn restore" title="Reactivar">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <footer style="text-align:center;font-family:var(--fm);font-size:9px;color:var(--muted);letter-spacing:.1em;padding:.5rem 0 1rem">
    © 2026 TenSaaS Infrastructure
  </footer>
</div>

<script>
function applyTheme(){
  const light=localStorage.theme==='light'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme:light)').matches);
  document.documentElement.classList.toggle('light',light);
  updateIcons(light);
}
function toggleTheme(){
  const l=document.documentElement.classList.toggle('light');
  localStorage.theme=l?'light':'dark';
  updateIcons(l);
}
function updateIcons(light){
  document.getElementById('sun-icon')?.classList.toggle('hidden',!light);
  document.getElementById('moon-icon')?.classList.toggle('hidden',light);
}
.hidden{display:none!important}
applyTheme();
</script>
<style>.hidden{display:none!important}</style>
</body>
</html>
<?php mysqli_close($conn); ?>
