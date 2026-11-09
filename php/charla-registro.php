<?php
// charla-registro.php (se abre como /api/charla-registro) — guarda la
// inscripción a una charla que se hace desde el QR del flyer
// (charla-registro.html) y avisa al equipo por email. A la persona no se le
// envía nada automático. No se copia en la hoja de Google: su Apps Script
// solo acepta los formatos diagnostico, propuesta y contadores.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

solo_post();
$in = entrada_json();

// Campo trampa para robots: si viene relleno, se responde ok sin guardar.
if (clean_str($in['web'] ?? '', 200) !== '') json_out(['ok' => true]);

$nombre = preg_replace('/\s+/u', ' ', clean_str($in['nombre'] ?? '', 120));
$email = clean_str($in['email'] ?? '', 160);
$asignatura = preg_replace('/\s+/u', ' ', clean_str($in['asignatura'] ?? '', 160));
$trabajo = clean_str($in['trabajo'] ?? '', 5);
if (!preg_match('/\S+\s+\S+/u', $nombre)) json_out(['ok' => false, 'reason' => 'datos', 'campo' => 'nombre']);
if (!es_email($email)) json_out(['ok' => false, 'reason' => 'datos', 'campo' => 'email']);
if ($asignatura === '') json_out(['ok' => false, 'reason' => 'datos', 'campo' => 'asignatura']);
if (!in_array($trabajo, ['Sí', 'No'], true)) json_out(['ok' => false, 'reason' => 'datos', 'campo' => 'trabajo']);
if (empty($in['consent'])) json_out(['ok' => false, 'reason' => 'consent', 'campo' => 'consent']);

// Un aula entera puede salir a internet con la misma IP: límite por IP generoso.
$limit = rate_limit('charla-registro', 300, 1500, 5000);
if ($limit !== null) json_out(['ok' => false, 'reason' => 'rate_' . $limit]);

// El id lo genera el móvil al cargar el formulario: un reintento no se guarda ni se avisa dos veces.
$id = preg_replace('/[^a-zA-Z0-9-]/', '', clean_str($in['id'] ?? '', 40));
$id = 'cr-' . ($id !== '' ? $id : nuevo_id());
if (registro_existe($id)) json_out(['ok' => true]);

$fecha = fecha_envio($in['fecha'] ?? '');   // la del envío, con su zona horaria
$row = [
  'id' => $id, 'fecha' => $fecha, 'recibida' => ahora(), 'tipo' => 'charla-registro',
  'charla' => codigo_charla($in['charla'] ?? ''), 'nombre' => $nombre, 'email' => $email,
  'asignatura' => $asignatura, 'negocio_o_trabajo' => $trabajo,
  'consentimiento' => 'Sí (' . $fecha . '): usar estos datos para gestionar su inscripción en la charla y contactarle',
];
$asunto = 'Inscripción a charla — ' . ($row['charla'] ?: 'sin código') . ' — ' . $email;

if (store_record($row)) {
  json_out_y_seguir(['ok' => true]);
  notify_team($asunto, $row);
  exit;
}
// Si no se pudo guardar en el servidor, al menos que llegue el aviso por correo.
json_out(['ok' => notify_team($asunto, $row)]);
