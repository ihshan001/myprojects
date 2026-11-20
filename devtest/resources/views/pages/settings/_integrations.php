<?php
/**
 * Integrations tab — what this clinic has connected to.
 *
 * Each row carries two separate facts and shows both, because they have
 * different fixes:
 *
 *   connected — this clinic has switched it on. Fix: press Disconnect.
 *   available — this SERVER has the credentials for it. Fix: the operator
 *               sets an environment variable.
 *
 * Collapsing those into one "Connected" badge would send a clinic owner to
 * hunt through their own settings for a problem that is not there.
 *
 * @var array<int,array<string,mixed>> $integrations
 * @var int $connectedCount
 * @var bool $canManage
 */
?>
<div class="card overflow-hidden">
  <div class="section-head">
    <p class="section-title">Integrations</p>
    <p class="text-[11px] text-ink-400"><?= (int) $connectedCount ?> connected</p>
  </div>

  <ul class="divide-y divide-slate-100">
    <?php foreach ($integrations as $i): ?>
      <li class="px-4 py-3.5" data-integration="<?= e((string) $i['provider']) ?>">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <p class="text-[13px] font-semibold text-ink-900"><?= e((string) $i['name']) ?></p>
              <?php if ($i['connected']): ?>
                <span class="badge-teal">Connected</span>
              <?php elseif (!$i['available']): ?>
                <span class="badge-slate">Not configured on this server</span>
              <?php else: ?>
                <span class="badge-slate">Off</span>
              <?php endif; ?>
              <span class="text-[10px] font-semibold uppercase tracking-wider text-ink-400"><?= e((string) $i['kind']) ?></span>
            </div>
            <p class="mt-0.5 text-[11px] leading-relaxed text-ink-500"><?= e((string) $i['blurb']) ?></p>
            <?php if ($i['connected'] && $i['connected_at']): ?>
              <p class="mt-1 text-[10px] text-ink-400">Connected <?= e(ago((string) $i['connected_at'])) ?></p>
            <?php endif; ?>
          </div>

          <?php if ($canManage): ?>
            <div class="shrink-0 text-right">
              <?php if ($i['connected']): ?>
                <form method="post" action="/settings/integrations/disconnect">
                  <?= csrf_field() ?>
                  <input type="hidden" name="provider" value="<?= e((string) $i['provider']) ?>">
                  <button class="btn-secondary !py-1.5 text-xs">Disconnect</button>
                </form>
              <?php else: ?>
                <form method="post" action="/settings/integrations/connect" class="mt-1 flex flex-wrap items-end justify-end gap-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="provider" value="<?= e((string) $i['provider']) ?>">
                  <?php foreach ($i['config'] === [] ? [] : (new \App\Services\IntegrationService())->configFields((string) $i['provider']) as $field => $definition): ?>
                    <span class="text-left">
                      <span class="label"><?= e((string) $definition['label']) ?></span>
                      <input class="input !w-40 !py-1.5 text-xs" name="config[<?= e((string) $field) ?>]"
                             value="<?= e((string) ($i['config'][$field] ?? '')) ?>">
                    </span>
                  <?php endforeach; ?>
                  <button class="btn-primary !py-1.5 text-xs" <?= $i['available'] ? '' : 'disabled title="Not configured on this server"' ?>>
                    Connect
                  </button>
                </form>
                <?php if (!$i['available']): ?>
                  <p class="mt-1 max-w-[15rem] text-[10px] leading-snug text-ink-400">
                    This server has no credentials for it, so connecting would do nothing.
                  </p>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>

  <p class="border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-[11px] leading-relaxed text-ink-500">
    Credentials are never stored in the database. A row here records that a
    connection exists and is switched on; the secret itself stays in the
    server's environment.
  </p>
</div>
