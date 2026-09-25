<?php
// Invoice Ninja settings of the Docker stack (run by setup.sh as www-data).
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
if ($mode === 'accounts') {
    echo App\Models\Account::count();
    exit(0);
}
if ($mode !== 'settings') {
    fwrite(STDERR, "Usage: configure.php accounts|settings\n");
    exit(2);
}

// Initial company settings, once: while the company still has the values
// `ninja:create-account` gives it (name "Untitled Company", currency 1 =
// USD). Later changes in the app are kept.
function env_or(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

// Exact (binary) match: MySQL's collation ignores case, and date formats
// differ only by case ("d/m/Y" vs "d/M/Y").
function lookup(string $model, string $column, string $value): string
{
    $id = $model::whereRaw("BINARY `{$column}` = ?", [$value])->value('id');
    if ($id === null) {
        fwrite(STDERR, "{$model} with {$column} = {$value} not found\n");
        exit(1);
    }
    return (string) $id;
}

$company = App\Models\Company::orderBy('id')->first();

// Every run: the client portal's address. `ninja:create-account` stores the
// APP_URL of that moment in portal_domain, which links in emails use, so
// a URL change wouldn't reach them.
$portal = rtrim(env_or('NINJA_PORTAL_URL', env_or('APP_URL')), '/');
foreach (App\Models\Company::all() as $each) {
    if ($each->portal_domain !== $portal) {
        $each->portal_domain = $portal;
        $each->save();
        echo "Client portal address: {$portal}\n";
    }
}

$settings = $company->settings;
if ($settings->name !== 'Untitled Company' || (string) $settings->currency_id !== '1') {
    echo "Company settings already set (kept)\n";
    exit(0);
}

$currency = strtoupper(env_or('NINJA_CURRENCY', 'CLP'));
$country = strtoupper(env_or('NINJA_COUNTRY', 'CL'));
$language = env_or('NINJA_LANGUAGE', 'es_ES');
echo "Initial company settings ({$country}, {$currency}, {$language})\n";

$settings->name = env_or('NINJA_COMPANY_NAME', 'Invoice Ninja');
$settings->currency_id = lookup(App\Models\Currency::class, 'code', $currency);
$settings->country_id = lookup(App\Models\Country::class, 'iso_3166_2', $country);
$settings->language_id = lookup(App\Models\Language::class, 'locale', $language);
$settings->timezone_id = lookup(App\Models\Timezone::class, 'name', env_or('TZ', 'America/Santiago'));
$settings->date_format_id = lookup(App\Models\DateFormat::class, 'format', env_or('NINJA_DATE_FORMAT', 'd/m/Y'));
$settings->military_time = true;
$settings->inclusive_taxes = env_or('NINJA_PRICES_INCLUDE_TAX', 'false') === 'true';

$rate = env_or('NINJA_TAX_RATE');
if ($rate !== '') {
    $name = env_or('NINJA_TAX_NAME', 'IVA');
    // company_id and user_id aren't mass-assignable: set them one by one.
    if (!App\Models\TaxRate::where('company_id', $company->id)->where('name', $name)->exists()) {
        $tax = new App\Models\TaxRate();
        $tax->company_id = $company->id;
        $tax->user_id = $company->owner()->id;
        $tax->name = $name;
        $tax->rate = (float) $rate;
        $tax->save();
    }
    // Default tax of new invoices, quotes and credits.
    $settings->tax_name1 = $name;
    $settings->tax_rate1 = (float) $rate;
    $company->enabled_tax_rates = 1;
}

$company->settings = $settings;
$company->save();
echo "Company settings OK\n";
