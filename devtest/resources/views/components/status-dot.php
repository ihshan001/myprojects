<?php $pos = $position ?? 0; $waiting = $status === 'waiting'; ?>
<span class="inline-flex items-center gap-1.5 text-xs font-medium
  <?= $waiting ? 'text-amber-700' : 'text-teal-700' ?>">
  <span class="h-1.5 w-1.5 rounded-full <?= $waiting ? 'bg-amber-500' : 'bg-teal-500' ?>"></span>
  <?= $waiting ? ($pos === 1 ? 'Next up' : '#' . ($pos - 1) . ' in line') : 'Your turn' ?>
</span>
