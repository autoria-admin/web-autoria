<?php
// charla-diagnostico.php (se abre como /api/charla-diagnostico) — guarda la
// solicitud de diagnóstico que dejan los asistentes al final de una charla
// (charla-diagnostico.html), la copia en la hoja de Google de las charlas y
// avisa al equipo por email. A la persona no se le envía nada automático.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

solo_post();
$in = entrada_json();

// Campo trampa para robots: si viene relleno, se responde ok sin guardar.
if (clean_str($in['web'] ?? '', 200) !== '') json_out(['ok' => true]);

$email = clean_str($in['email'] ?? '', 160);
$red = clean_str($in['red'] ?? '', 20);
$perfil = clean_str($in['perfil'] ?? '', 200);
if (!in_array($red, ['LinkedIn', 'Instagram', 'TikTok'], true)) json_out(['ok' => false, 'reason' => 'datos', 'campo' => 'red']);
if ($perfil === '') json_out(['ok' => false, 'reason' => 'datos', 'campo' => 'perfil']);
if (!es_email($email)) json_out(['ok' => false, 'reason' => 'datos', 'campo' => 'email']);
if (empty($in['consent'])) json_out(['ok' => false, 'reason' => 'consent', 'campo' => 'consent']);

// Un aula entera puede salir a internet con la misma IP: límite por IP generoso.
$limit = rate_limit('charla-diagnostico', 300, 1500, 5000);
if ($limit !== null) json_out(['ok' => false, 'reason' => 'rate_' . $limit]);

// El id lo genera el móvil al cargar el formulario: si un reintento, un doble toque o
// una recarga lo mandan otra vez, no se guarda, ni se copia, ni se avisa de nuevo.
$id = preg_replace('/[^a-zA-Z0-9-]/', '', clean_str($in['id'] ?? '', 40));
$id = 'cd-' . ($id !== '' ? $id : nuevo_id());
if (registro_existe($id)) json_out(['ok' => true]);

$fecha = fecha_envio($in['fecha'] ?? '');   // la del envío, con su zona horaria
$row = [
  'id' => $id, 'fecha' => $fecha, 'recibida' => ahora(), 'tipo' => 'charla-diagnostico',
  'charla' => codigo_charla($in['charla'] ?? ''), 'email' => $email, 'nombre' => clean_str($in['nombre'] ?? '', 120),
  'red' => $red, 'perfil' => $perfil, 'propuesta' => clean_str($in['propuesta'] ?? '', 400),
  'consentimiento' => 'Sí (' . $fecha . '): usar estos datos para enviarle el diagnóstico y contactarle',
];
$hoja = [
  'tipo' => 'diagnostico', 'id' => $id, 'fecha' => $fecha, 'charla' => $row['charla'], 'nombre' => $row['nombre'],
  'correo' => $email, 'red' => $red, 'perfil' => $perfil, 'propuesta' => $row['propuesta'], 'consentimiento' => true,
];
$asunto = 'Diagnóstico de charla — ' . ($row['charla'] ?: 'sin código') . ' — ' . $email;

// Primero se guarda en el servidor y se contesta; la hoja y el aviso van después.
if (store_record($row)) {
  json_out_y_seguir(['ok' => true]);
  hoja_registrar($hoja);
  hoja_contadores($row['charla']);
  notify_team($asunto, $row);
  exit;
}
// Si no se pudo guardar en el servidor, al menos que llegue a la hoja o al correo.
$okHoja = hoja_post($hoja) === '';
json_out(['ok' => notify_team($asunto, $row) || $okHoja]);
