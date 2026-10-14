<?php
function limpiar(string $texto): string {
    return trim(preg_replace('/[\r\n]+/', ' ', $texto));
}

function enviarCorreoCreacion(array $r): void {
    $host = getenv('SMTP_HOST');
    $puerto = (int) getenv('SMTP_PORT');
    $de = getenv('MAIL_FROM') ?: 'reservas@practica.local';

    $asunto = 'Reservación creada: ' . limpiar($r['espacio']);
    $cuerpo = implode("\r\n", [
        'Hola ' . limpiar($r['solicitante']) . ',',
        '',
        'Tu reservación fue registrada con los siguientes datos:',
        '',
        'Folio: ' . $r['id'],
        'Espacio: ' . limpiar($r['espacio']),
        'Fecha: ' . $r['fecha'],
        'Horario: ' . substr($r['hora_inicio'], 0, 5) . ' a ' . substr($r['hora_fin'], 0, 5),
        'Estado: ' . $r['estado'],
        '',
        'Este es un correo de prueba capturado por Mailpit.',
    ]);

    $socket = @fsockopen($host, $puerto, $errno, $errstr, 3);
    if (!$socket) {
        throw new RuntimeException("SMTP no disponible: $errstr");
    }
    stream_set_timeout($socket, 3);

    $leer = function () use ($socket): string {
        $respuesta = '';
        while (($linea = fgets($socket, 512)) !== false) {
            $respuesta .= $linea;
            if (isset($linea[3]) && $linea[3] === ' ') {
                break;
            }
        }
        return $respuesta;
    };
    $enviar = function (string $comando) use ($socket, $leer): string {
        fwrite($socket, $comando . "\r\n");
        $respuesta = $leer();
        if ($respuesta === '' || $respuesta[0] === '4' || $respuesta[0] === '5') {
            throw new RuntimeException('Error SMTP: ' . trim($respuesta));
        }
        return $respuesta;
    };

    try {
        $leer();
        $enviar('EHLO backend');
        $enviar("MAIL FROM:<$de>");
        $enviar('RCPT TO:<' . $r['correo'] . '>');
        $enviar('DATA');
        $mensaje = implode("\r\n", [
            "From: <$de>",
            'To: <' . $r['correo'] . '>',
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $cuerpo,
            '.',
        ]);
        $enviar($mensaje);
        $enviar('QUIT');
    } finally {
        fclose($socket);
    }
}