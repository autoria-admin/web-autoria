<?php
// visita.php (se abre como /api/visita) — cuenta, de forma anónima, las visitas
// a las páginas de las charlas por código (?c=C01), y los clics en los botones de
// la comunidad de WhatsApp y de la agenda, para comparar unas charlas con otras.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

solo_post();
$in = entrada_json();
$pagina = clean_str($in['pagina'] ?? '', 20);
if (!in_array($pagina, ['propuesta', 'diagnostico', 'registro', 'whatsapp', 'agenda'], true)) json_out(['ok' => false, 'reason' => 'datos']);
if (rate_limit('visita', 300, 1500, 10000) !== null) json_out(['ok' => false, 'reason' => 'rate']);
$charla = codigo_charla($in['charla'] ?? '');
$ok = contar_visita($charla, $pagina);
if ($pagina !== 'whatsapp') json_out(['ok' => $ok]);

// Las pulsaciones en «Unirme a la comunidad» también ponen al día la pestaña
// Contadores de la hoja (como mucho una vez por minuto), sin hacer esperar.
json_out_y_seguir(['ok' => $ok]);
hoja_contadores($charla);
exit;
