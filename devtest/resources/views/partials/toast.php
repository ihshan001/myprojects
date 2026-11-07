<?php
/**
 * Server-set flash messages, handed to the existing toast helper.
 *
 * Controllers across the app call flash('error'|'success', ...) — 176 places —
 * but nothing ever read the bag, so every one of those messages was silently
 * discarded. A refusal that explains itself ("that letter is already used by
 * Room 2") is the difference between a guard the owner understands and a button
 * that appears broken.
 *
 * Passed as a data attribute rather than an inline <script>: the CSP is
 * script-src 'self', so an inline block would be blocked.
 */
$qpNotice = notice();
$qpPayload = $qpNotice !== null && !empty($qpNotice['message'])
    ? json_encode(['type' => (string) ($qpNotice['type'] ?? 'success'), 'message' => (string) $qpNotice['message']], JSON_HEX_APOS | JSON_HEX_QUOT)
    : null;
?>
<div id="toast-root"
     class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-[min(92vw,22rem)] flex-col gap-2 no-print"
     <?php if ($qpPayload !== null): ?>data-notice="<?= e($qpPayload) ?>"<?php endif; ?>
     aria-live="polite" aria-atomic="false"></div>
