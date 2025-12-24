<?php
/**
 * Webhook Handler for Flight Date Selection
 * Sends flight date/time data to n8n webhook
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST method allowed']);
    exit();
}

// Get raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

// Log received data (for debugging)
logData('Flight date received:', $data);

// CONFIGURATION - SET YOUR N8N WEBHOOK URL HERE
$n8n_webhook_url = 'https://aistudio.didbi.com/webhook/form/flight_scheduling'; // ← CHANGE THIS!

// Validate webhook URL
if (empty($n8n_webhook_url) || strpos($n8n_webhook_url, 'your-n8n-domain') !== false) {
    $response = [
        'success' => false,
        'error' => 'N8N webhook URL not configured',
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    echo json_encode($response);
    exit();
}

// Prepare data for n8n
$payload = prepareN8NPayload($data);

// Send to n8n webhook
$result = sendToN8N($n8n_webhook_url, $payload);

// Return response
if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Flight date data sent to n8n successfully',
        'n8n_response' => $result['response'],
        'timestamp' => date('Y-m-d H:i:s'),
        'flight_id' => uniqid('FLIGHT_')
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send flight date to n8n',
        'n8n_error' => $result['error'],
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Prepare payload for n8n
 */
function prepareN8NPayload($data) {
    $payload = [
        'event_type' => 'flight_date_selection',
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time(),
        'source' => $data['source'] ?? 'telegram_web_app',
        'action' => $data['action'] ?? 'unknown'
    ];
    
    // Add date/time information
    if (isset($data['date_time'])) {
        $payload['flight_schedule'] = [
            'date' => $data['date_time']['date'] ?? 'unknown',
            'time_24h' => $data['date_time']['time_24h'] ?? 'unknown',
            'time_12h' => $data['date_time']['time_12h'] ?? 'unknown',
            'formatted_display' => $data['date_time']['formatted'] ?? 'unknown',
            'unix_timestamp' => $data['date_time']['unix_timestamp'] ?? 0,
            'iso_timestamp' => $data['date_time']['iso_string'] ?? 'unknown'
        ];
        
        // Calculate if date is in the future
        $flightTimestamp = $data['date_time']['unix_timestamp'] ?? 0;
        $currentTimestamp = time();
        $payload['flight_schedule']['is_future'] = ($flightTimestamp > $currentTimestamp);
        $payload['flight_schedule']['days_until'] = $flightTimestamp > 0 ? 
            floor(($flightTimestamp - $currentTimestamp) / 86400) : 0;
    }
    
    // Add user selection details
    if (isset($data['user_selection'])) {
        $payload['user_input'] = $data['user_selection'];
    }
    
    // Add Telegram user info
    if (isset($data['telegram_user'])) {
        $payload['telegram_user'] = $data['telegram_user'];
    }
    
    // Add metadata
    $payload['metadata'] = [
        'ip_address' => $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $data['metadata']['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timezone' => $data['metadata']['timezone'] ?? 'unknown',
        'language' => $data['metadata']['language'] ?? 'unknown',
        'screen_resolution' => $data['metadata']['screen_resolution'] ?? 'unknown',
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'processed_at' => date('Y-m-d H:i:s')
    ];
    
    // Add validation flags
    $payload['validation'] = [
        'is_valid_date' => isset($data['date_time']['date']) && $data['date_time']['date'] !== 'unknown',
        'is_valid_time' => isset($data['date_time']['time_24h']) && $data['date_time']['time_24h'] !== 'unknown',
        'is_future_date' => $payload['flight_schedule']['is_future'] ?? false,
        'data_integrity' => 'valid'
    ];
    
    return $payload;
}

/**
 * Send data to n8n webhook
 */
function sendToN8N($url, $payload) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: Telegram-Flight-Date-Webhook/1.0'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    // Log the request
    logData('Flight date sent to n8n:', [
        'url' => $url,
        'payload_size' => strlen(json_encode($payload)),
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ]);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    // Consider 2xx and 3xx status codes as success
    if ($httpCode >= 200 && $httpCode < 400) {
        return [
            'success' => true,
            'response' => json_decode($response, true) ?: $response,
            'http_code' => $httpCode
        ];
    }
    
    return [
        'success' => false,
        'error' => "HTTP $httpCode",
        'response' => $response
    ];
}

/**
 * Log data for debugging
 */
function logData($message, $data) {
    $logFile = __DIR__ . '/webhook_date_log.txt';
    $logEntry = date('Y-m-d H:i:s') . " - $message\n";
    $logEntry .= print_r($data, true) . "\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Create a simple HTML test page if accessed via browser
if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Webhook Test Page - Flight Date</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            .container { max-width: 800px; margin: 0 auto; }
            .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            .test-form { background: #f8f9fa; padding: 20px; border-radius: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Webhook Test Page - Flight Date Selection</h1>
            
            <div class="status success">
                <strong>Status:</strong> Webhook is running
            </div>
            
            <div class="test-form">
                <h2>Test the Webhook</h2>
                <p>Use this form to test the webhook manually:</p>
                
                <form id="testForm">
                    <div>
                        <label>Date (YYYY-MM-DD):</label>
                        <input type="date" id="flightDate" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div>
                        <label>Time (24h):</label>
                        <input type="time" id="flightTime" value="14:30">
                    </div>
                    
                    <button type="button" onclick="testWebhook()">Test Webhook</button>
                </form>
                
                <div id="testResult"></div>
            </div>
        </div>
        
        <script>
            async function testWebhook() {
                const flightDate = document.getElementById('flightDate').value;
                const flightTime = document.getElementById('flightTime').value;
                
                // Parse time
                const [hour, minute] = flightTime.split(':');
                const hour12 = hour > 12 ? hour - 12 : (hour == 0 ? 12 : hour);
                const ampm = hour >= 12 ? 'PM' : 'AM';
                const time12h = `${hour12}:${minute} ${ampm}`;
                
                // Create date object for formatting
                const dateObj = new Date(flightDate + 'T' + flightTime);
                const formattedDate = dateObj.toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                
                const data = {
                    action: 'flight_date_selected',
                    timestamp: new Date().toISOString(),
                    date_time: {
                        date: flightDate,
                        time_24h: flightTime,
                        time_12h: time12h,
                        time_24h_display: flightTime,
                        formatted: `${formattedDate} at ${time12h} (${flightTime})`,
                        unix_timestamp: Math.floor(dateObj.getTime() / 1000),
                        iso_string: dateObj.toISOString()
                    },
                    user_selection: {
                        hour: parseInt(hour),
                        minute: parseInt(minute),
                        date_picker_value: flightDate
                    },
                    telegram_user: {
                        telegram_id: 123456789,
                        telegram_username: 'testuser'
                    },
                    source: 'web_test',
                    ip_address: '127.0.0.1'
                };
                
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    document.getElementById('testResult').innerHTML = 
                        '<pre>' + JSON.stringify(result, null, 2) + '</pre>';
                } catch (error) {
                    document.getElementById('testResult').innerHTML = 
                        'Error: ' + error.message;
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit();
}
?>