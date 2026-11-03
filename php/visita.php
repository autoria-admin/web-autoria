<?php
// visita.php (se abre como /api/visita) — cuenta, de forma anónima, las visitas
// a las páginas de las charlas por código (?c=C01), para comparar unas con otras.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

solo_post();
$in = entrada_json();
$pagina = clean_str($in['pagina'] ?? '', 20);
if (!in_array($pagina, ['propuesta', 'diagnostico'], true)) json_out(['ok' => false, 'reason' => 'datos']);
if (rate_limit('visita', 300, 1500, 10000) !== null) json_out(['ok' => false, 'reason' => 'rate']);
json_out(['ok' => contar_visita(codigo_charla($in['charla'] ?? ''), $pagina)]);
