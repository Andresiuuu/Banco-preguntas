<?php
require_once __DIR__ . '/../config.php';
arrancar_sesion();
$titulo = $titulo ?? APP_NOMBRE;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e(APP_NOMBRE) ?></title>
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">
    <span class="brand-mark">400</span>
    <span class="brand-text"><?= e(APP_NOMBRE) ?><small><?= e(APP_SUBTITULO) ?></small></span>
  </a>
  <nav class="nav">
    <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'activo' : '' ?>">Estudiar</a>
    <a href="resultados.php" class="<?= basename($_SERVER['PHP_SELF']) === 'resultados.php' ? 'activo' : '' ?>">Resultados</a>
    <a href="estadisticas.php" class="<?= basename($_SERVER['PHP_SELF']) === 'estadisticas.php' ? 'activo' : '' ?>">Estadísticas</a>
    <a href="ranking.php" class="<?= basename($_SERVER['PHP_SELF']) === 'ranking.php' ? 'activo' : '' ?>">Ranking</a>
  </nav>
</header>
<main class="contenedor">
