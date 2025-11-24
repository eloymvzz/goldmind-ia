<?php
// Simple text persistence using PHP 5.x
// Now also sends the saved content to an AI agent.
$filePath = __DIR__ . '/texto-guardado.txt';

$agentConfig = [
    'endpoint' => 'https://updm4qp227hrrp43wru3np4h.agents.do-ai.run/api/v1/chat/completions',
    'apiKey'   => '9W60bykvIihVVtK2GPBp6dcsmBh8xxIY',
];

function sendToAgent($content, array $config)
{
    if (trim($content) === '') {
        return ['ok' => false, 'error' => 'No hay contenido para enviar al agente.'];
    }

    if (empty($config['endpoint']) || empty($config['apiKey'])) {
        return ['ok' => false, 'error' => 'El agente no está configurado correctamente.'];
    }

    $payload = [
        'messages' => [
            ['role' => 'user', 'content' => $content],
        ],
        'temperature' => 0.4,
        'max_tokens' => 600,
    ];

    $ch = curl_init($config['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $config['apiKey'],
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => 'Error de conexión: ' . $curlError];
    }

    if ($httpCode !== 200) {
        return ['ok' => false, 'error' => 'Respuesta HTTP inesperada: ' . $httpCode, 'raw' => $response];
    }

    $decoded = json_decode($response, true);
    $contentReply = isset($decoded['choices'][0]['message']['content']) ? $decoded['choices'][0]['message']['content'] : '';

    return ['ok' => true, 'reply' => $contentReply];
}

$savedContent = '';
$agentReply = null;
$agentError = null;

if (file_exists($filePath)) {
    $savedContent = file_get_contents($filePath);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contenido = isset($_POST['contenido']) ? $_POST['contenido'] : '';
    file_put_contents($filePath, $contenido);

    $agentResult = sendToAgent($contenido, $agentConfig);
    if ($agentResult['ok']) {
        $agentReply = $agentResult['reply'];
    } else {
        $agentError = $agentResult['error'];
    }

    $savedContent = $contenido;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editor de texto</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f6f6f6;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            margin-bottom: 10px;
        }
        form {
            background: #fff;
            padding: 15px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .CodeMirror {
            border: 1px solid #ccc;
            height: 400px;
        }
        .actions {
            margin-top: 12px;
            text-align: right;
        }
        button { 
            padding: 10px 16px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            background: #0069d9;
        }
        .agent-reply, .agent-error {
            margin-top: 16px;
            background: #fff;
            padding: 12px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .agent-reply pre {
            white-space: pre-wrap;
            word-break: break-word;
        }
        .agent-error h2 {
            color: #c00;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Guardar texto</h1>
        <form method="post">
            <textarea id="contenido" name="contenido"><?php echo htmlspecialchars($savedContent, ENT_QUOTES, 'UTF-8'); ?></textarea>
            <div class="actions">
                <button type="submit">Guardar</button>
            </div>
        </form>
        <?php if ($agentReply !== null): ?>
            <div class="agent-reply">
                <h2>Respuesta del agente</h2>
                <pre><?php echo htmlspecialchars($agentReply, ENT_QUOTES, 'UTF-8'); ?></pre>
            </div>
        <?php elseif ($agentError !== null): ?>
            <div class="agent-error">
                <h2>Error al consultar al agente</h2>
                <p><?php echo htmlspecialchars($agentError, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.15/addon/edit/matchbrackets.min.js"></script>
    <script>
        var textarea = document.getElementById('contenido');
        var editor = CodeMirror.fromTextArea(textarea, {
            lineNumbers: true,
            mode: 'application/json',
            matchBrackets: true,
            lineWrapping: true,
            theme: 'default'
        });
    </script>
</body>
</html>
