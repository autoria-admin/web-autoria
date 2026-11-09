<?php
// charla.php (se abre como /charla/propuesta y /charla/diagnostico) — sirve las
// landings de las charlas con los estilos de la web dentro de la propia página.
// Así el móvil no pide styles.css aparte: con la red muy lenta de un aula, esa
// segunda petición retrasaba la aparición del formulario más de 2 segundos.
// La fuente sigue siendo styles.css; si algo falla, la página sale tal cual,
// con su enlace normal a styles.css.
$pagina = in_array($_GET['p'] ?? '', ['propuesta', 'diagnostico'], true) ? $_GET['p'] : '';
$raiz = dirname(__DIR__);
$html = $pagina !== '' ? @file_get_contents("$raiz/charla-$pagina.html") : false;
if ($html === false) { http_response_code(404); exit; }

$css = @file_get_contents("$raiz/styles.css");
if ($css && preg_match('#<link rel="stylesheet" href="/styles\.css\?v=[^"]*">#', $html, $m)) {
  // Las tipografías, con ruta absoluta: desde /charla/… la ruta relativa no las encontraría.
  $css = str_replace('url("assets/', 'url("/assets/', $css);
  $html = str_replace($m[0], "<style>\n" . $css . "</style>", $html);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo $html;
