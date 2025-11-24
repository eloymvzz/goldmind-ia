<?php
// Simple text persistence using PHP 5.x
$filePath = __DIR__ . '/texto-guardado.txt';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contenido = isset($_POST['contenido']) ? $_POST['contenido'] : '';
    file_put_contents($filePath, $contenido);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$savedContent = '';
if (file_exists($filePath)) {
    $savedContent = file_get_contents($filePath);
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
