<?php

namespace App\ColdOutreach;

use App\Enums\ColdContactStage;
use App\Models\ColdContact;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use SplFileObject;

/**
 * Imports a CSV export from any cold-email database (Apollo, ZoomInfo, Clay, a spreadsheet…) into the
 * site's pipeline as `Imported` contacts. Headers are matched loosely ("Email", "Work Email",
 * "First Name", "Company Name"…); every other column is kept in `custom` and forwarded to Instantly as
 * a personalization variable. A contact already in the site's pipeline is left untouched (its stage and
 * history are never reset by a re-import). Rows without a syntactically valid email are skipped here,
 * so they are never sent to Bird (each lookup is billed).
 */
class ContactImporter
{
    /** @var array<string, list<string>> canonical field => accepted header spellings (normalised) */
    private const ALIASES = [
        'email' => ['email', 'emailaddress', 'workemail', 'businessemail', 'contactemail', 'primaryemail', 'e-mail', 'mail'],
        'first_name' => ['firstname', 'first', 'givenname', 'fname'],
        'last_name' => ['lastname', 'last', 'surname', 'familyname', 'lname'],
        'full_name' => ['name', 'fullname', 'contactname'],
        'company' => ['company', 'companyname', 'organization', 'organisation', 'account', 'accountname', 'business', 'businessname'],
        'job_title' => ['title', 'jobtitle', 'position', 'role'],
        'phone' => ['phone', 'phonenumber', 'mobile', 'mobilephone', 'directphone', 'workphone'],
        'website' => ['website', 'companywebsite', 'domain', 'companydomain', 'url', 'websiteurl'],
    ];

    public function importFile(Site $site, string $path, ?string $source = null): ImportResult
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $rows = [];
        foreach ($file as $row) {
            if (is_array($row) && $row !== [null]) {
                $rows[] = array_map(fn ($v) => is_string($v) ? $v : '', $row);
            }
        }

        return $this->importRows($site, $rows, $source ?? basename($path));
    }

    /**
     * @param  list<list<string>>  $rows  first row is the header
     */
    public function importRows(Site $site, array $rows, ?string $source = null): ImportResult
    {
        $header = array_shift($rows);
        if ($header === null) {
            return new ImportResult(errors: ['The file is empty.']);
        }

        $map = $this->mapHeader($header);
        if (! isset($map['email'])) {
            return new ImportResult(errors: ['No email column found. Name it "Email" (or "Work Email").']);
        }

        $existing = array_flip(ColdContact::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->pluck('email_key')->map('strval')->all());

        $created = $duplicates = $invalid = 0;
        foreach ($rows as $row) {
            $email = trim($row[$map['email']] ?? '');
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $invalid++;

                continue;
            }

            $key = ColdContact::keyFor($email);
            if (isset($existing[$key])) {
                $duplicates++;

                continue;
            }

            $fields = $this->fields($header, $map, $row);
            ColdContact::withoutGlobalScope(SiteScope::class)->create($fields + [
                'site_id' => $site->id,
                'email' => $email,
                'email_key' => $key,
                'source' => $source,
                'stage' => ColdContactStage::Imported,
            ]);
            $existing[$key] = true;
            $created++;
        }

        return new ImportResult($created, $duplicates, $invalid);
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $label) {
            $norm = preg_replace('/[^a-z0-9-]/', '', strtolower(trim(ltrim($label, "\xEF\xBB\xBF")))) ?? '';
            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($map[$field]) && in_array($norm, $aliases, true)) {
                    $map[$field] = $i;
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $header
     * @param  array<string, int>  $map
     * @param  list<string>  $row
     * @return array<string, mixed>
     */
    private function fields(array $header, array $map, array $row): array
    {
        $get = fn (string $field): ?string => isset($map[$field]) && trim($row[$map[$field]] ?? '') !== ''
            ? mb_substr(trim($row[$map[$field]]), 0, 255) : null;

        $first = $get('first_name');
        $last = $get('last_name');
        if ($first === null && $last === null && ($full = $get('full_name')) !== null) {
            [$first, $last] = array_pad(explode(' ', $full, 2), 2, null);
        }

        $custom = [];
        $mapped = array_flip($map);
        foreach ($header as $i => $label) {
            $value = trim($row[$i] ?? '');
            if (! isset($mapped[$i]) && $value !== '' && trim($label) !== '') {
                $custom[$this->variableName($label)] = mb_substr($value, 0, 500);
            }
        }

        return [
            'first_name' => $first,
            'last_name' => $last,
            'company' => $get('company'),
            'job_title' => $get('job_title'),
            'phone' => $get('phone'),
            'website' => $get('website'),
            'custom' => $custom === [] ? null : $custom,
        ];
    }

    /** "Company Industry" → "companyIndustry" (Instantly custom variable naming). */
    private function variableName(string $label): string
    {
        $words = preg_split('/[^a-zA-Z0-9]+/', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: ['field'];
        $first = strtolower(array_shift($words));

        return $first.implode('', array_map(fn ($w) => ucfirst(strtolower($w)), $words));
    }
}
