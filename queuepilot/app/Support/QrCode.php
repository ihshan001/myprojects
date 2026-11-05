<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Self-contained QR Code encoder (ISO/IEC 18004), byte mode, versions 1–10,
 * error-correction level M, mask pattern 0.
 *
 * Why hand-rolled: no Composer in this deployment, and a QR code is the one
 * thing a receptionist actually needs (scan instead of typing a URL).
 *
 * Verified by tests/qr_test.php, which checks:
 *   • the codeword stream against the spec's bit layout,
 *   • the data-module count equals codewords × 8 (placement is exact),
 *   • round-trip decoding with an independent decoder for several payloads.
 *
 * Layout notes that are easy to get wrong (and are asserted in tests):
 *   • The two 15-module format areas share the module at (8,8) → reserve once.
 *   • The vertical timing column (x=6) is skipped by shifting $right to 5,
 *     which is why the traversal direction is computed from the shifted value.
 *   • The first pad byte after the terminator is 0xEC (then 0x11 alternating).
 */
final class QrCode
{
    /** [version][eccLevelIndex 0=L,1=M,2=Q,3=H] */
    private const ECC_CODEWORDS_PER_BLOCK = [
        1 => [7, 10, 13, 17], 2 => [10, 16, 22, 28], 3 => [15, 26, 18, 22],
        4 => [20, 18, 26, 16], 5 => [26, 24, 18, 22], 6 => [18, 16, 24, 28],
        7 => [20, 18, 18, 26], 8 => [24, 22, 22, 26], 9 => [30, 22, 20, 24],
        10 => [18, 26, 24, 28],
    ];

    private const NUM_BLOCKS = [
        1 => [1, 1, 1, 1], 2 => [1, 1, 1, 1], 3 => [1, 1, 2, 2], 4 => [1, 2, 2, 4],
        5 => [1, 2, 4, 4], 6 => [2, 4, 4, 4], 7 => [2, 4, 6, 5], 8 => [2, 4, 6, 6],
        9 => [2, 5, 8, 8], 10 => [4, 5, 8, 8],
    ];

    private const TOTAL_CODEWORDS = [
        1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
        6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
    ];

    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var array<int, array<int, bool>> */
    private array $modules = [];
    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];
    private int $size;
    private int $version;
    /** 0=L 1=M 2=Q 3=H */
    private int $eccLevel;

    private function __construct(int $version, int $eccLevel)
    {
        $this->version = $version;
        $this->eccLevel = $eccLevel;
        $this->size = $version * 4 + 17;
        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->isFunction[$y] = array_fill(0, $this->size, false);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Public API                                                          */
    /* ------------------------------------------------------------------ */

    public static function svg(string $text, int $scale = 4, string $dark = '#0f172a', string $light = '#ffffff'): string
    {
        [$version, $ecc] = self::fitVersion($text);
        $qr = new self($version, $ecc);
        $qr->encode($text);

        $border = 4;
        $dim = ($qr->size + $border * 2) * $scale;
        $path = '';
        for ($y = 0; $y < $qr->size; $y++) {
            for ($x = 0; $x < $qr->size; $x++) {
                if ($qr->modules[$y][$x]) {
                    $px = ($x + $border) * $scale;
                    $py = ($y + $border) * $scale;
                    $path .= "M{$px} {$py}h{$scale}v{$scale}h-{$scale}z";
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim
            . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img">'
            . '<rect width="100%" height="100%" fill="' . $light . '"/>'
            . '<path d="' . $path . '" fill="' . $dark . '"/></svg>';
    }

    /** Inline data URI — no file writes, no extra request, CSP-safe. */
    public static function dataUri(string $text, int $scale = 4, string $dark = '#0f172a', string $light = '#ffffff'): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($text, $scale, $dark, $light));
    }

    /** @return array<int, string> rows of '0'/'1' — used by the test suite. */
    public static function matrix(string $text): array
    {
        [$version, $ecc] = self::fitVersion($text);
        $qr = new self($version, $ecc);
        $qr->encode($text);
        $out = [];
        for ($y = 0; $y < $qr->size; $y++) {
            $row = '';
            for ($x = 0; $x < $qr->size; $x++) {
                $row .= $qr->modules[$y][$x] ? '1' : '0';
            }
            $out[] = $row;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Capacity selection                                                  */
    /* ------------------------------------------------------------------ */

    /** @return array{0:int,1:int} version, ecc index */
    private static function fitVersion(string $text): array
    {
        $len = strlen($text);
        foreach (self::TOTAL_CODEWORDS as $version => $total) {
            $ecc = self::ECC_CODEWORDS_PER_BLOCK[$version][1];      // prefer M
            $blocks = self::NUM_BLOCKS[$version][1];
            $dataCodewords = $total - $ecc * $blocks;
            $headerBits = 4 + ($version <= 9 ? 8 : 16);
            if ($headerBits + $len * 8 <= $dataCodewords * 8) {
                return [$version, 1];
            }
        }
        throw new \RuntimeException('Payload too long for this QR implementation (' . $len . ' bytes).');
    }

    /* ------------------------------------------------------------------ */
    /* Encoding                                                            */
    /* ------------------------------------------------------------------ */

    private function encode(string $text): void
    {
        $this->drawFunctionPatterns();
        $codewords = $this->buildCodewords($text);
        $this->drawCodewords($codewords);
        $this->drawFormatBits();
        if ($this->version >= 7) {
            $this->drawVersionBits();
        }
    }

    /** @return array<int, int> final interleaved data + ECC codewords */
    public static function codewordsFor(string $text): array
    {
        [$version, $ecc] = self::fitVersion($text);
        $qr = new self($version, $ecc);
        return $qr->buildCodewords($text);
    }

    /** @return array<int, int> */
    private function buildCodewords(string $text): array
    {
        $total = self::TOTAL_CODEWORDS[$this->version];
        $eccPerBlock = self::ECC_CODEWORDS_PER_BLOCK[$this->version][$this->eccLevel];
        $blockCount = self::NUM_BLOCKS[$this->version][$this->eccLevel];
        $dataCodewords = $total - $eccPerBlock * $blockCount;
        $capacityBits = $dataCodewords * 8;

        $bits = [];
        $this->appendBits($bits, 0b0100, 4);                                  // byte mode
        $this->appendBits($bits, strlen($text), $this->version <= 9 ? 8 : 16);
        for ($i = 0; $i < strlen($text); $i++) {
            $this->appendBits($bits, ord($text[$i]), 8);
        }

        // terminator (up to 4 zero bits, never beyond capacity)
        $this->appendBits($bits, 0, max(0, min(4, $capacityBits - count($bits))));
        // pad to byte boundary
        $this->appendBits($bits, 0, (8 - count($bits) % 8) % 8);
        // alternating pad bytes, starting with 0xEC
        for ($p = 0; count($bits) < $capacityBits; $p++) {
            $this->appendBits($bits, $p % 2 === 0 ? 0xEC : 0x11, 8);
        }

        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $data[] = $byte;
        }

        // split into RS blocks (short blocks first, then long ones)
        $shortLen = intdiv($dataCodewords, $blockCount);
        $longCount = $dataCodewords % $blockCount;
        $blocks = [];
        $eccBlocks = [];
        $offset = 0;
        for ($b = 0; $b < $blockCount; $b++) {
            $len = $shortLen + ($b >= $blockCount - $longCount ? 1 : 0);
            $chunk = array_slice($data, $offset, $len);
            $offset += $len;
            $blocks[] = $chunk;
            $eccBlocks[] = $this->reedSolomon($chunk, $eccPerBlock);
        }

        // interleave data, then interleave ECC
        $out = [];
        $maxData = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                $out[] = $block[$i];
            }
        }
        return $out;
    }

    private function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /** @return array<int, int> */
    private function reedSolomon(array $data, int $eccLen): array
    {
        static $exp = null, $log = null;
        if ($exp === null) {
            $exp = [];
            $log = [];
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
        }

        // Generator polynomial: ∏(X − α^i) for i = 0..eccLen-1.
        // Coefficients are kept HIGHEST-degree-first (gen[0] = 1), which is the
        // order the synthesised division needs below.
        $gen = [1];
        for ($i = 0; $i < $eccLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                if ($coef !== 0) {
                    $next[$j] ^= $exp[($log[$coef] + $i) % 255];
                }
                $next[$j + 1] ^= $coef;
            }
            $gen = $next;
        }
        $gen = array_reverse($gen);   // now gen[0] = 1 (leading term)

        $rem = array_fill(0, $eccLen, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $rem[0];
            array_shift($rem);
            $rem[] = 0;
            if ($factor !== 0) {
                $logFactor = $log[$factor];
                for ($i = 0; $i < $eccLen; $i++) {
                    $rem[$i] ^= $exp[($logFactor + $log[$gen[$i + 1]]) % 255];
                }
            }
        }
        return $rem;
    }

    /* ------------------------------------------------------------------ */
    /* Function patterns                                                   */
    /* ------------------------------------------------------------------ */

    private function drawFunctionPatterns(): void
    {
        // timing patterns
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        // Finder pattern = 7×7 (rings at distance 0..3: dark,light,dark core),
        // surrounded by a light separator (distance 4). Only the separator
        // ring is light — getting this inverted breaks every decoder.
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    if ($dist === 4) {
                        $this->setFunction($cx + $dx, $cy + $dy, false);   // separator
                        continue;
                    }
                    // dist 3 = outer dark ring, 2 = light ring, 1 & 0 = 3×3 core
                    $this->setFunction($cx + $dx, $cy + $dy, $dist !== 2);
                }
            }
        }

        // alignment patterns
        $centres = self::ALIGNMENT[$this->version];
        $last = $this->size - 7;
        foreach ($centres as $px) {
            foreach ($centres as $py) {
                if (($px === 6 && $py === 6) || ($px === 6 && $py === $last) || ($px === $last && $py === 6)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        // Reserve the two 15-module format-info areas; they share (8,8).
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, false);
            $this->setFunction($i, 8, false);
        }
        $this->setFunction(8, 7, false);
        $this->setFunction(8, 8, false);       // shared
        $this->setFunction(7, 8, false);
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, false);
            $this->setFunction(8, $this->size - 1 - $i, false);
        }
        $this->setFunction(8, $this->size - 8, true);   // dark module

        // Reserve version-info areas for version ≥ 7.
        if ($this->version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->setFunction($a, $b, false);
                $this->setFunction($b, $a, false);
            }
        }
    }

    private function drawFormatBits(): void
    {
        // The format word encodes the ECC *indicator* (L=01, M=00, Q=11, H=10),
        // not the internal 0..3 index, plus the mask id (0 here).
        $eccBits = [0b01, 0b00, 0b11, 0b10][$this->eccLevel];
        $data = ($eccBits << 3) | 0;

        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, (($bits >> $i) & 1) === 1);
        }
        $this->setFunction(8, 7, (($bits >> 6) & 1) === 1);
        $this->setFunction(8, 8, (($bits >> 7) & 1) === 1);
        $this->setFunction(7, 8, (($bits >> 8) & 1) === 1);
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, (($bits >> $i) & 1) === 1);
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, (($bits >> $i) & 1) === 1);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, (($bits >> $i) & 1) === 1);
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersionBits(): void
    {
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $bit);
            $this->setFunction($b, $a, $bit);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Data placement                                                      */
    /* ------------------------------------------------------------------ */

    private function drawCodewords(array $codewords): void
    {
        $totalBits = count($codewords) * 8;
        $bitIndex = 0;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;   // skip the vertical timing column
            }
            // Pairs alternate upward/downward, starting upward at the right edge.
            $upward = (($right + 1) & 2) === 0;

            for ($vert = 0; $vert < $this->size; $vert++) {
                $y = $upward ? $this->size - 1 - $vert : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if ($this->isFunction[$y][$x]) {
                        continue;
                    }
                    $bit = false;
                    if ($bitIndex < $totalBits) {
                        $bit = (($codewords[$bitIndex >> 3] >> (7 - ($bitIndex & 7))) & 1) === 1;
                        $bitIndex++;
                    }
                    if (($x + $y) % 2 === 0) {   // mask pattern 0
                        $bit = !$bit;
                    }
                    $this->modules[$y][$x] = $bit;
                }
            }
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }
}
