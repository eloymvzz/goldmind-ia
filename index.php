<?php
// Simple text persistence using PHP 5.x
// Now also sends the saved content to an AI agent.
$filePath = __DIR__ . '/texto-guardado.txt';
$historyPath = __DIR__ . '/consultas-historial.txt';

$configPath = __DIR__ . '/agent-config.php';
$agentConfig = [];
if (file_exists($configPath)) {
    $loadedConfig = include $configPath;
    if (is_array($loadedConfig)) {
        $agentConfig = $loadedConfig;
    }
}

function sendToAgent($contextContent, $userQuery, array $config)
{
    if (trim($contextContent) === '' && trim($userQuery) === '') {
        return ['ok' => false, 'error' => 'No hay contenido para enviar al agente.'];
    }

    if (empty($config['endpoint']) || empty($config['apiKey'])) {
        return ['ok' => false, 'error' => 'El agente no está configurado correctamente.'];
    }

    $messageContent = '** PROMPT DE CONTEXTO **' . "\n" . $contextContent;
    $messageContent .= "\n\n" . '** CONSULTA DEL USUARIO **' . "\n" . $userQuery;

    $payload = [
        'messages' => [
            ['role' => 'user', 'content' => $messageContent],
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
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 60,
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

function loadHistory($path)
{
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $history = [];

    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['id'])) {
            $history[] = $decoded;
        }
    }

    return $history;
}

function saveHistory(array $history, $path)
{
    $content = '';
    foreach ($history as $entry) {
        $content .= json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
    }

    file_put_contents($path, $content);
}

function orderHistory(array $history)
{
    usort($history, function ($a, $b) {
        $timeA = isset($a['timestamp']) ? (int) $a['timestamp'] : 0;
        $timeB = isset($b['timestamp']) ? (int) $b['timestamp'] : 0;
        if ($timeA === $timeB) {
            return 0;
        }

        return ($timeA > $timeB) ? -1 : 1;
    });

    return $history;
}

function deleteHistoryEntry($path, $id)
{
    $history = loadHistory($path);
    $filtered = [];
    foreach ($history as $entry) {
        if (!isset($entry['id']) || $entry['id'] !== $id) {
            $filtered[] = $entry;
        }
    }

    saveHistory($filtered, $path);
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

  function ensureWritableFile($path)
  {
      $directory = dirname($path);

      if (!is_dir($directory)) {
          return ['ok' => false, 'error' => "La carpeta de almacenamiento no existe: {$directory}"];
      }

      $errorDetail = null;
      set_error_handler(function ($errno, $errstr) use (&$errorDetail) {
          $errorDetail = $errstr;
          return true;
      });

      $appendFlag = file_exists($path) ? FILE_APPEND : 0;
      $writeResult = @file_put_contents($path, '', $appendFlag);

      restore_error_handler();

      if ($writeResult === false) {
          $hint = $errorDetail ? " Detalle: {$errorDetail}" : '';
          $target = file_exists($path) ? 'archivo' : 'carpeta';
          $targetPath = file_exists($path) ? $path : $directory;

          return [
              'ok' => false,
              'error' => "El servidor no tiene permisos de escritura en la {$target}: {$targetPath}." . $hint,
          ];
      }

      if (!file_exists($path)) {
          @chmod($path, 0664);
      }

      return ['ok' => true];
  }

$savedContent = '';
$userQuery = '';
$agentReply = null;
$agentError = null;
$storageIssue = null;

$historyCheck = ensureWritableFile($historyPath);
$fileCheck = ensureWritableFile($filePath);

if (!$historyCheck['ok']) {
    $storageIssue = $historyCheck['error'];
}

if (!$fileCheck['ok']) {
    $storageIssue = $fileCheck['error'];
}

$history = $historyCheck['ok'] ? orderHistory(loadHistory($historyPath)) : [];

if ($fileCheck['ok'] && file_exists($filePath)) {
    $savedContent = file_get_contents($filePath);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($storageIssue !== null) {
        $agentError = $storageIssue;
    } elseif (isset($_POST['delete_id'])) {
        deleteHistoryEntry($historyPath, $_POST['delete_id']);
        $history = orderHistory(loadHistory($historyPath));
    } else {
        $contenido = isset($_POST['contenido']) ? $_POST['contenido'] : '';
        $consulta = isset($_POST['consulta']) ? $_POST['consulta'] : '';

        $writeResult = file_put_contents($filePath, $contenido);
        if ($writeResult === false) {
            $agentError = 'No se pudo guardar el contenido en el servidor. Verifica los permisos de escritura.';
        } else {
            $agentResult = sendToAgent($contenido, $consulta, $agentConfig);
            if ($agentResult['ok']) {
                $agentReply = $agentResult['reply'];

                $historyEntry = [
                    'id' => uniqid('consulta_', true),
                    'timestamp' => time(),
                    'consulta' => $consulta,
                    'respuesta' => $agentReply,
                ];

                array_unshift($history, $historyEntry);
                $history = orderHistory($history);
                saveHistory($history, $historyPath);
            } else {
                $agentError = $agentResult['error'];
            }
        }

        $savedContent = $contenido;
        $userQuery = $consulta;
    }
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
            width: 95%;
            max-width: 1400px;
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
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            min-height: 120px;
            resize: vertical;
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
        .loading-indicator {
            display: none;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            color: #0d6efd;
            font-weight: 600;
        }
        .loading-indicator.visible {
            display: inline-flex;
        }
        .loading-indicator .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid #cfe2ff;
            border-top-color: #0d6efd;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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
        .history {
            margin-top: 20px;
            background: #fff;
            padding: 15px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .history-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .history-item {
            border-bottom: 1px solid #e2e2e2;
            padding: 12px 0;
        }
        .history-item:last-child {
            border-bottom: none;
        }
        .history-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .history-header h3 {
            margin: 0 0 6px 0;
            font-size: 16px;
        }
        .history-header small {
            display: block;
            color: #666;
            margin-bottom: 0;
        }
        .history-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .history-actions form {
            display: inline;
        }
        .view-button {
            background: #17a2b8;
        }
        .view-button:hover {
            background: #138496;
        }
        .delete-button {
            background: #dc3545;
        }
        .delete-button:hover {
            background: #c82333;
        }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: stretch;
            justify-content: stretch;
            padding: 0;
            z-index: 1000;
        }
        .modal {
            background: #fff;
            padding: 20px;
            border-radius: 0;
            width: 100vw;
            height: 100vh;
            max-width: none;
            max-height: none;
            overflow: auto;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }
        .modal h3 {
            margin-top: 0;
        }
        .modal-close {
            background: #6c757d;
        }
        .modal-close:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Consulta IA GoldMind</h1>
        <?php if ($storageIssue !== null): ?>
            <div class="agent-error">
                <h2>Problema con el almacenamiento</h2>
                <p><?php echo htmlspecialchars($storageIssue, ENT_QUOTES, 'UTF-8'); ?></p>
                <p style="margin-bottom: 0;">Revisa los permisos de escritura del servidor o crea manualmente los archivos "texto-guardado.txt" y "consultas-historial.txt" con permisos 664.</p>
            </div>
        <?php endif; ?>
        <form method="post" id="agent-form">
            <h2>Prompt de contexto</h2>
            <textarea id="contenido" name="contenido"><?php echo htmlspecialchars($savedContent, ENT_QUOTES, 'UTF-8'); ?></textarea>
            <h2>Consulta del usuario</h2>
            <textarea id="consulta" name="consulta" placeholder="Escribe la consulta para el agente..."><?php echo htmlspecialchars($userQuery, ENT_QUOTES, 'UTF-8'); ?></textarea>
            <div class="actions">
                <button type="submit" id="submit-button">Ejecutar</button>
            </div>
            <div id="loading-indicator" class="loading-indicator" aria-live="polite">
                <div class="spinner" aria-hidden="true"></div>
                <span>Procesando consulta...</span>
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

        <div class="history">
            <h2>Historial de consultas</h2>
            <?php if (!empty($history)): ?>
                <ul class="history-list">
                    <?php foreach ($history as $entry): ?>
                        <li class="history-item">
                            <div class="history-header">
                                <div>
                                    <h3><?php echo htmlspecialchars(substr($entry['consulta'], 0, 80) . (strlen($entry['consulta']) > 80 ? '…' : ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <small><?php echo isset($entry['timestamp']) ? date('d/m/Y H:i:s', $entry['timestamp']) : ''; ?></small>
                                </div>
                                <div class="history-actions">
                                    <button type="button" class="view-button" data-consulta="<?php echo htmlspecialchars($entry['consulta'], ENT_QUOTES, 'UTF-8'); ?>" data-respuesta="<?php echo htmlspecialchars($entry['respuesta'], ENT_QUOTES, 'UTF-8'); ?>">Ver detalle</button>
                                    <form method="post">
                                        <input type="hidden" name="delete_id" value="<?php echo htmlspecialchars($entry['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="delete-button">Eliminar</button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No hay consultas guardadas todavía.</p>
            <?php endif; ?>
        </div>
    </div>

    <div id="modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="modal">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                <h3 id="modal-title">Detalle de la consulta</h3>
                <button type="button" class="modal-close" id="modal-close">Cerrar</button>
            </div>
            <div>
                <h4>Consulta</h4>
                <div id="modal-consulta" class="markdown-body"></div>
            </div>
            <div style="margin-top: 12px;">
                <h4>Respuesta</h4>
                <div id="modal-respuesta" class="markdown-body"></div>
            </div>
        </div>
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

        var form = document.getElementById('agent-form');
        var submitButton = document.getElementById('submit-button');
        var loadingIndicator = document.getElementById('loading-indicator');

        if (form && submitButton && loadingIndicator) {
            form.addEventListener('submit', function() {
                loadingIndicator.classList.add('visible');
                submitButton.disabled = true;
                submitButton.textContent = 'Procesando...';
            });
        }

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

        function renderMarkdownElements() {
            var blocks = document.querySelectorAll('.markdown-content');
            blocks.forEach(function(block) {
                var content = block.getAttribute('data-content') || '';
                block.innerHTML = marked.parse(content);
            });
        }

        function openModal(consulta, respuesta) {
            var overlay = document.getElementById('modal-overlay');
            var consultaContainer = document.getElementById('modal-consulta');
            var respuestaContainer = document.getElementById('modal-respuesta');

            consultaContainer.innerHTML = marked.parse(consulta || '');
            respuestaContainer.innerHTML = marked.parse(respuesta || '');

            overlay.style.display = 'flex';
            overlay.setAttribute('aria-hidden', 'false');
        }

        function closeModal() {
            var overlay = document.getElementById('modal-overlay');
            overlay.style.display = 'none';
            overlay.setAttribute('aria-hidden', 'true');
        }

        renderMarkdownElements();

        var viewButtons = document.querySelectorAll('.view-button');
        viewButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                var consulta = button.getAttribute('data-consulta') || '';
                var respuesta = button.getAttribute('data-respuesta') || '';
                openModal(consulta, respuesta);
            });
        });

        var modalClose = document.getElementById('modal-close');
        if (modalClose) {
            modalClose.addEventListener('click', closeModal);
        }

        var modalOverlay = document.getElementById('modal-overlay');
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function(event) {
                if (event.target === modalOverlay) {
                    closeModal();
                }
            });
        }
    </script>
</body>
</html>
