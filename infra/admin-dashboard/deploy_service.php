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
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        die("Error de cURL: " . $error);
    }
    
    $data = json_decode($response, true);

    header('Content-Type: application/json');
    if ($http_code == 200 && isset($data['status']) && $data['status'] == 'success') {
        echo json_encode(['status' => 'success', 'message' => 'Despliegue completado', 'stdout' => $data['stdout'] ?? '']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $data['stderr'] ?? $data['detail'] ?? 'Error desconocido']);
    }
    exit;
}
?>
