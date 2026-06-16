<?php
/**
 * DocumentTextExtractor — Extract readable text from encrypted vendor documents.
 *
 * Supports PDF (via pdftotext), CSV (direct read), XLSX (ZipArchive + SimpleXML),
 * and XLS (binary string scan with CSV fallback).
 *
 * Author: Tim Rice
 */

class DocumentTextExtractor
{
    /**
     * Supported MIME types for text extraction.
     */
    public const SUPPORTED_MIME_TYPES = [
        'application/pdf',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Extract readable text from decrypted document data.
     *
     * @param string $decryptedData  Raw binary file content
     * @param string $mimeType       MIME type of the document
     * @param int    $maxChars       Maximum characters to return
     * @return string|null           Extracted text, or null on failure
     */
    public static function extract(string $decryptedData, string $mimeType, int $maxChars = 8000): ?string
    {
        if (empty($decryptedData)) {
            return null;
        }

        $text = null;

        switch ($mimeType) {
            case 'application/pdf':
                $text = self::extractPdf($decryptedData, $maxChars);
                break;

            case 'text/csv':
                $text = self::extractCsv($decryptedData, $maxChars);
                break;

            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                $text = self::extractXlsx($decryptedData, $maxChars);
                break;

            case 'application/vnd.ms-excel':
                $text = self::extractXls($decryptedData, $maxChars);
                break;
        }

        if ($text === null) {
            return null;
        }

        // Normalize whitespace and trim
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = mb_substr(trim($text), 0, $maxChars);

        return mb_strlen($text) >= 50 ? $text : null;
    }

    /**
     * Extract text from PDF using pdftotext CLI.
     */
    private static function extractPdf(string $data, int $maxChars): ?string
    {
        $tmpFile = null;
        $tmpOut = null;
        try {
            $tmpFile = tempnam(sys_get_temp_dir(), 'dte_');
            file_put_contents($tmpFile, $data);

            $tmpOut = $tmpFile . '.txt';
            $cmd = 'pdftotext -l 10 -enc UTF-8 '
                . escapeshellarg($tmpFile) . ' '
                . escapeshellarg($tmpOut) . ' 2>/dev/null';
            exec($cmd, $cmdOutput, $retCode);

            if ($retCode === 0 && file_exists($tmpOut)) {
                return file_get_contents($tmpOut);
            }
            return null;
        } catch (\Exception $e) {
            return null;
        } finally {
            if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
            if ($tmpOut && file_exists($tmpOut)) @unlink($tmpOut);
        }
    }

    /**
     * Extract text from CSV — read raw content directly.
     */
    private static function extractCsv(string $data, int $maxChars): ?string
    {
        // Handle BOM
        if (substr($data, 0, 3) === "\xEF\xBB\xBF") {
            $data = substr($data, 3);
        }

        // Parse CSV rows into readable tab-separated lines
        $lines = [];
        $rows = str_getcsv_rows($data);
        if (empty($rows)) {
            // Fallback: return raw text
            return mb_substr($data, 0, $maxChars);
        }

        foreach ($rows as $row) {
            $lines[] = implode("\t", $row);
        }

        return implode("\n", $lines);
    }

    /**
     * Extract text from XLSX using ZipArchive + SimpleXML.
     * XLSX is a ZIP containing XML files — no external dependencies needed.
     */
    private static function extractXlsx(string $data, int $maxChars): ?string
    {
        $tmpFile = null;
        try {
            $tmpFile = tempnam(sys_get_temp_dir(), 'dte_') . '.xlsx';
            file_put_contents($tmpFile, $data);

            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                return null;
            }

            // Read shared strings table (XLSX stores cell text here)
            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml) {
                $ss = @simplexml_load_string($ssXml);
                if ($ss) {
                    foreach ($ss->si as $si) {
                        // Handle both simple <t> and rich-text <r><t> elements
                        $text = '';
                        if (isset($si->t)) {
                            $text = (string)$si->t;
                        } elseif (isset($si->r)) {
                            foreach ($si->r as $r) {
                                $text .= (string)$r->t;
                            }
                        }
                        $sharedStrings[] = $text;
                    }
                }
            }

            // Read worksheets — iterate sheets until we have enough text
            $allLines = [];
            $totalChars = 0;
            for ($sheetNum = 1; $sheetNum <= 10; $sheetNum++) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$sheetNum}.xml");
                if (!$sheetXml) break;

                $sheet = @simplexml_load_string($sheetXml);
                if (!$sheet || !isset($sheet->sheetData->row)) continue;

                foreach ($sheet->sheetData->row as $row) {
                    $cells = [];
                    foreach ($row->c as $cell) {
                        $cellValue = '';
                        $type = (string)($cell['t'] ?? '');
                        $value = (string)($cell->v ?? '');

                        if ($type === 's' && isset($sharedStrings[(int)$value])) {
                            // Shared string reference
                            $cellValue = $sharedStrings[(int)$value];
                        } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                            // Inline string
                            $cellValue = (string)$cell->is->t;
                        } else {
                            $cellValue = $value;
                        }

                        if ($cellValue !== '') {
                            $cells[] = $cellValue;
                        }
                    }
                    if (!empty($cells)) {
                        $line = implode("\t", $cells);
                        $allLines[] = $line;
                        $totalChars += mb_strlen($line) + 1;
                        if ($totalChars > $maxChars) break 2;
                    }
                }
            }

            $zip->close();

            return !empty($allLines) ? implode("\n", $allLines) : null;
        } catch (\Exception $e) {
            return null;
        } finally {
            if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
        }
    }

    /**
     * Extract text from XLS (legacy BIFF format).
     * Uses binary string scanning — extracts readable text sequences.
     * Falls back to CSV parsing if the file is actually a CSV with .xls extension.
     */
    private static function extractXls(string $data, int $maxChars): ?string
    {
        // Check if this is actually a CSV file mislabeled as .xls (very common)
        $header = substr($data, 0, 8);
        $isBiff = ($header === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"); // OLE2 magic bytes

        if (!$isBiff) {
            // Likely a CSV or tab-delimited file with .xls extension
            return self::extractCsv($data, $maxChars);
        }

        // BIFF/OLE2 binary — scan for readable text sequences
        // BIFF stores strings as length-prefixed Unicode or ASCII sequences
        $strings = [];
        $dataLen = strlen($data);
        $totalChars = 0;

        // Scan for SST (Shared String Table) record — type 0x00FC
        // SST strings contain the actual cell text in BIFF8
        $offset = 0;
        while ($offset < $dataLen - 4) {
            $recordType = ord($data[$offset]) | (ord($data[$offset + 1]) << 8);
            $recordLen = ord($data[$offset + 2]) | (ord($data[$offset + 3]) << 8);

            if ($recordType === 0x00FC && $recordLen > 8) {
                // SST record found — parse strings from it
                $sstData = substr($data, $offset + 4, $recordLen);
                $sstStrings = self::parseSstStrings($sstData);
                foreach ($sstStrings as $s) {
                    $s = trim($s);
                    if (mb_strlen($s) >= 2) {
                        $strings[] = $s;
                        $totalChars += mb_strlen($s) + 1;
                        if ($totalChars > $maxChars) break 2;
                    }
                }
            }

            $offset += 4 + $recordLen;
            if ($recordLen === 0) $offset += 1; // Prevent infinite loop
        }

        // If SST parsing didn't yield much, do a brute-force readable text scan
        if ($totalChars < 100) {
            $strings = [];
            // Match sequences of 4+ printable ASCII/UTF-8 characters
            preg_match_all('/[\x20-\x7E]{4,}/', $data, $matches);
            if (!empty($matches[0])) {
                $seen = [];
                foreach ($matches[0] as $m) {
                    $m = trim($m);
                    if (mb_strlen($m) >= 4 && !isset($seen[$m])) {
                        $seen[$m] = true;
                        $strings[] = $m;
                    }
                }
            }
        }

        return !empty($strings) ? implode("\n", $strings) : null;
    }

    /**
     * Parse strings from a BIFF8 SST (Shared String Table) record.
     */
    private static function parseSstStrings(string $sstData): array
    {
        $strings = [];
        $len = strlen($sstData);
        if ($len < 8) return $strings;

        // First 8 bytes: total strings (4 bytes) + unique strings (4 bytes)
        $uniqueCount = ord($sstData[4]) | (ord($sstData[5]) << 8)
            | (ord($sstData[6]) << 16) | (ord($sstData[7]) << 24);
        $offset = 8;

        for ($i = 0; $i < $uniqueCount && $offset < $len - 3; $i++) {
            // String length (2 bytes) + option flags (1 byte)
            $charCount = ord($sstData[$offset]) | (ord($sstData[$offset + 1]) << 8);
            $flags = ord($sstData[$offset + 2]);
            $offset += 3;

            $isWide = ($flags & 0x01) !== 0;       // 16-bit characters
            $hasExtRst = ($flags & 0x04) !== 0;     // Extended string
            $hasRichText = ($flags & 0x08) !== 0;   // Rich text

            // Skip rich text run count
            if ($hasRichText && $offset + 2 <= $len) {
                $offset += 2;
            }
            // Skip extended string size
            if ($hasExtRst && $offset + 4 <= $len) {
                $offset += 4;
            }

            if ($isWide) {
                $byteLen = $charCount * 2;
                if ($offset + $byteLen > $len) break;
                $raw = substr($sstData, $offset, $byteLen);
                $str = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
                $offset += $byteLen;
            } else {
                if ($offset + $charCount > $len) break;
                $str = substr($sstData, $offset, $charCount);
                $offset += $charCount;
            }

            if ($str !== false && $str !== '') {
                $strings[] = $str;
            }
        }

        return $strings;
    }
}

/**
 * Parse a CSV string into an array of rows.
 * Each row is an array of field values.
 */
function str_getcsv_rows(string $csvString): array
{
    $rows = [];
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csvString);
    rewind($stream);
    while (($row = fgetcsv($stream)) !== false) {
        $rows[] = $row;
    }
    fclose($stream);
    return $rows;
}
