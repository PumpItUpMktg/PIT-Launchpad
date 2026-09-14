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
        // A UTF-8 BOM (Excel, Sheets exports) would otherwise become part of the first header's name.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $reader = CsvReader::createFromString($contents);

        // Headers are read by hand: a Sheets/Excel export routinely carries blank trailing columns or repeated
        // names, which league/csv's header mode rejects outright. Blank headers are dropped; repeats are
        // suffixed so every column stays addressable in the mapping step.
        $headers = null;
        $rows = [];
        foreach ($reader->getRecords() as $record) {
            $cells = array_map(fn ($v): string => trim((string) $v), array_values($record));
            if ($headers === null) {
                $headers = $this->uniqueHeaders($cells);

                continue;
            }
            if (implode('', $cells) === '') {
                continue; // a fully blank line
            }
            $assoc = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $assoc[$header] = $cells[$i] ?? '';
                }
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    /**
     * Blank headers stay '' (skipped when mapping cells); duplicates become "name (2)", "name (3)".
     *
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function uniqueHeaders(array $cells): array
    {
        $seen = [];
        $headers = [];
        foreach ($cells as $cell) {
            if ($cell === '') {
                $headers[] = '';

                continue;
            }
            $name = $cell;
            for ($n = 2; isset($seen[$name]); $n++) {
                $name = "{$cell} ({$n})";
            }
            $seen[$name] = true;
            $headers[] = $name;
        }

        return $headers;
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
                    $headers = $this->uniqueHeaders($cells);

                    continue;
                }
                if (implode('', $cells) === '') {
                    continue; // a fully blank line
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
