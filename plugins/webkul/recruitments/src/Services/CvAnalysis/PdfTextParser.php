<?php

namespace Webkul\Recruitment\Services\CvAnalysis;

/**
 * Minimal, dependency-free text reader for PDF CVs: inflates FlateDecode
 * streams, follows each font's ToUnicode map (so embedded Unicode fonts, as
 * exported by Word, Google Docs or Canva, read correctly) and reads the text
 * operators (Tj, TJ, ', "). Scanned CVs (images) have no text and return "".
 * When the pdftotext tool is installed, CvTextExtractor prefers it.
 */
class PdfTextParser
{
    /** @var array<int, array{dictionary: string, stream: ?string}> */
    private array $objects = [];

    /** @var array<string, array{width: int, chars: array<string, string>, ranges: array<int, array{0: int, 1: int, 2: int, 3: int|array<int, string>}>}> font resource name => its ToUnicode map */
    private array $fontMaps = [];

    /** @var array{width: int, chars: array<string, string>, ranges: array<int, array{0: int, 1: int, 2: int, 3: int|array<int, string>}>}|null */
    private ?array $currentMap = null;

    public function parse(string $pdf): string
    {
        $this->objects = $this->objects($pdf);
        $this->fontMaps = $this->fontMaps();

        $text = '';

        foreach ($this->objects as $object) {
            $stream = $object['stream'];

            if ($stream !== null && ! str_contains($stream, 'begincmap') && str_contains($stream, 'BT') && preg_match('/\b(Tj|TJ)\b/', $stream)) {
                $this->currentMap = null;
                $text .= $this->readContent($stream)."\n";
            }
        }

        return trim((string) preg_replace("/[ \t]+/", ' ', (string) preg_replace("/\n{3,}/", "\n\n", $text)));
    }

    /** @return array<int, array{dictionary: string, stream: ?string}> */
    private function objects(string $pdf): array
    {
        preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)\bendobj/s', $pdf, $matches, PREG_SET_ORDER);

        $objects = [];

        foreach ($matches as [, $id, $body]) {
            $stream = null;
            $dictionary = $body;

            if (preg_match('/^(.*?)\bstream\r?\n(.*?)\r?\n?endstream/s', $body, $parts)) {
                [, $dictionary, $raw] = $parts;
                $stream = $this->decodeStream($dictionary, $raw);
            }

            $objects[(int) $id] = ['dictionary' => $dictionary, 'stream' => $stream];
        }

        return $objects;
    }

    private function decodeStream(string $dictionary, string $raw): ?string
    {
        if (preg_match('#/Subtype\s*/Image#', $dictionary)) {
            return null;
        }

        if (str_contains($dictionary, '/FlateDecode')) {
            foreach ([fn () => @gzuncompress($raw), fn () => @gzinflate($raw), fn () => @gzinflate(substr($raw, 2))] as $inflate) {
                $inflated = $inflate();

                if ($inflated !== false) {
                    return $inflated;
                }
            }

            return null;
        }

        return preg_match('#/Filter\s*/#', $dictionary) ? null : $raw;
    }

    /**
     * Font resource names (/F1, /TT2…) mapped to the ToUnicode table of the
     * font they point at.
     *
     * @return array<string, array{width: int, chars: array<string, string>, ranges: array<int, array{0: int, 1: int, 2: int, 3: int|array<int, string>}>}>
     */
    private function fontMaps(): array
    {
        $mapsByFont = [];

        foreach ($this->objects as $id => $object) {
            if (preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $object['dictionary'], $ref)
                && ($cmap = $this->objects[(int) $ref[1]]['stream'] ?? null) !== null) {
                $mapsByFont[$id] = $this->readCMap($cmap);
            }
        }

        $byName = [];

        foreach ($this->objects as $object) {
            $dictionary = $object['dictionary'];

            if (preg_match('#/Font\s+(\d+)\s+\d+\s+R#', $dictionary, $ref)) {
                $dictionary = $this->objects[(int) $ref[1]]['dictionary'] ?? '';
            } elseif (preg_match('#/Font\s*<<(.*?)>>#s', $dictionary, $inline)) {
                $dictionary = $inline[1];
            } else {
                continue;
            }

            preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $dictionary, $fonts, PREG_SET_ORDER);

            foreach ($fonts as [, $name, $fontId]) {
                if (isset($mapsByFont[(int) $fontId])) {
                    $byName[$name] = $mapsByFont[(int) $fontId];
                }
            }
        }

        return $byName;
    }

    /** @return array{width: int, chars: array<string, string>, ranges: array<int, array{0: int, 1: int, 2: int, 3: int|array<int, string>}>} */
    private function readCMap(string $cmap): array
    {
        $map = ['width' => 2, 'chars' => [], 'ranges' => []];

        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $space)) {
            $map['width'] = max(1, intdiv(strlen($space[1]), 2));
        }

        preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $chars);

        foreach ($chars[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER);

            foreach ($pairs as [, $code, $unicode]) {
                $map['chars'][strtoupper($code)] = $this->utf16Hex($unicode);
            }
        }

        preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $ranges);

        foreach ($ranges[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $block, $rows, PREG_SET_ORDER);

            foreach ($rows as [, $from, $to, $target]) {
                $targets = $target[0] === '['
                    ? (preg_match_all('/<([0-9A-Fa-f]+)>/', $target, $items) ? array_map(fn (string $hex): string => $this->utf16Hex($hex), $items[1]) : [])
                    : (int) hexdec(trim($target, '<>'));

                $map['ranges'][] = [intdiv(strlen($from), 2), (int) hexdec($from), (int) hexdec($to), $targets];
            }
        }

        return $map;
    }

    private function readContent(string $content): string
    {
        $out = '';
        $length = strlen($content);
        $operands = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === '(') {
                [$string, $i] = $this->literal($content, $i);
                $operands[] = ['str', $string];
            } elseif ($char === '<' && ($content[$i + 1] ?? '') !== '<') {
                $close = strpos($content, '>', $i);
                $close = $close === false ? $length - 1 : $close;
                $operands[] = ['hex', (string) hex2bin($this->evenHex(substr($content, $i + 1, $close - $i - 1)))];
                $i = $close;
            } elseif ($char === '/') {
                preg_match('#/([^\s/<>\[\]()]*)#', $content, $name, 0, $i);
                $operands[] = ['name', $name[1] ?? ''];
                $i += strlen($name[0] ?? '/') - 1;
            } elseif ($char === '[') {
                $operands[] = ['array_start', null];
            } elseif ($char === ']') {
                $operands[] = ['array_end', null];
            } elseif (preg_match('/[-+.\d]/', $char)) {
                preg_match('/[-+]?\d*\.?\d+/', $content, $number, 0, $i);
                $operands[] = ['num', (float) ($number[0] ?? 0)];
                $i += max(strlen($number[0] ?? '1'), 1) - 1;
            } elseif (ctype_alpha($char) || $char === "'" || $char === '"' || $char === '*') {
                preg_match('/[A-Za-z\'"*]+/', $content, $word, 0, $i);
                $operator = $word[0] ?? $char;
                $i += strlen($operator) - 1;

                if ($operator === 'Tf') {
                    $font = collect($operands)->firstWhere(0, 'name')[1] ?? null;
                    $this->currentMap = $font !== null ? ($this->fontMaps[$font] ?? null) : null;
                }

                $out .= match ($operator) {
                    'Tj'             => $this->joinStrings($operands),
                    'TJ'             => $this->joinArray($operands),
                    "'", '"'         => "\n".$this->joinStrings($operands),
                    'Td', 'TD', 'T*' => $this->lineBreak($operands),
                    'Tm', 'ET'       => "\n",
                    default          => '',
                };

                $operands = [];
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: int} */
    private function literal(string $content, int $start): array
    {
        $depth = 0;
        $out = '';
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === '\\') {
                $next = $content[++$i] ?? '';
                $out .= match ($next) {
                    'n'        => "\n",
                    'r'        => "\r",
                    't'        => "\t",
                    'b', 'f'   => '',
                    "\r", "\n" => '',
                    default    => ctype_digit($next) ? $this->octal($content, $i) : $next,
                };

                continue;
            }

            if ($char === '(') {
                if ($depth++ > 0) {
                    $out .= $char;
                }

                continue;
            }

            if ($char === ')') {
                if (--$depth === 0) {
                    return [$out, $i];
                }

                $out .= $char;

                continue;
            }

            $out .= $char;
        }

        return [$out, $length];
    }

    private function octal(string $content, int &$i): string
    {
        preg_match('/[0-7]{1,3}/', $content, $digits, 0, $i);
        $i += strlen($digits[0]) - 1;

        return chr(octdec($digits[0]) & 0xFF);
    }

    /** @param  array<int, array{0: string, 1: mixed}>  $operands */
    private function joinStrings(array $operands): string
    {
        return collect($operands)
            ->filter(fn (array $operand): bool => in_array($operand[0], ['str', 'hex'], true))
            ->map(fn (array $operand): string => $this->decode((string) $operand[1]))
            ->implode('');
    }

    /** @param  array<int, array{0: string, 1: mixed}>  $operands */
    private function joinArray(array $operands): string
    {
        $out = '';

        foreach ($operands as $operand) {
            if ($operand[0] === 'num') {
                $out .= $operand[1] < -200 ? ' ' : '';
            } elseif (in_array($operand[0], ['str', 'hex'], true)) {
                $out .= $this->decode((string) $operand[1]);
            }
        }

        return $out;
    }

    /** @param  array<int, array{0: string, 1: mixed}>  $operands */
    private function lineBreak(array $operands): string
    {
        $y = collect($operands)->where(0, 'num')->values()->get(1)[1] ?? null;

        return $y === null || abs((float) $y) > 0.01 ? "\n" : ' ';
    }

    /** Raw string bytes as text, through the current font's ToUnicode map when it has one. */
    private function decode(string $bytes): string
    {
        $map = $this->currentMap;

        if ($map === null) {
            return $this->winAnsi($bytes);
        }

        $width = $map['width'];
        $out = '';

        foreach (str_split(bin2hex($bytes), $width * 2) as $code) {
            $out .= $this->lookup($map, strtoupper($code), $width);
        }

        return $out;
    }

    /** @param  array{width: int, chars: array<string, string>, ranges: array<int, array{0: int, 1: int, 2: int, 3: int|array<int, string>}>}  $map */
    private function lookup(array $map, string $code, int $width): string
    {
        if (isset($map['chars'][$code])) {
            return $map['chars'][$code];
        }

        $value = (int) hexdec($code);

        foreach ($map['ranges'] as [$rangeWidth, $start, $end, $target]) {
            if ($rangeWidth === $width && $value >= $start && $value <= $end) {
                return is_array($target)
                    ? ($target[$value - $start] ?? '')
                    : (string) mb_chr($target + ($value - $start), 'UTF-8');
            }
        }

        return $width === 1 ? $this->winAnsi(chr($value)) : '';
    }

    private function winAnsi(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }

        return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    private function utf16Hex(string $hex): string
    {
        $bytes = hex2bin($this->evenHex($hex));

        return $bytes === false || $bytes === '' ? '' : (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
    }

    private function evenHex(string $hex): string
    {
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', $hex);

        return strlen($hex) % 2 ? $hex.'0' : $hex;
    }
}
