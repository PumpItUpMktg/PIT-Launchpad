<?php

use App\ColdOutreach\ContactImporter;
use App\Enums\ColdContactStage;
use App\Models\ColdContact;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

function coldContacts(Site $site)
{
    return ColdContact::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
}

it('imports a cold-email CSV with loose headers, keeping extra columns as variables', function () {
    $site = Site::factory()->create();

    $result = app(ContactImporter::class)->importRows($site, [
        ["\xEF\xBB\xBFWork Email", 'First Name', 'Last Name', 'Company Name', 'Title', 'Industry', 'Employee Count'],
        ['Dana.Smith@Acme.test', 'Dana', 'Smith', 'Acme HVAC', 'Owner', 'HVAC', '12'],
        ['not-an-email', 'X', 'Y', 'Z', '', '', ''],
        ['', 'No', 'Email', '', '', '', ''],
        ['dana.smith@acme.test', 'Dup', '', '', '', '', ''],
    ], 'Apollo — NJ');

    expect($result->created)->toBe(1)->and($result->invalid)->toBe(2)->and($result->duplicates)->toBe(1);

    $contact = coldContacts($site)->sole();
    expect($contact->email)->toBe('Dana.Smith@Acme.test') // case kept for Bird
        ->and($contact->email_key)->toBe('dana.smith@acme.test')
        ->and($contact->first_name)->toBe('Dana')
        ->and($contact->company)->toBe('Acme HVAC')
        ->and($contact->job_title)->toBe('Owner')
        ->and($contact->custom)->toBe(['industry' => 'HVAC', 'employeeCount' => '12'])
        ->and($contact->source)->toBe('Apollo — NJ')
        ->and($contact->stage)->toBe(ColdContactStage::Imported);
});

it('splits a single name column and never resets a contact already in the pipeline', function () {
    $site = Site::factory()->create();
    $importer = app(ContactImporter::class);
    $importer->importRows($site, [['Email', 'Name'], ['pat@x.test', 'Pat Lee Jones']]);
    coldContacts($site)->update(['stage' => ColdContactStage::Warming->value]);

    $again = $importer->importRows($site, [['Email', 'Name'], ['PAT@x.test', 'Other']]);

    $contact = coldContacts($site)->sole();
    expect($again->duplicates)->toBe(1)
        ->and($contact->first_name)->toBe('Pat')->and($contact->last_name)->toBe('Lee Jones')
        ->and($contact->stage)->toBe(ColdContactStage::Warming);
});

it('dedupes per site, so two tenants can hold the same address', function () {
    [$a, $b] = [Site::factory()->create(), Site::factory()->create()];
    $rows = [['email'], ['same@x.test']];

    app(ContactImporter::class)->importRows($a, $rows);
    app(ContactImporter::class)->importRows($b, $rows);

    expect(coldContacts($a)->count())->toBe(1)->and(coldContacts($b)->count())->toBe(1);
});

it('refuses a file with no email column', function () {
    $result = app(ContactImporter::class)->importRows(Site::factory()->create(), [['Name', 'Phone'], ['A', '1']]);

    expect($result->errors)->not->toBeEmpty()->and($result->created)->toBe(0);
});

it('imports from a file via the artisan command', function () {
    $site = Site::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'cold').'.csv';
    file_put_contents($path, "Email,First Name\none@x.test,One\ntwo@x.test,Two\n");

    $this->artisan('launchpad:cold-import', ['site' => $site->id, 'file' => $path])->assertSuccessful();

    expect(coldContacts($site)->count())->toBe(2);
    @unlink($path);
});
