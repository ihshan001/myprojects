<?php
/**
 * The systems a clinic can connect to.
 *
 * Two facts are shown for every provider, because they are different
 * problems with different fixes:
 *
 *   available — this deployment has the means to use it (credentials in
 *               the environment). Not available is a platform question.
 *   connected — this clinic has switched it on. Not connected is one click.
 *
 * Collapsing them into one "enabled" flag is how a clinic ends up filing a
 * support ticket about an integration that simply is not on this server.
 *
 * Credentials are never asked for here and never stored in the database —
 * only non-secret settings are, and those are whitelisted per provider.
 *
 * @var array $integrations
 */
?>

<div class="card card-pad mb-4">
  <h1 class="text-2xl font-bold tracking-tight text-slate-900">Integrations</h1>
  <p class="mt-1 max-w-prose text-sm text-slate-500">
    Systems QueuePilot can send to. Connecting one here switches it on for your clinic — the credentials
    themselves are held by your deployment, never in this page.
  </p>
</div>

<div class="grid gap-4 md:grid-cols-2">
  <?php foreach ($integrations as $i): ?>
    <div class="card card-pad flex flex-col">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm font-semibold text-slate-900"><?= e($i['name']) ?></p>
          <p class="mt-0.5 text-xs text-slate-500"><?= e($i['kind']) ?></p>
        </div>
        <?php if ($i['connected']): ?>
          <span class="badge-emerald">Connected</span>
        <?php elseif (!$i['available']): ?>
          <span class="badge-slate">Not on this server</span>
        <?php else: ?>
          <span class="badge-slate">Off</span>
        <?php endif; ?>
      </div>

      <p class="mt-3 flex-1 text-sm text-slate-600"><?= e($i['blurb']) ?></p>

      <?php if (!$i['available']): ?>
        <p class="mt-3 text-xs text-slate-500">
          This deployment has no credentials configured for it, so it cannot be switched on here.
        </p>
      <?php else: ?>
        <?php $fields = (new \App\Services\IntegrationService())->configFields((string) $i['provider']); ?>

        <?php if ($i['connected']): ?>
          <?php if ($fields !== [] && $i['config'] !== []): ?>
            <dl class="mt-3 space-y-1 border-t border-slate-100 pt-3">
              <?php foreach ($fields as $key => $definition): ?>
                <?php if (isset($i['config'][$key])): ?>
                  <div class="flex gap-2 text-xs">
                    <dt class="w-28 shrink-0 text-slate-500"><?= e($definition['label']) ?></dt>
                    <dd class="min-w-0 break-all font-mono text-slate-700"><?= e((string) $i['config'][$key]) ?></dd>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </dl>
          <?php endif; ?>

          <form method="post" action="/settings/integrations/disconnect" class="mt-4"
                data-confirm-submit="Switch <?= e($i['name']) ?> off? Its settings are kept." data-confirm-danger>
            <?= csrf_field() ?>
            <input type="hidden" name="provider" value="<?= e((string) $i['provider']) ?>">
            <button class="btn-secondary w-full justify-center">Switch off</button>
          </form>
        <?php else: ?>
          <details class="mt-4">
            <summary class="btn-primary w-full cursor-pointer list-none justify-center">Connect</summary>
            <form method="post" action="/settings/integrations/connect" class="mt-3 space-y-3">
              <?= csrf_field() ?>
              <input type="hidden" name="provider" value="<?= e((string) $i['provider']) ?>">

              <?php if ($fields === []): ?>
                <p class="text-xs text-slate-500">
                  Nothing to configure — connecting switches it on.
                </p>
              <?php else: ?>
                <?php foreach ($fields as $key => $definition): ?>
                  <div>
                    <label class="label" for="cfg-<?= e((string) $i['provider']) ?>-<?= e($key) ?>">
                      <?= e($definition['label']) ?>
                    </label>
                    <input class="input" id="cfg-<?= e((string) $i['provider']) ?>-<?= e($key) ?>"
                           name="config[<?= e($key) ?>]" maxlength="<?= (int) $definition['max'] ?>"
                           value="<?= e((string) ($i['config'][$key] ?? '')) ?>">
                  </div>
                <?php endforeach; ?>
                <p class="text-[11px] text-slate-400">
                  Settings only. Never enter a password or API key here — those belong with your deployment.
                </p>
              <?php endif; ?>

              <button class="btn-primary w-full justify-center">Connect <?= e($i['name']) ?></button>
            </form>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
