<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Token slip for an 80 mm thermal printer.
 *
 * Two outputs, because clinics own two kinds of hardware:
 *
 *   * **Printable HTML** (`html()`) — 80 mm page, black on white, no colour,
 *     no background, sized so the browser's own print dialog produces a slip
 *     on a cheap USB thermal printer. This is what most clinics will use: no
 *     driver work, works from a tablet.
 *   * **ESC/POS** (`escpos()`) — the raw byte stream a receipt printer
 *     understands over a serial or network connection, for the sites that
 *     wire one up properly.
 *
 * The slip deliberately carries no clinical information: token, queue,
 * doctor, room, position and the tracking link. A slip left on a counter
 * tells a stranger nothing about anyone's health.
 */
final class ThermalSlip
{
    private const WIDTH = 42; // characters at 80 mm / font A

    /**
     * ESC/POS byte stream.
     *
     * Control codes used: ESC @ (initialise), ESC a 1 (centre), ESC E 1
     * (bold on), GS ! (size), ESC d 3 (feed 3 lines), GS V 66 0 (partial cut
     * with feed). All are supported by every Epson-compatible unit; a printer
     * that does not cut simply ignores the cut command by design.
     *
     * @param array<string, mixed> $data
     */
    public static function escpos(array $data): string
    {
        $esc = "\x1b";
        $gs = "\x1d";

        $out = $esc . '@';                 // initialise
        $out .= $esc . 'a' . "\x01";       // centre
        $out .= $esc . 'E' . "\x01";       // bold
        $out .= $gs . '!' . "\x11";        // double width + height
        $out .= self::clean((string) ($data['clinic'] ?? 'Clinic')) . "\n";
        $out .= $gs . '!' . "\x00";        // normal size
        $out .= $esc . 'E' . "\x00";       // bold off
        $out .= self::clean((string) ($data['queue'] ?? '')) . "\n";
        $out .= str_repeat('-', self::WIDTH) . "\n";

        $out .= $esc . 'E' . "\x01" . $gs . '!' . "\x11";
        $out .= self::clean((string) ($data['token'] ?? '')) . "\n";
        $out .= $gs . '!' . "\x00" . $esc . 'E' . "\x00";

        $out .= "\n";
        if (!empty($data['doctor'])) {
            $out .= 'Doctor: ' . self::clean((string) $data['doctor']) . "\n";
        }
        if (!empty($data['room'])) {
            $out .= 'Room:   ' . self::clean((string) $data['room']) . "\n";
        }
        if (isset($data['ahead'])) {
            $out .= 'Ahead:  ' . (int) $data['ahead'] . "\n";
        }
        if (!empty($data['issued_at'])) {
            $out .= 'Issued: ' . self::clean((string) $data['issued_at']) . "\n";
        }

        $out .= str_repeat('-', self::WIDTH) . "\n";
        $out .= $esc . 'a' . "\x01";
        $out .= "Track your turn\n";

        // The QR code is printed as a matrix only when the caller supplies
        // one; ESC/POS native QR needs GS ( k and varies between vendors, so
        // the URL is always printed in text as the reliable path.
        if (!empty($data['url'])) {
            $out .= self::clean((string) $data['url']) . "\n";
        }
        $out .= $esc . 'd' . "\x03";       // feed
        $out .= $gs . 'V' . "\x42\x00";    // partial cut

        return $out;
    }

    /**
     * Printable HTML for the 80 mm roll.
     *
     * @param array<string, mixed> $data
     */
    public static function html(array $data): string
    {
        $esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';

        foreach (['doctor' => 'Doctor', 'room' => 'Room', 'issued_at' => 'Issued'] as $key => $label) {
            if (!empty($data[$key])) {
                $rows .= '<tr><td>' . $label . '</td><td class="r">' . $esc($data[$key]) . '</td></tr>';
            }
        }
        if (isset($data['ahead'])) {
            $rows .= '<tr><td>Before you</td><td class="r">' . (int) $data['ahead'] . '</td></tr>';
        }

        return '<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Token ' . $esc($data['token'] ?? '') . '</title>
<style>
  @page { size: 80mm auto; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 4mm 3mm; width: 80mm;
         font-family: ui-monospace, "DejaVu Sans Mono", monospace;
         font-size: 12px; line-height: 1.35; color: #000; background: #fff; }
  .c { text-align: center; }
  h1 { font-size: 15px; margin: 0 0 1mm; letter-spacing: .01em; }
  .sub { font-size: 11px; margin: 0 0 2mm; }
  .rule { border-top: 1px dashed #000; margin: 2mm 0; }
  .token { font-size: 42px; font-weight: 700; letter-spacing: 1px; margin: 1mm 0 2mm; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  td { padding: .7mm 0; vertical-align: top; }
  td.r { text-align: right; font-weight: 600; }
  .url { font-size: 9px; word-break: break-all; text-align: center; margin-top: 1mm; }
  .no-print { text-align: center; margin-top: 4mm; }
  @media print { .no-print { display: none; } }
</style></head>
<body>
  <div class="c">
    <h1>' . $esc($data['clinic'] ?? 'Clinic') . '</h1>
    <p class="sub">' . $esc($data['queue'] ?? '') . '</p>
  </div>
  <div class="c">
    <p class="sub" style="margin:0">Your token</p>
    <div class="token">' . $esc($data['token'] ?? '') . '</div>
  </div>
  <div class="rule"></div>
  <table>' . $rows . '</table>
  <div class="rule"></div>
  <div class="c">Please stay nearby — we will call your token.</div>
  ' . (!empty($data['url']) ? '<div class="url">Track live: ' . $esc($data['url']) . '</div>' : '') . '
  <div class="no-print"><button onclick="window.print()">Print slip</button></div>
  <script>window.addEventListener("load",function(){ if(!location.search.includes("manual")) setTimeout(function(){ window.print(); },350); });</script>
</body></html>';
    }

    /** Strip control characters so a patient's name cannot inject ESC codes. */
    private static function clean(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        // ESC/POS code pages differ per vendor; transliterate anything outside
        // Latin-1 to a safe placeholder rather than printing mojibake.
        return mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
    }
}
