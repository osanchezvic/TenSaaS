<?php
session_start();

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

// Login logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresa = $_POST['empresa'] ?? '';
    $usuario = $_POST['usuario'] ?? '';
    $password = $_POST['password'] ?? '';

    $sql = "SELECT hash_password FROM usuarios WHERE empresa = ? AND usuario = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $empresa, $usuario);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $hash = $row['hash_password'];
        if (password_verify($password, $hash)) {
            $_SESSION['empresa'] = $empresa;
            $_SESSION['usuario'] = $usuario;
            header("Location: dashboard.php");
            exit;
        }
    }
    $error = "Credenciales invalidas";

<!DOCTYPE html>
<html>
<head>
    <title>Panel Login</title>
</head>
<body>
    <form method="post" id="loginForm">
        Empresa: <input type="text" name="empresa" required><br>
        Usuario: <input type="text" name="usuario" required><br>
        Password: <input type="password" name="password" id="password" required>
        <button type="button" onclick="togglePassword()">Ver</button><br>
        <button type="submit">Login</button>
    </form>
    <script>
        function togglePassword() {
            var x = document.getElementById("password");
            if (x.type === "password") { x.type = "text"; } else { x.type = "password"; }
        }
    </script>
    <?php
    if (isset($error)) echo "<p style='color:red'>$error</p>";
    ?>
</body>
</html>