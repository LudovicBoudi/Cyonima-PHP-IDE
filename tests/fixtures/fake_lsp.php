<?php

/**
 * Faux serveur LSP pour les tests : implémente le strict nécessaire.
 * Framing Content-Length, initialize, didOpen (diagnostic), shutdown.
 */

declare(strict_types=1);

/** @return array<string, mixed>|null */
function readMessage(): ?array
{
    $header = '';
    while (($line = fgets(STDIN)) !== false) {
        $header .= $line;
        if (trim($line) === '') {
            break;
        }
    }

    if (!preg_match('/Content-Length:\s*(\d+)/i', $header, $matches)) {
        return null;
    }

    $length = (int) $matches[1];
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread(STDIN, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $body .= $chunk;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/** @param array<string, mixed> $message */
function sendMessage(array $message): void
{
    $json = json_encode($message, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json);
    fflush(STDOUT);
}

while (($message = readMessage()) !== null) {
    $method = (string) ($message['method'] ?? '');
    $id = $message['id'] ?? null;

    switch ($method) {
        case 'initialize':
            sendMessage([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'capabilities' => [
                        'textDocumentSync' => 1,
                        'completionProvider' => new stdClass(),
                    ],
                ],
            ]);
            break;

        case 'initialized':
            break;

        case 'textDocument/didOpen':
            sendMessage([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/publishDiagnostics',
                'params' => [
                    'uri' => $message['params']['textDocument']['uri'] ?? '',
                    'diagnostics' => [
                        [
                            'range' => [
                                'start' => ['line' => 0, 'character' => 0],
                                'end' => ['line' => 0, 'character' => 1],
                            ],
                            'severity' => 1,
                            'message' => 'diagnostic factice',
                        ],
                    ],
                ],
            ]);
            break;

        case 'shutdown':
            sendMessage(['jsonrpc' => '2.0', 'id' => $id, 'result' => null]);
            break;

        case 'exit':
            exit(0);
    }
}
