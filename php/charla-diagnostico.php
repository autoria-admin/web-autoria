<?php
// charla-diagnostico.php (se abre como /api/charla-diagnostico) — guarda la
// solicitud de diagnóstico que dejan los asistentes al final de una charla
// (charla-diagnostico.html) y avisa al equipo por email de cada una.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

solo_post();
$in = entrada_json();

$email = clean_str($in['email'] ?? '', 160);
$red = clean_str($in['red'] ?? '', 20);
$perfil = clean_str($in['perfil'] ?? '', 200);
if (!es_email($email) || !in_array($red, ['LinkedIn', 'Instagram', 'TikTok'], true) || $perfil === '') json_out(['ok' => false, 'reason' => 'datos']);
if (empty($in['consent'])) json_out(['ok' => false, 'reason' => 'consent']);

// Un aula entera puede salir a internet con la misma IP: límite por IP generoso.
$limit = rate_limit('charla-diagnostico', 300, 1500, 5000);
if ($limit !== null) json_out(['ok' => false, 'reason' => 'rate_' . $limit]);

// El id lo pone el móvil: si un reintento llega dos veces, no se guarda ni se avisa otra vez.
$id = preg_replace('/[^a-zA-Z0-9-]/', '', clean_str($in['id'] ?? '', 40));
$id = 'cd-' . ($id !== '' ? $id : nuevo_id());
if (registro_existe($id)) json_out(['ok' => true]);

// Fecha en que pulsó el botón (puede llegar más tarde si no había conexión).
$fecha = ahora();
$t = strtotime(clean_str($in['fecha'] ?? '', 40));
if ($t !== false && $t <= time() + 300 && $t > time() - 30 * 86400) $fecha = gmdate('Y-m-d\TH:i:s.000\Z', $t);

$row = [
  'id' => $id, 'fecha' => $fecha, 'recibida' => ahora(), 'tipo' => 'charla-diagnostico',
  'charla' => codigo_charla($in['charla'] ?? ''), 'email' => $email, 'nombre' => clean_str($in['nombre'] ?? '', 120),
  'red' => $red, 'perfil' => $perfil, 'propuesta' => clean_str($in['propuesta'] ?? '', 400),
  'consentimiento' => 'Sí (' . $fecha . '): usar estos datos para enviarle el diagnóstico y contactarle',
];
$asunto = 'Diagnóstico de charla — ' . ($row['charla'] ?: 'sin código') . ' — ' . $email;
if (store_record($row)) { json_out_y_seguir(['ok' => true]); notify_team($asunto, $row); exit; }
json_out(['ok' => notify_team($asunto, $row)]);   // si no se pudo guardar, al menos llega el aviso
