<?php
// router-php.php — servidor de pruebas en local que imita a Hostinger
// (las mismas direcciones y bloqueos que el .htaccess).
// Uso: php -S localhost:8089 -t . tools/router-php.php
// Los datos de prueba se guardan en «Web Autoría - privado/hostinger-pruebas».
$root = dirname(__DIR__);
if (!getenv('AUTORIA_PRIVADO')) putenv('AUTORIA_PRIVADO=' . dirname($root) . '/Web Autoría - privado/hostinger-pruebas');

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$rutas = [
  '#^/api/(asistente|lector-perfil|diagnostico|contacto|propuesta|charla-diagnostico|charla-registro|visita)/?$#' => '/php/$1.php',
  '#^/panel/?$#' => '/php/panel.php',
  '#^/instalar/?$#' => '/php/instalar.php',
];
if (preg_match('#^/charla/(propuesta|diagnostico|registro)/?$#', $uri, $m)) { $_GET['p'] = $m[1]; require $root . '/php/charla.php'; return true; }
foreach ($rutas as $re => $destino) {
  if (preg_match($re, $uri)) { require $root . preg_replace($re, $destino, $uri); return true; }
}
if (preg_match('#^/(php|api|tools|\.git|node_modules)(/|$)|/\.|\.md$|^/(package|package-lock|vercel)\.json$#', $uri)) {
  http_response_code(403); echo 'Prohibido'; return true;
}
return false;   // archivo estático: lo sirve el propio servidor de PHP
