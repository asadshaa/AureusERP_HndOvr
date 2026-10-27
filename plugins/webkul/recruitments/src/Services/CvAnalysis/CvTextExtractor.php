<?php

namespace Webkul\Recruitment\Services\CvAnalysis;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Turns a CV file into plain text for the CV analyser, without new Composer
 * dependencies: DOCX/ODT through PHP's zip extension, PDF through the
 * pdftotext tool when available (else PdfTextParser), TXT/RTF directly, and a
 * best-effort read of old binary .doc files.
 */
class CvTextExtractor
{
    public function __construct(
        protected PdfTextParser $pdfParser,
    ) {}

    /** @return array{text: string, source: string} */
    public function extract(string $path, ?string $fileName = null): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['text' => '', 'source' => 'missing'];
        }

        $extension = Str::lower(pathinfo($fileName ?: $path, PATHINFO_EXTENSION));

        try {
            [$text, $source] = match ($extension) {
                'pdf'          => $this->pdf($path),
                'docx'         => [$this->zippedXml($path, 'word/document.xml'), 'docx'],
                'odt'          => [$this->zippedXml($path, 'content.xml'), 'odt'],
                'txt', 'text'  => [(string) file_get_contents($path), 'txt'],
                'rtf'          => [$this->rtf((string) file_get_contents($path)), 'rtf'],
                'doc'          => [$this->binaryDoc((string) file_get_contents($path)), 'doc'],
                default        => ['', 'unsupported'],
            };
        } catch (Throwable $e) {
            report($e);

            return ['text' => '', 'source' => 'error'];
        }

        return ['text' => $this->normalise($text), 'source' => $source];
    }

    /** @return array{0: string, 1: string} */
    protected function pdf(string $path): array
    {
        $binary = config('recruitment.cv_analysis.pdftotext_path') ?: 'pdftotext';

        try {
            $result = Process::timeout(30)->run([$binary, '-enc', 'UTF-8', '-q', $path, '-']);

            if ($result->successful() && trim($result->output()) !== '') {
                return [$result->output(), 'pdftotext'];
            }
        } catch (Throwable) {
            // Not installed: fall back to the built-in reader.
        }

        return [$this->pdfParser->parse((string) file_get_contents($path)), 'pdf'];
    }

    protected function zippedXml(string $path, string $entry): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = (string) $zip->getFromName($entry);
        $zip->close();

        $xml = (string) preg_replace('#</w:p>|</text:p>|</text:h>|<w:br\s*/>|<text:line-break\s*/>#', "\n", $xml);
        $xml = (string) preg_replace('#<w:tab\s*/>|<text:tab\s*/>#', "\t", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function rtf(string $rtf): string
    {
        $text = (string) preg_replace('/\\\\par[d]?\b/', "\n", $rtf);
        $text = (string) preg_replace_callback("/\\\\'([0-9a-f]{2})/i", fn (array $m): string => mb_convert_encoding(chr(hexdec($m[1])), 'UTF-8', 'Windows-1252'), $text);
        $text = (string) preg_replace('/\\\\[a-z]+-?\d* ?|[{}]/i', '', $text);

        return $text;
    }

    /** Old Word .doc: keep the readable runs of text (good enough for keywords). */
    protected function binaryDoc(string $bytes): string
    {
        $utf16 = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{P}\p{Zs}]{3,}/u', (string) $utf16, $wide);
        preg_match_all('/[A-Za-z0-9][A-Za-z0-9 .,;:()\/@+\-]{3,}/', $bytes, $narrow);

        $wideText = implode("\n", $wide[0]);
        $narrowText = implode("\n", $narrow[0]);

        return strlen($wideText) > strlen($narrowText) ? $wideText : $narrowText;
    }

    protected function normalise(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', ' ', $text);
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        $text = (string) preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return Str::limit(trim($text), (int) config('recruitment.cv_analysis.max_text_chars', 100000), '');
    }
}
