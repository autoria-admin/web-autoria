<?php
// propuesta.php (se abre como /api/propuesta) — guarda, de forma anónima, la
// propuesta de valor que escriben los asistentes a una charla
// (charla-propuesta.html). No pide nombre ni correo y no avisa por email.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

solo_post();
$in = entrada_json();

$para = clean_str($in['para'] ?? '', 90);
$aporto = clean_str($in['aporto'] ?? '', 90);
$gracias = clean_str($in['gracias_a'] ?? '', 90);
if ($para === '' || $aporto === '' || $gracias === '') json_out(['ok' => false, 'reason' => 'datos']);

// Un aula entera puede salir a internet con la misma IP: límite por IP generoso.
$limit = rate_limit('propuesta', 300, 1500, 5000);
if ($limit !== null) json_out(['ok' => false, 'reason' => 'rate_' . $limit]);

// El id lo pone el móvil, para que un reintento no cuente dos veces en el panel.
$id = preg_replace('/[^a-zA-Z0-9-]/', '', clean_str($in['id'] ?? '', 40));
$charla = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', clean_str($in['charla'] ?? '', 20)));

// Fecha en que pulsó «Listo» (puede llegar más tarde si no había conexión).
$fecha = ahora();
$t = strtotime(clean_str($in['fecha'] ?? '', 40));
if ($t !== false && $t <= time() + 300 && $t > time() - 30 * 86400) $fecha = gmdate('Y-m-d\TH:i:s.000\Z', $t);

$row = [
  'id' => 'p-' . ($id !== '' ? $id : nuevo_id()), 'fecha' => $fecha, 'recibida' => ahora(), 'tipo' => 'propuesta',
  'charla' => $charla, 'para' => $para, 'aporto' => $aporto, 'gracias_a' => $gracias,
];
json_out(['ok' => store_record($row)]);
