<?php
/** @var string $name @var string $class */
$name = $name ?? 'info';
$class = $class ?? 'h-5 w-5';
?>
<svg class="<?= e($class) ?>" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-<?= e($name) ?>"/></svg>
