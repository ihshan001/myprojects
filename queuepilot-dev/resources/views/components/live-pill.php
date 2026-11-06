<?php $stale = $stale ?? false; ?>
<span id="live-status" class="<?= $stale ? 'stale-pill' : 'live-pill' ?>">
  <?php if ($stale): ?>
    <svg class="h-3 w-3"><use href="#i-wifi-off"/></svg>
    <span>Reconnecting…</span>
  <?php else: ?>
    <span class="pulse-dot"><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span>
    <span>Live</span>
  <?php endif; ?>
</span>
