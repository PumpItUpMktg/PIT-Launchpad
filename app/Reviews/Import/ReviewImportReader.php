<?php

namespace App\Reviews\Import;

use DateTimeInterface;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads a bulk-review source into normalized header-keyed rows (Review Capture §10) — one shape regardless of
 * whether it came from a CSV, an XLSX, or a Google Sheet. A Google Sheet URL is fetched via its CSV export
 * endpoint (with explicit timeouts — this app must add no request-path hang). Parsing runs on the worker (the
 * caller is a queued job), so a 5,000-row file never touches a web request.
 */
final class ReviewImportReader
{
    /**
     * @return list<array<string, string>>
     */
    public function csv(string $contents): array
    {
        $reader = CsvReader::createFromString($contents);
        $reader->setHeaderOffset(0);

        $rows = [];
        foreach ($reader->getRecords() as $record) {
            $rows[] = array_map(fn ($v): string => trim((string) $v), $record);
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    public function xlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $headers = null;
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map($this->stringify(...), $row->toArray());
                if ($headers === null) {
                    $headers = $cells;

                    continue;
                }
                $assoc = [];
                foreach ($headers as $i => $header) {
                    if ($header !== '') {
                        $assoc[$header] = $cells[$i] ?? '';
                    }
                }
                $rows[] = $assoc;
            }
            break; // first sheet only
        }
        $reader->close();

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    public function googleSheet(string $url): array
    {
        $response = Http::timeout(20)->connectTimeout(5)->get($this->toCsvExportUrl($url));
        $response->throw();

        return $this->csv($response->body());
    }

    /** Turn a Google Sheets share/edit URL into its CSV export endpoint. */
    public function toCsvExportUrl(string $url): string
    {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m) === 1) {
            $gid = preg_match('~[?&#]gid=(\d+)~', $url, $g) === 1 ? $g[1] : '0';

            return 'https://docs.google.com/spreadsheets/d/'.$m[1].'/export?format=csv&gid='.$gid;
        }

        return $url; // already a direct CSV URL
    }

    private function stringify(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }
}
