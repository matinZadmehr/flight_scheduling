<?php
// webhook.php - For receiving data if you want server-side processing
header('Content-Type: application/json');

// Get data from Telegram Web App
$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    // Save to log file
    $log = date('Y-m-d H:i:s') . " - " . json_encode($data) . "\n";
    file_put_contents('date_selections.log', $log, FILE_APPEND);
    
    // You can also forward to n8n webhook
    $n8nWebhook = 'YOUR_N8N_WEBHOOK_URL';
    $ch = curl_init($n8nWebhook);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
    
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No data received']);
}
?>