<?php
session_start();
if (!isset($_SESSION['empresa'])) {
    header("Location: index.php");
    exit;
}

$empresa = htmlspecialchars($_SESSION['empresa']);
$usuario = htmlspecialchars($_SESSION['usuario']);

// Config DB
$host = getenv('DB_HOST') ?: 'infra_users_db';
$dbname = getenv('DB_NAME') ?: 'users_db';
$user = getenv('DB_USER') ?: 'users_user';
$pass = getenv('DB_PASSWORD');
if (!$pass) die("Config Error: DB_PASSWORD not set");

$conn = mysqli_connect($host, $user, $pass, $dbname);
if (!$conn) {
    die("Error DB: " . mysqli_connect_error());
}

// Obtener servicios de esta empresa desde txt (simulado)
$servicios_txt = "";
if (file_exists("/scripts/databases/servicios.txt")) {
    $lineas = file("/scripts/databases/servicios.txt");
    foreach ($lineas as $linea) {
        if (strpos($linea, "$empresa:") === 0) {
            $servicios_txt .= $linea;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - <?php echo $empresa; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header h1 { margin: 0; color: #333; }
        .logout-btn { padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; }
        .servicios-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
        .servicio-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .servicio-card h3 { margin-top: 0; color: #007bff; }
        .servicio-card p { margin: 10px 0; font-size: 14px; color: #666; }
        .servicio-btn { display: inline-block; margin-top: 10px; padding: 8px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
        .servicio-btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Dashboard - <?php echo $empresa; ?></h1>
            <p>Bienvenido, <?php echo $usuario; ?></p>
        </div>
        <a href="logout.php" class="logout-btn">Cerrar sesión</a>
    </div>

    <h2>Tus servicios contratados</h2>
    <div class="servicios-grid">
        <?php if (empty($servicios_txt)): ?>
            <p>No tienes servicios contratados aún.</p>
        <?php else: ?>
            <?php
            $lineas = explode("\n", $servicios_txt);
            foreach ($lineas as $linea) {
                if (trim($linea) === '') continue;
                $partes = explode(':', $linea);
                if (count($partes) >= 3) {
                    $srv = $partes[1];
                    $puerto = $partes[2];
                    echo "<div class='servicio-card'>
                            <h3>" . ucfirst($srv) . "</h3>
                            <p>Puerto: <strong>$puerto</strong></p>
                            <a href='http://localhost:$puerto' class='servicio-btn' target='_blank'>Acceder</a>
                          </div>";
                }
            }
            ?>
        <?php endif; ?>
    </div>
</body>
</html>