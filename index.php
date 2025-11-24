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
        'max_tokens' => 15000,
    ];

    $ch = curl_init($config['endpoint']);
    setCurlCaBundle($ch);
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
        $friendlyError = 'Error de conexión: ' . $curlError;

        if (stripos($curlError, 'SSL certificate problem') !== false) {
            $friendlyError .= ' Verifica que el sistema pueda validar certificados raíz. En Windows asegúrate de que cacert.pem esté disponible y configurado; en Linux confirma que el paquete de certificados del sistema esté instalado o define CURLOPT_CAINFO con la ruta adecuada.';
        }

        return ['ok' => false, 'error' => $friendlyError];
    }

    if ($httpCode !== 200) {
        return ['ok' => false, 'error' => 'Respuesta HTTP inesperada: ' . $httpCode, 'raw' => $response];
    }

    $decoded = json_decode($response, true);

    $contentReply = '';
    if (isset($decoded['choices'][0]['message']['content'])) {
        $messageContent = $decoded['choices'][0]['message']['content'];
        if (is_array($messageContent)) {
            foreach ($messageContent as $segment) {
                if (is_array($segment) && isset($segment['text'])) {
                    $contentReply .= $segment['text'];
                } elseif (is_string($segment)) {
                    $contentReply .= $segment;
                }
            }
        } elseif (is_string($messageContent)) {
            $contentReply = $messageContent;
        }
    }

    if ($contentReply === '' && isset($decoded['choices'][0]['text']) && is_string($decoded['choices'][0]['text'])) {
        $contentReply = $decoded['choices'][0]['text'];
    }

    if ($contentReply === '' && is_array($decoded)) {
        $contentReply = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    return ['ok' => true, 'reply' => $contentReply];
}

function setCurlCaBundle($ch)
{
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $winCa = 'D:\\AppServ\\cacert.pem';
        if (file_exists($winCa)) {
            curl_setopt($ch, CURLOPT_CAINFO, $winCa);
        }
    }
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/github-markdown-css/5.5.1/github-markdown-light.min.css">
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
        .agent-error h2 {
            color: #c00;
        }
        .agent-reply .markdown-body {
            padding: 0;
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
            <div class="agent-reply" id="agent-reply" data-content="<?php echo htmlspecialchars($agentReply, ENT_QUOTES, 'UTF-8'); ?>">
                <h2>Respuesta del agente</h2>
                <div id="agent-reply-body" class="markdown-body"></div>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.1/marked.min.js"></script>
    <script>
        var textarea = document.getElementById('contenido');
        var editor = CodeMirror.fromTextArea(textarea, {
            lineNumbers: true,
            mode: 'application/json',
            matchBrackets: true,
            lineWrapping: true,
            theme: 'default'
        });

        var replyContainer = document.getElementById('agent-reply');
        if (replyContainer) {
            var rawContent = replyContainer.getAttribute('data-content') || '';
            var replyBody = document.getElementById('agent-reply-body');
            if (rawContent.trim().length > 0 && replyBody) {
                replyBody.innerHTML = marked.parse(rawContent);
            } else if (replyBody) {
                replyBody.textContent = 'La respuesta del agente no se pudo interpretar.';
            }
        }
    </script>
</body>
</html>
