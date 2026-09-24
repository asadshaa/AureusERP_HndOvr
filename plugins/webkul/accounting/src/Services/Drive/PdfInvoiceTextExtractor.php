<?php

namespace Webkul\Accounting\Services\Drive;

use Carbon\Carbon;
use Throwable;

/**
 * Pure-PHP stream-based PDF candidate extractor.
 *
 * Acting STRICTLY as an untrusted input analysis layer.
 * Values extracted here are candidates and must be validated against
 * authoritative Aureus master data (Company, Partner, FS Tag, GL, Tax, Currency)
 * before being considered for review or accounting.
 */
class PdfInvoiceTextExtractor
{
    /**
     * Extracts raw candidate fields from raw PDF binary bytes.
     *
     * @return array{
     *     raw_text: string,
     *     is_scanned: bool,
     *     is_malformed: bool,
     *     document_type_candidate: ?string,
     *     invoice_number: ?string,
     *     invoice_date: ?string,
     *     due_date: ?string,
     *     partner_name: ?string,
     *     tax_id: ?string,
     *     strn: ?string,
     *     currency_code: ?string,
     *     subtotal: ?float,
     *     tax_amount: ?float,
     *     tax_rate: ?float,
     *     discount_amount: ?float,
     *     total_amount: ?float,
     *     fs_tag_code: ?string,
     *     line_items: array<int, array{description: string, quantity: float, unit_price: float, amount: float}>,
     *     math_balanced: bool
     * }
     */
    public function extract(string $binaryContent, ?float $currencyRounding = 0.01): array
    {
        $rounding = $currencyRounding ?? 0.01;

        if (! str_starts_with($binaryContent, '%PDF-') && ! str_contains(substr($binaryContent, 0, 1024), '%PDF-')) {
            return $this->emptyCandidate(isMalformed: true);
        }

        try {
            $text = $this->extractTextFromPdfStreams($binaryContent);
        } catch (Throwable) {
            return $this->emptyCandidate(isMalformed: true);
        }

        // dompdf renders the Rs/PKR currency glyph as raw byte 0xA0 (not
        // valid standalone UTF-8 -- it's a leftover single-byte non-breaking
        // space from the PDF's internal encoding), sitting directly between
        // the currency code and the digits (e.g. "PKR\xA05,000.00").
        // Confirmed live against the actual extracted bytes: this silently
        // broke every amount-extraction regex below, since none of them
        // tolerate a non-digit, non-whitespace byte in that position.
        $text = str_replace("\xA0", '', $text);

        $cleanText = trim($text);
        if ($cleanText === '') {
            return $this->emptyCandidate(isScanned: true);
        }

        $lines = preg_split('/\r\n|\r|\n/', $cleanText);
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));

        $docType = $this->detectDocumentType($cleanText);
        $invoiceNumber = $this->extractInvoiceNumber($cleanText);
        $dates = $this->extractDates($cleanText);
        $partnerName = $this->extractPartnerName($lines);
        $taxId = $this->extractRegex($cleanText, '/(?:NTN|Tax\s*ID|VAT\s*No|TRN)[:\s#]*([A-Z0-9\-]+)/i');
        $strn = $this->extractRegex($cleanText, '/(?:STRN|Sales\s*Tax\s*Reg(?:istration)?(?:\s*No)?[:\s#]*)([0-9\-]+)/i');
        $currencyCode = $this->extractCurrencyCode($cleanText);
        $financials = $this->extractFinancials($cleanText);
        $fsTagCode = $this->extractFsTag($cleanText);
        $lineItems = $this->extractLineItemsBestEffort($lines);

        $mathBalanced = $this->verifyMathBalance($financials, $lineItems, $rounding);

        return [
            'raw_text'                => $cleanText,
            'is_scanned'              => false,
            'is_malformed'            => false,
            'document_type_candidate' => $docType,
            'invoice_number'          => $invoiceNumber,
            'invoice_date'            => $dates['invoice_date'] ?? null,
            'due_date'                => $dates['due_date'] ?? null,
            'partner_name'            => $partnerName,
            'tax_id'                  => $taxId,
            'strn'                    => $strn,
            'currency_code'           => $currencyCode,
            'subtotal'                => $financials['subtotal'],
            'tax_amount'              => $financials['tax_amount'],
            'tax_rate'                => $financials['tax_rate'],
            'discount_amount'         => $financials['discount_amount'],
            'total_amount'            => $financials['total_amount'],
            'fs_tag_code'             => $fsTagCode,
            'line_items'              => $lineItems,
            'math_balanced'           => $mathBalanced,
        ];
    }

    /**
     * Unpacks and decompresses text object streams from PDF content.
     */
    private function extractTextFromPdfStreams(string $pdf): string
    {
        $textResult = '';

        if (str_contains($pdf, 'stream') && ! str_contains($pdf, 'endstream')) {
            throw new \RuntimeException('Malformed PDF: unclosed stream.');
        }

        // Match streams, checking preceding dict to skip font binaries (/Length1, /FontFile, etc.)
        preg_match_all('/(?:<<(?<dict>[^>]*?)>>\s*)?stream[\r\n]+(?<stream>.*?)[\r\n]+endstream/s', $pdf, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $dict = $match['dict'] ?? '';
            if (str_contains($dict, '/Length1') || str_contains($dict, '/FontFile') || str_contains($dict, '/Type /Font')) {
                continue;
            }

            $stream = $match['stream'];
            $decompressed = @gzuncompress($stream);
            $data = ($decompressed !== false) ? $decompressed : $stream;

            if ($this->hasTextOperators($data)) {
                $textResult .= ' '.$this->parseTextOperators($data);
            }
        }

        // Also check uncompressed text operands outside streams
        if ($textResult === '' && $this->hasTextOperators($pdf)) {
            $textResult = $this->parseTextOperators($pdf);
        }

        // Normalize non-breaking spaces and clean null bytes
        $textResult = str_replace(["\xc2\xa0", "\u{00A0}", "\0"], ' ', $textResult);

        return $textResult;
    }

    private function hasTextOperators(string $content): bool
    {
        return str_contains($content, 'BT') && str_contains($content, 'ET');
    }

    private function parseTextOperators(string $content): string
    {
        $extracted = '';

        preg_match_all('/BT[\s\S]*?ET/', $content, $blocks);

        foreach ($blocks[0] as $block) {
            // Match (string) Tj
            if (preg_match_all('/\((.*?)\)\s*Tj/', $block, $tjMatches)) {
                foreach ($tjMatches[1] as $m) {
                    $extracted .= $this->unescapePdfString($m).' ';
                }
            }

            // Match [(array) ... (of) ... (strings)] TJ
            if (preg_match_all('/\[(.*?)\]\s*TJ/', $block, $tjArrayMatches)) {
                foreach ($tjArrayMatches[1] as $arr) {
                    if (preg_match_all('/\((.*?)\)/', $arr, $itemMatches)) {
                        foreach ($itemMatches[1] as $item) {
                            $extracted .= $this->unescapePdfString($item);
                        }
                        $extracted .= "\n";
                    }
                }
            }

            // Match ' (single quote) or " (double quote) text operators
            if (preg_match_all('/\((.*?)\)\s*[\'"]/', $block, $quoteMatches)) {
                foreach ($quoteMatches[1] as $q) {
                    $extracted .= "\n".$this->unescapePdfString($q).' ';
                }
            }
        }

        return $extracted;
    }

    private function unescapePdfString(string $str): string
    {
        $unescaped = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $str);

        // Octal escapes \000 - \377
        $unescaped = preg_replace_callback('/\\\\([0-7]{1,3})/', fn ($m) => chr(octdec($m[1])), $unescaped);

        // UTF-16BE with BOM (\xFE\xFF)
        if (str_starts_with($unescaped, "\xFE\xFF")) {
            $conv = @mb_convert_encoding(substr($unescaped, 2), 'UTF-8', 'UTF-16BE');
            if ($conv !== false) {
                return $conv;
            }
        }

        // UTF-16BE without BOM (null bytes interleaved, standard in Dompdf TTF streams)
        if (str_contains($unescaped, "\0")) {
            $conv = @mb_convert_encoding($unescaped, 'UTF-8', 'UTF-16BE');
            if ($conv !== false && ! str_contains($conv, "\0") && trim($conv) !== '') {
                return $conv;
            }
            $unescaped = str_replace("\0", '', $unescaped);
        }

        return $unescaped;
    }

    /**
     * Money-direction detection -- deliberately refuses to guess. The bare
     * word "invoice" is genuinely ambiguous in everyday language (people
     * call a vendor's bill "an invoice" just as often as a customer
     * invoice), so it used to default straight to customer_invoice (money
     * IN) whenever no other qualifier was present -- meaning a plain
     * vendor-issued PDF titled just "INVOICE" would silently get the
     * money direction backwards. Only return a type when there's an
     * unambiguous, explicit signal either way; otherwise return null and
     * let the existing "could not determine document type" path route it
     * to NeedsReview for a human to set explicitly via Edit & Submit,
     * exactly as an unrecognized filename already does.
     */
    private function detectDocumentType(string $text): ?string
    {
        $lower = strtolower($text);

        if (str_contains($lower, 'credit note') || str_contains($lower, 'credit memo') || str_contains($lower, 'refund') || str_contains($lower, 'rbill')) {
            return 'credit_note';
        }

        if (str_contains($lower, 'debit note') || str_contains($lower, 'debit memo')) {
            return 'debit_note';
        }

        if (str_contains($lower, 'vendor bill') || str_contains($lower, 'bill to pay') || str_contains($lower, 'purchase invoice')) {
            return 'vendor_bill';
        }

        if (str_contains($lower, 'tax invoice') || str_contains($lower, 'sales invoice') || str_contains($lower, 'customer invoice')) {
            return 'customer_invoice';
        }

        // "Bill To" (addressed to a customer, billing them) is a genuine,
        // unambiguous signal distinct from the bare word "invoice" -- this
        // app's own real customer-invoice PDF template says only "Invoice
        // ID #..." as its title (no "tax"/"sales"/"customer" qualifier),
        // but always renders a "Bill To" label next to the customer's
        // name, which never appears on a vendor bill's PDF at all.
        if (str_contains($lower, 'bill to') && str_contains($lower, 'invoice')) {
            return 'customer_invoice';
        }

        return null;
    }

    private function extractInvoiceNumber(string $text): ?string
    {
        $patterns = [
            '/(?:Invoice|Bill|Inv|Reference|Doc|Credit\s*Note|Refund)\s*(?:ID|No|Number|#)?[:\s#]+([A-Z0-9\-\/]+)/i',
            '/\b(INV[-_\/][0-9]{4}[-_\/][0-9]{3,6})\b/i',
            '/\b(BILL[-_\/][0-9]{4}[-_\/][0-9]{3,6})\b/i',
            '/\b(RBILL[-_\/][0-9]{4}[-_\/][0-9]{3,6})\b/i',
            '/\b(CN[-_\/][0-9]{4}[-_\/][0-9]{3,6})\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $val = trim($m[1], " \t\n\r\0\x0B/-#");
                if (strlen($val) >= 3 && ! is_numeric($val)) {
                    return $val;
                }
                if (is_numeric($val) && strlen($val) >= 3) {
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * @return array{invoice_date: ?string, due_date: ?string}
     */
    private function extractDates(string $text): array
    {
        $invoiceDate = null;
        $dueDate = null;

        $dateRegex = '([0-9]{4}[-\/.][0-9]{1,2}[-\/.][0-9]{1,2}|[0-9]{1,2}[-\/.][0-9]{1,2}[-\/.][0-9]{4}|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+[0-9]{1,2},?\s+[0-9]{4})';

        if (preg_match('/(?:Invoice|Bill|Issue|Refund)\s*Date[:\s]+'.$dateRegex.'/i', $text, $m)) {
            $invoiceDate = $this->normalizeDate($m[1]);
        }

        if (preg_match('/(?:Due\s*Date|Payment\s*Due)[:\s]+'.$dateRegex.'/i', $text, $m)) {
            $dueDate = $this->normalizeDate($m[1]);
        }

        if (! $invoiceDate && preg_match('/Date[:\s]+'.$dateRegex.'/i', $text, $m)) {
            $invoiceDate = $this->normalizeDate($m[1]);
        }

        return [
            'invoice_date' => $invoiceDate,
            'due_date'     => $dueDate,
        ];
    }

    private function normalizeDate(string $val): ?string
    {
        try {
            return Carbon::parse(trim($val))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function extractPartnerName(array $lines): ?string
    {
        foreach ($lines as $i => $line) {
            // Ignore lines that are document titles/numbers (e.g. "Customer
            // Invoice #...", "Vendor Bill #...", "Vendor Bill ID #..."). This
            // app's own bill template's title line starts with the bare word
            // "Vendor" ("Vendor Bill ID #BILL/..."), which the label check
            // just below used to mistake for a "Vendor: <name>" field --
            // confirmed live: it always extracted the next field's label
            // ("Date") as the partner name instead of the real vendor name
            // found further down the document. [^\n#]* (not just \s*)
            // between the keyword and # catches title lines with extra
            // words in between, like "... ID #...".
            if (preg_match('/(?:Invoice|Bill|Refund|Credit\s*Note|Vendor|Customer)[^\n#]*#/i', $line)) {
                continue;
            }

            if (preg_match('/^(?:Bill\s*To|Customer|Vendor|Billed\s*To|Supplier|Sold\s*To)[:\s]*(.*)/i', $line, $m)) {
                $candidate = trim($m[1]);
                if ($candidate !== '' && ! preg_match('/^(?:Invoice|Bill|Refund|Credit\s*Note)/i', $candidate)) {
                    return $candidate;
                }
                if (isset($lines[$i + 1])) {
                    $next = trim($lines[$i + 1]);
                    if ($next !== '' && ! preg_match('/^(?:Invoice|Bill|Refund|Credit\s*Note)/i', $next)) {
                        return $next;
                    }
                }
            }
        }

        // For templates where partner name appears after company contact info (Email/Phone)
        $afterContact = false;
        foreach ($lines as $line) {
            if (preg_match('/(?:Email|Phone):/i', $line)) {
                $afterContact = true;

                continue;
            }

            if ($afterContact) {
                if (preg_match('/(?:Refund|Invoice|Bill|Credit|Due\s*Date|Product|Payment)/i', $line)) {
                    break;
                }

                $candidate = trim($line, " \t\n\r\0\x0B,");
                if (strlen($candidate) >= 3 && ! is_numeric($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function extractCurrencyCode(string $text): ?string
    {
        $currencies = ['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'CAD', 'AUD', 'INR', 'CNY'];

        foreach ($currencies as $curr) {
            if (preg_match('/\b'.$curr.'\b/i', $text)) {
                return strtoupper($curr);
            }
        }

        if (str_contains($text, 'Rs.') || str_contains($text, 'Rs ')) {
            return 'PKR';
        }
        if (str_contains($text, '$')) {
            return 'USD';
        }
        if (str_contains($text, '€')) {
            return 'EUR';
        }
        if (str_contains($text, '£')) {
            return 'GBP';
        }

        return null;
    }

    /**
     * @return array{
     *     subtotal: ?float,
     *     tax_amount: ?float,
     *     tax_rate: ?float,
     *     discount_amount: ?float,
     *     total_amount: ?float
     * }
     */
    private function extractFinancials(string $text): array
    {
        $currencyPrefix = '(?:\s*[-:\s]\s*)?(?:[A-Z]{3}|\$|€|£|Rs\.?)?\s*';

        $subtotal = $this->extractAmount($text, [
            '/Subtotal'.$currencyPrefix.'([0-9,]+(?:\.[0-9]{1,4})?)/i',
            '/Untaxed\s*Amount'.$currencyPrefix.'([0-9,]+(?:\.[0-9]{1,4})?)/i',
            '/Net\s*Amount'.$currencyPrefix.'([0-9,]+(?:\.[0-9]{1,4})?)/i',
        ]);

        $taxAmount = $this->extractAmount($text, [
            '/(?:Sales\s*Tax|GST|VAT|Tax)\s*(?:Amount)?'.$currencyPrefix.'([0-9,]+(?:\.[0-9]{1,4})?)/i',
        ]);

        $taxRate = null;
        if (preg_match('/(?:GST|VAT|Tax)\s*@?\s*([0-9]+(?:\.[0-9]+)?)\s*%/i', $text, $m)) {
            $taxRate = (float) $m[1];
        }

        $discount = $this->extractAmount($text, [
            '/Discount'.$currencyPrefix.'-?([0-9,]+(?:\.[0-9]{1,4})?)/i',
        ]);

        $total = $this->extractAmount($text, [
            '/\bGrand\s*Total'.$currencyPrefix.'([0-9,]+(?:\.[0-9]{1,4})?)/i',
            '/\bTotal\s*Amount'.$currencyPrefix.'([0-9,]+(?:\.[0-9]{1,4})?)/i',
            '/(?<!Sub)\bTotal'.$currencyPrefix.'([0-9,]+(?:\.[0-9]{1,4})?)/i',
        ]);

        return [
            'subtotal'        => $subtotal,
            'tax_amount'      => $taxAmount,
            'tax_rate'        => $taxRate,
            'discount_amount' => $discount,
            'total_amount'    => $total,
        ];
    }

    private function extractFsTag(string $text): ?string
    {
        if (preg_match('/(?:FS\s*Tag|FSTAG)[:\s#]*([A-Z0-9_\-]+)/i', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/\b(FS[-_][A-Z0-9_\-]+)\b/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractAmount(string $text, array $patterns): ?float
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $clean = str_replace(',', '', trim($m[1]));
                if (is_numeric($clean)) {
                    return (float) $clean;
                }
            }
        }

        return null;
    }

    private function extractRegex(string $text, string $pattern): ?string
    {
        if (preg_match($pattern, $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Best-effort line item extraction:
     * Only extracts lines when structured description, qty, unit_price, and amount exist clearly.
     * Never infers or fabricates lines.
     *
     * @return array<int, array{description: string, quantity: float, unit_price: float, amount: float}>
     */
    private function extractLineItemsBestEffort(array $lines): array
    {
        $lineItems = [];

        // Match table rows shaped like: Description Quantity Price Total (e.g. "Consulting Services 2 1500.00 3000.00")
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9\s\-_]{3,40})\s+([0-9]+(?:\.[0-9]{1,2})?)\s+([0-9,]+(?:\.[0-9]{1,4})?)\s+([0-9,]+(?:\.[0-9]{1,4})?)$/', $line, $m)) {
                $desc = trim($m[1]);
                $qty = (float) $m[2];
                $price = (float) str_replace(',', '', $m[3]);
                $amount = (float) str_replace(',', '', $m[4]);

                // Basic sanity check: qty * price == amount
                if ($qty > 0 && $price > 0 && abs(($qty * $price) - $amount) < 0.1) {
                    $lineItems[] = [
                        'description' => $desc,
                        'quantity'    => $qty,
                        'unit_price'  => $price,
                        'amount'      => $amount,
                    ];
                }
            }
        }

        return $lineItems;
    }

    /**
     * Verifies mathematical balance using Aureus accounting precision.
     */
    private function verifyMathBalance(array $financials, array $lineItems, float $rounding): bool
    {
        $subtotal = $financials['subtotal'];
        $total = $financials['total_amount'];
        $tax = $financials['tax_amount'] ?? 0.0;
        $discount = $financials['discount_amount'] ?? 0.0;

        // If line items were extracted, verify sum(lines.amount) == subtotal
        if (! empty($lineItems) && $subtotal !== null) {
            $sumLines = array_sum(array_column($lineItems, 'amount'));
            if (function_exists('float_compare')) {
                if (float_compare($sumLines, $subtotal, precisionRounding: $rounding) !== 0) {
                    return false;
                }
            } elseif (abs($sumLines - $subtotal) > $rounding) {
                return false;
            }
        }

        // Verify subtotal + tax - discount == total
        if ($subtotal !== null && $total !== null) {
            $calculatedTotal = $subtotal + $tax - $discount;
            if (function_exists('float_compare')) {
                return float_compare($calculatedTotal, $total, precisionRounding: $rounding) === 0;
            }

            return abs($calculatedTotal - $total) <= $rounding;
        }

        return true;
    }

    private function emptyCandidate(bool $isScanned = false, bool $isMalformed = false): array
    {
        return [
            'raw_text'                => '',
            'is_scanned'              => $isScanned,
            'is_malformed'            => $isMalformed,
            'document_type_candidate' => null,
            'invoice_number'          => null,
            'invoice_date'            => null,
            'due_date'                => null,
            'partner_name'            => null,
            'tax_id'                  => null,
            'strn'                    => null,
            'currency_code'           => null,
            'subtotal'                => null,
            'tax_amount'              => null,
            'tax_rate'                => null,
            'discount_amount'         => null,
            'total_amount'            => null,
            'fs_tag_code'             => null,
            'line_items'              => [],
            'math_balanced'           => false,
        ];
    }
}
