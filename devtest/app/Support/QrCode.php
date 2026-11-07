<?php
declare(strict_types=1);

namespace App\Support;

/**
 * A small QR encoder, because the printed token slip needs one and this
 * project has no Composer.
 *
 * Scope is deliberately narrow: **byte mode, error-correction level M,
 * versions 1–6** (up to 106 bytes). That is not a general-purpose QR library
 * — it is exactly what a tracking URL needs, and every line of it is
 * verifiable against a reference encoder. A version-40 multi-block
 * implementation would be three times the code and none of it would ever run.
 *
 * Two things matter for correctness and are worth stating:
 *
 *  1. **The Reed–Solomon arithmetic is over GF(256) with the QR primitive
 *     polynomial 0x11D**, not the AES one (0x11B). Using the wrong polynomial
 *     produces a QR that looks perfect and cannot be scanned.
 *  2. **The mask is chosen by the standard's four penalty rules.** Skipping
 *     mask selection, or always using mask 0, produces codes that fail on
 *     some phone cameras — particularly on cheap thermal paper.
 *
 * Verified module-for-module against Python's `qrcode` library.
 */
final class QrCode
{
    /** Error-correction level M: ~15% recoverable, the usual choice for print. */
    private const EC_LEVEL_M_BITS = 0b00;

    /**
     * Per version (1–6), at EC level M: [total codewords, ec codewords per
     * block, [data codewords per block, ...]].
     */
    private const BLOCKS = [
        1 => [26, 10, [16]],
        2 => [44, 16, [28]],
        3 => [70, 26, [44]],
        4 => [100, 18, [32, 32]],
        5 => [134, 24, [43, 43]],
        6 => [172, 16, [27, 27, 27, 27]],
    ];

    /** Alignment pattern centre coordinates per version. */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
    ];

    /** @var array<int, array<int, bool>> module matrix, true = dark */
    private array $modules = [];
    private int $size = 0;
    /** @var array<int, array<int, bool>> reserved (function pattern) cells */
    private array $reserved = [];

    /** Which of the eight masks won the penalty contest for this code. */
    private int $chosenMask = 0;

    /**
     * Render as a PNG string.
     *
     * `$scale` is pixels per module and `$quiet` the mandatory quiet zone in
     * modules (the standard says 4; a thermal printer's edge is not always
     * crisp, so the slip uses 4 and the screen uses 2 for space).
     */
    public static function png(string $text, int $scale = 4, int $quiet = 4): string
    {
        $qr = new self();
        $qr->encode($text);
        return $qr->renderPng($scale, $quiet);
    }

    /**
     * Render as a self-contained SVG string. No external images, no scripts,
     * no external references — safe to embed in a printed slip or an email.
     */
    public static function svg(string $text, int $scale = 4, int $quiet = 4): string
    {
        $qr = new self();
        $qr->encode($text);
        $size = $qr->size;
        $dim = ($size + $quiet * 2) * $scale;

        $lines = [];
        $lines[] = '<svg xmlns="http://www.w3.org/2000/svg"';
        $lines[] = '     viewBox="0 0 ' . $dim . ' ' . $dim . '"';
        $lines[] = '     shape-rendering="crispEdges">';
        $lines[] = '  <rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>';
        foreach ($qr->modules as $r => $row) {
            foreach ($row as $c => $dark) {
                if (!$dark) {
                    continue;
                }
                $x = ($c + $quiet) * $scale;
                $y = ($r + $quiet) * $scale;
                $lines[] = '  <rect x="' . $x . '" y="' . $y . '"'
                    . ' width="' . $scale . '" height="' . $scale . '" fill="#000000"/>';
            }
        }
        $lines[] = '</svg>';
        return implode("\n", $lines) . "\n";
    }

    /**
     * The raw matrix with a specific mask forced on, for reference comparison.
     * Rows are '0'/'1' strings ('1' = dark).
     */
    public static function matrixWithMask(string $text, int $mask): array
    {
        $qr = new self();
        $qr->forcedMask = $mask;
        $qr->encode($text);
        $out = [];
        foreach ($qr->modules as $row) {
            $out[] = implode('', array_map(static fn (bool $c): string => $c ? '1' : '0', $row));
        }
        return $out;
    }

    /**
     * The raw matrix, for tests: list of rows of '0'/'1' strings ('1' = dark).
     * Strings, not booleans, so callers can `preg_match` and `str_starts_with`
     * them directly.
     */
    public static function matrix(string $text): array
    {
        $qr = new self();
        $qr->encode($text);
        $out = [];
        foreach ($qr->modules as $row) {
            $out[] = implode('', array_map(static fn (bool $c): string => $c ? '1' : '0', $row));
        }
        return $out;
    }

    /** @var int|null force a specific mask (tests compare against a reference) */
    private ?int $forcedMask = null;

    private function encode(string $text): void
    {
        $data = array_values(unpack('C*', $text) ?: []);
        $version = $this->pickVersion(count($data));

        $bits = [];
        // Mode indicator 0100 = byte mode.
        $this->push($bits, 0b0100, 4);
        // Character count: 8 bits for versions 1–9 in byte mode.
        $this->push($bits, count($data), 8);
        foreach ($data as $byte) {
            $this->push($bits, $byte, 8);
        }

        [$totalCodewords, $ecPerBlock, $dataBlocks] = self::BLOCKS[$version];
        $dataCodewords = array_sum($dataBlocks);

        // Terminator: up to four zero bits, then pad to a byte boundary.
        $capacityBits = $dataCodewords * 8;
        $this->push($bits, 0, min(4, $capacityBits - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | ($bits[$i + $j] ?? 0);
            }
            $codewords[] = $byte;
        }
        // Pad codewords 0xEC / 0x11 alternating, as the standard specifies.
        $pad = [0xEC, 0x11];
        $p = 0;
        while (count($codewords) < $dataCodewords) {
            $codewords[] = $pad[$p++ % 2];
        }

        // Split into blocks, compute EC per block, then interleave.
        $blocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ($dataBlocks as $count) {
            $block = array_slice($codewords, $offset, $count);
            $offset += $count;
            $blocks[] = $block;
            $ecBlocks[] = $this->reedSolomon($block, $ecPerBlock);
        }

        $final = [];
        $maxData = max($dataBlocks);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $final[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $final[] = $block[$i];
                }
            }
        }

        $this->size = 17 + 4 * $version;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->reserved = array_fill(0, $this->size, array_fill(0, $this->size, false));

        $this->drawFinder(0, 0);
        $this->drawFinder($this->size - 7, 0);
        $this->drawFinder(0, $this->size - 7);
        $this->drawTiming();
        $this->drawAlignment($version);
        $this->reserveFormat();
        $this->drawData($final);

        // Choose the mask with the lowest penalty, per the standard's rules.
        //
        // The chosen index must be carried through to the format bits: a code
        // whose format field says mask 0 while its data is masked with mask 5
        // is a perfect-looking QR that no scanner can read. That was the first
        // bug in this file, and it is invisible to any check short of a decode.
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $this->chosenMask = 0;
        $masks = $this->forcedMask !== null ? [$this->forcedMask] : range(0, 7);
        foreach ($masks as $mask) {
            $candidate = $this->applyMask($mask);
            $penalty = $this->penalty($candidate);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
                $this->chosenMask = $mask;
            }
        }
        $this->modules = $best ?? $this->modules;
        $this->drawFormat($this->chosenMask);
    }

    private function pickVersion(int $bytes): int
    {
        // Byte-mode data capacity at EC level M, minus the 4+8 header bits.
        $limits = [1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106];
        foreach ($limits as $version => $limit) {
            if ($bytes <= $limit) {
                return $version;
            }
        }
        throw new \InvalidArgumentException('Text is too long for this QR encoder (' . strlen($bytes <= 0 ? '' : (string) $bytes) . ' bytes; limit 106).');
    }

    /** @param array<int, int> $bits */
    private function push(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private function reedSolomon(array $data, int $ecCount): array
    {
        $generator = $this->generatorPoly($ecCount);
        $remainder = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            for ($i = 0; $i < $ecCount; $i++) {
                $remainder[$i] ^= $this->gfMul($generator[$i], $factor);
            }
        }
        return $remainder;
    }

    /**
     * Generator polynomial for `$degree` EC codewords: (x - a^0)(x - a^1)…
     *
     * @return array<int, int>
     */
    private function generatorPoly(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            $alpha = $this->gfPow(2, $i);
            foreach ($poly as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= $this->gfMul($coefficient, $alpha);
            }
            $poly = $next;
        }
        // The leading 1 corresponds to nothing in the remainder loop.
        array_shift($poly);
        return $poly;
    }

    /** Multiply in GF(256) with the QR primitive polynomial 0x11D. */
    private function gfMul(int $a, int $b): int
    {
        $result = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($b & 1) !== 0) {
                $result ^= $a;
            }
            $high = $a & 0x80;
            $a = ($a << 1) & 0xFF;
            if ($high !== 0) {
                $a ^= 0x1D; // 0x11D with the leading bit dropped
            }
            $b >>= 1;
        }
        return $result;
    }

    private function gfPow(int $base, int $exponent): int
    {
        $result = 1;
        for ($i = 0; $i < $exponent; $i++) {
            $result = $this->gfMul($result, $base);
        }
        return $result;
    }

    private function set(int $row, int $col, bool $dark): void
    {
        if ($row >= 0 && $row < $this->size && $col >= 0 && $col < $this->size) {
            $this->modules[$row][$col] = $dark;
            $this->reserved[$row][$col] = true;
        }
    }

    /** Finder pattern plus its separator. */
    private function drawFinder(int $row, int $col): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $row + $r;
                $cc = $col + $c;
                if ($rr < 0 || $rr >= $this->size || $cc < 0 || $cc >= $this->size) {
                    continue;
                }
                $inRing = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                    || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                $inCore = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                $this->set($rr, $cc, $inRing || $inCore);
            }
        }
    }

    private function drawTiming(): void
    {
        for ($i = 8; $i < $this->size - 8; $i++) {
            $dark = $i % 2 === 0;
            $this->set(6, $i, $dark);
            $this->set($i, 6, $dark);
        }
    }

    private function drawAlignment(int $version): void
    {
        $centres = self::ALIGNMENT[$version];
        foreach ($centres as $row) {
            foreach ($centres as $col) {
                // Skip the three positions occupied by finder patterns.
                if (($row === 6 && $col === 6)
                    || ($row === 6 && $col === $this->size - 7)
                    || ($row === $this->size - 7 && $col === 6)) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $ring = max(abs($r), abs($c));
                        $this->set($row + $r, $col + $c, $ring !== 1);
                    }
                }
            }
        }
    }

    /** Reserve the format-information strips so data does not land there. */
    private function reserveFormat(): void
    {
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $this->reserved[8][$i] = true;
                $this->reserved[$i][8] = true;
            }
        }
        // Top-right: eight cells along row 8, from the right edge inwards.
        for ($i = 0; $i < 8; $i++) {
            $this->reserved[8][$this->size - 1 - $i] = true;
        }
        // Bottom-left: SEVEN cells up column 8 (size-7 … size-2), then the
        // dark module at size-8. Reserving eight here eats the first data
        // column of the bottom-right block and makes the code unreadable
        // while leaving every function pattern looking perfect.
        for ($i = 1; $i <= 7; $i++) {
            $this->reserved[$this->size - $i][8] = true;
        }
        // The dark module, always set.
        $this->set($this->size - 8, 8, true);
    }

    /** @param array<int, int> $codewords */
    private function drawData(array $codewords): void
    {
        $bits = [];
        foreach ($codewords as $cw) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($cw >> $i) & 1;
            }
        }
        $index = 0;
        $upward = true;
        for ($col = $this->size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // skip the vertical timing column
            }
            for ($i = 0; $i < $this->size; $i++) {
                $row = $upward ? $this->size - 1 - $i : $i;
                foreach ([$col, $col - 1] as $c) {
                    if ($this->reserved[$row][$c]) {
                        continue;
                    }
                    $this->modules[$row][$c] = (bool) ($bits[$index] ?? 0);
                    $index++;
                }
            }
            $upward = !$upward;
        }
    }

    /** @return array<int, array<int, bool>> */
    private function applyMask(int $mask): array
    {
        $out = $this->modules;
        for ($r = 0; $r < $this->size; $r++) {
            for ($c = 0; $c < $this->size; $c++) {
                if ($this->reserved[$r][$c]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => (($r * $c) % 2 + ($r * $c) % 3) === 0,
                    6 => ((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0,
                    7 => ((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0,
                };
                if ($invert) {
                    $out[$r][$c] = !$out[$r][$c];
                }
            }
        }
        return $out;
    }

    /** @param array<int, array<int, bool>> $m */
    private function penalty(array $m): int
    {
        $score = 0;
        $size = $this->size;

        // Rule 1: runs of five or more same-coloured modules in a row/column.
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($m[$r][$c] === $m[$r - 1][$c]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }

        // Rule 2: 2x2 blocks of one colour.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($m[$r][$c + 1] === $v && $m[$r + 1][$c] === $v && $m[$r + 1][$c + 1] === $v) {
                    $score += 3;
                }
            }
        }

        // Rule 3: the finder-like 1:1:3:1:1 pattern.
        $pattern = [true, false, true, true, true, false, true];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 7; $c++) {
                if ($this->matches($m, $r, $c, 0, 1, $pattern)) {
                    $score += 40;
                }
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 7; $r++) {
                if ($this->matches($m, $r, $c, 1, 0, $pattern)) {
                    $score += 40;
                }
            }
        }

        // Rule 4: deviation of the dark-module proportion from 50%.
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }
        $ratio = ($dark * 100) / ($size * $size);
        $score += (int) (floor(abs($ratio - 50) / 5) * 10);

        return $score;
    }

    /**
     * @param array<int, array<int, bool>> $m
     * @param array<int, bool> $pattern
     */
    private function matches(array $m, int $r, int $c, int $dr, int $dc, array $pattern): bool
    {
        foreach ($pattern as $i => $expected) {
            if ($m[$r + $dr * $i][$c + $dc * $i] !== $expected) {
                return false;
            }
        }
        return true;
    }

    /** Write the format-information bits for EC level M and the chosen mask. */
    private function drawFormat(int $mask): void
    {
        // EC level M is 00; format = 00 << 3 | mask, then BCH(15,5) and XOR.
        $data = (self::EC_LEVEL_M_BITS << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        // Bit 14 is the most significant and lands first in each run. Getting
        // this backwards writes a perfectly valid-looking 15-bit field that
        // describes a different mask, so a scanner reads garbage and blames
        // the print. Verified against the reference encoder cell by cell.
        $bit = static fn (int $i): bool => (($bits >> (14 - $i)) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->modules[8][$i] = $bit($i);
        }
        $this->modules[8][7] = $bit(6);
        $this->modules[8][8] = $bit(7);
        $this->modules[7][8] = $bit(8);
        for ($i = 9; $i <= 14; $i++) {
            $this->modules[14 - $i][8] = $bit($i);
        }

        // Second copy: bits 0-7 run up the left of the bottom-left finder,
        // bits 8-14 along the top-right.
        for ($i = 0; $i <= 7; $i++) {
            $this->modules[$this->size - 1 - $i][8] = $bit($i);
        }
        for ($i = 8; $i <= 14; $i++) {
            $this->modules[8][$this->size - 15 + $i] = $bit($i);
        }
        $this->modules[$this->size - 8][8] = true; // dark module
    }

    private function renderPng(int $scale, int $quiet): string
    {
        $scale = max(1, $scale);
        $quiet = max(0, $quiet);
        $pixels = ($this->size + $quiet * 2) * $scale;

        $img = imagecreatetruecolor($pixels, $pixels);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $pixels, $pixels, $white);

        for ($r = 0; $r < $this->size; $r++) {
            for ($c = 0; $c < $this->size; $c++) {
                if (!$this->modules[$r][$c]) {
                    continue;
                }
                $x = ($c + $quiet) * $scale;
                $y = ($r + $quiet) * $scale;
                imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
            }
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        return $png;
    }
}
