<?php
/**
 * DEVTEST role switcher.
 *
 * Every role runs on its own port, and in a proxied preview each port is its
 * own hostname. Without this, the other four roles are effectively invisible:
 * you would have to know the sandbox host pattern and retype it by hand.
 *
 * Links are built by DevIdentity::siblingUrl(), which rewrites the port in the
 * host the browser actually used — never localhost.
 *
 * @var string $switcherStyle 'bar' (inline chips) or 'drawer' (stacked rows)
 */
$roster = App\Core\DevIdentity::roster();
$here = (int) ($devPort ?? 0);
$style = $switcherStyle ?? 'bar';
// Land on the role's own home page rather than a path it may not be allowed
// to see (a doctor hitting /admin would just get a 403).
$homeFor = static fn(array $r): string => (string) ($r['home'] ?? '/');
?>
<?php if ($style === 'pop'): ?>
  <div id="role-pop" class="role-pop" data-role-pop hidden>
    <div class="border-b border-hairline bg-amber-50 px-3 py-2">
      <p class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide text-amber-900">
        <span class="rounded bg-amber-500 px-1.5 py-0.5 text-[9px] font-black text-white">devtest</span>
        port <?= (int) ($devPort ?? 0) ?> &middot; no sign-in
      </p>
      <p class="mt-1 truncate text-[11px] text-amber-800"><?= e($devRole['email'] ?? '') ?></p>
    </div>
    <div class="p-1.5">
      <?php foreach ($roster as $r): $p = (int) ($r['port'] ?? 0); $on = $p === $here; ?>
        <a href="<?= e(App\Core\DevIdentity::siblingUrl($p, $homeFor($r))) ?>"
           class="flex items-center gap-2 rounded-lg px-2 py-2 text-[12px] transition
                  <?= $on ? 'bg-mint-100 font-semibold text-brand-700' : 'text-ink-700 hover:bg-slate-50' ?>"
           <?= $on ? 'aria-current="true"' : '' ?>>
          <span class="w-9 shrink-0 rounded px-1 py-0.5 text-center font-mono text-[10px] font-bold
                       <?= $on ? 'bg-brand-700 text-white' : 'bg-slate-100 text-ink-500' ?>"><?= $p ?></span>
          <span class="min-w-0 flex-1 truncate"><?= e($r['label'] ?? '') ?></span>
          <?php if ($on): ?><svg class="h-3.5 w-3.5 shrink-0"><use href="#i-check"/></svg><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php elseif ($style === 'drawer'): ?>
  <div class="shrink-0 border-t border-hairline p-2">
    <p class="nav-section-label px-1">Switch role</p>
    <div class="space-y-0.5">
      <?php foreach ($roster as $r): $p = (int) ($r['port'] ?? 0); $on = $p === $here; ?>
        <a href="<?= e(App\Core\DevIdentity::siblingUrl($p, $homeFor($r))) ?>"
           class="flex min-h-[44px] items-center gap-2 rounded-lg px-2 py-2 text-[13px] transition
                  <?= $on ? 'bg-mint-100 font-semibold text-brand-700' : 'text-ink-700 hover:bg-slate-50' ?>"
           <?= $on ? 'aria-current="true"' : '' ?>>
          <span class="w-9 shrink-0 rounded px-1 py-0.5 text-center font-mono text-[10px] font-bold
                       <?= $on ? 'bg-brand-700 text-white' : 'bg-slate-100 text-ink-500' ?>"><?= $p ?></span>
          <span class="min-w-0 flex-1 truncate"><?= e($r['label'] ?? '') ?></span>
          <?php if ($on): ?>
            <svg class="h-3.5 w-3.5 shrink-0"><use href="#i-check"/></svg>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
  <div class="flex flex-wrap items-center gap-1.5">
    <?php foreach ($roster as $r): $p = (int) ($r['port'] ?? 0); $on = $p === $here; ?>
      <a href="<?= e(App\Core\DevIdentity::siblingUrl($p, $homeFor($r))) ?>"
         class="inline-flex min-h-[28px] items-center gap-1 rounded-md border px-2 py-1 text-[11px] font-semibold transition
                <?= $on
                    ? 'border-amber-500 bg-amber-500 text-white'
                    : 'border-amber-300 bg-white/70 text-amber-900 hover:border-amber-500 hover:bg-white' ?>"
         title="<?= e(($r['label'] ?? '') . ' · ' . ($r['email'] ?? '')) ?>"
         <?= $on ? 'aria-current="true"' : '' ?>>
        <span class="font-mono <?= $on ? 'text-white/80' : 'text-amber-500' ?>"><?= $p ?></span>
        <?= e($r['short'] ?? $r['label'] ?? '') ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
