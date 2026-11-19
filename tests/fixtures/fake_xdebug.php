<?php

/**
 * Faux Xdebug (DBGp) pour les tests : se connecte à l'IDE, annonce un script,
 * répond aux commandes de contrôle par des arrêts scriptés.
 */

declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 2);

if ($client === false) {
    fwrite(STDERR, "fake_xdebug: connexion impossible ({$error})\n");
    exit(1);
}

stream_set_timeout($client, 2);

function sendPacket(mixed $client, string $xml): void
{
    fwrite($client, $xml . "\0");
}

$packetBuffer = '';

function readPacket(mixed $client): ?string
{
    global $packetBuffer;

    while (true) {
        $position = strpos($packetBuffer, "\0");
        if ($position !== false) {
            $packet = substr($packetBuffer, 0, $position);
            $packetBuffer = substr($packetBuffer, $position + 1);
            return $packet;
        }

        $chunk = fread($client, 8192);

        if ($chunk === false) {
            return null;
        }

        if ($chunk === '') {
            if (feof($client) || stream_get_meta_data($client)['timed_out']) {
                return null;
            }
            continue;
        }

        $packetBuffer .= $chunk;
    }
}

function breakResponse(string $command, int $line, ?string $transaction): string
{
    $transactionAttribute = $transaction === null ? '' : ' transaction_id="' . $transaction . '"';

    return '<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug"'
        . ' command="' . $command . '"' . $transactionAttribute
        . ' status="break" reason="ok">'
        . '<xdebug:message filename="file:///tmp/demo.php" lineno="' . $line . '"/>'
        . '</response>';
}

sendPacket(
    $client,
    '<init fileuri="file:///tmp/demo.php" language="PHP" protocol_version="1.0"'
        . ' appid="fake" idekey="cyonima"/>',
);

while (($packet = readPacket($client)) !== null) {
    if (!preg_match('/^<([a-z_]+)/', $packet, $matches)) {
        continue;
    }

    $command = $matches[1];
    $transaction = preg_match('/-i="(\d+)"/', $packet, $id) ? $id[1] : null;
    $transactionAttribute = $transaction === null ? '' : ' transaction_id="' . $transaction . '"';

    switch ($command) {
        case 'run':
            sendPacket($client, breakResponse('run', 4, $transaction));
            break;

        case 'step_into':
            sendPacket($client, breakResponse('step_into', 5, $transaction));
            break;

        case 'step_over':
            sendPacket($client, breakResponse('step_over', 6, $transaction));
            break;

        case 'breakpoint_set':
            sendPacket(
                $client,
                '<response xmlns="urn:debugger_protocol_v1"'
                    . ' command="breakpoint_set"' . $transactionAttribute . ' id="1"/>',
            );
            break;

        case 'stop':
            sendPacket(
                $client,
                '<response xmlns="urn:debugger_protocol_v1" command="stop"'
                    . $transactionAttribute . ' status="stopped" reason="ok"/>',
            );
            exit(0);

        case 'detach':
            sendPacket(
                $client,
                '<response xmlns="urn:debugger_protocol_v1" command="detach"'
                    . $transactionAttribute . ' status="stopped" reason="ok"/>',
            );
            exit(0);
    }
}
