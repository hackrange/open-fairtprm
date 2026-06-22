<?php
/**
 * Contact field helpers — Phone and VAT question/field types.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Centralises everything the "phone" and "vat" input widgets need so that the
 * assessment Template Builder renderer (vendor-assessment.php) and the Vendor
 * Onboarding Request form (vendor-onboarding.php) share ONE implementation:
 *
 *   - Phone: an international dialling-code selector (with flag) + a national
 *     number box. Whatever the user types — "314-444-5544", "(314) 444-5544",
 *     "3144445544" — is normalised to canonical E.164 ("+13144445544"). The
 *     United States is pinned first; every other country follows alphabetically.
 *
 *   - VAT: an EU-format VAT number entered twice (to catch typos), optionally
 *     verified live against the official EU VIES service. Stored uppercased and
 *     stripped of spaces/punctuation (e.g. "DE123456789").
 *
 * Normalisation is implemented server-side here as well as client-side in JS, so
 * the stored value is always canonical even if the browser is doing something we
 * did not expect.
 */

if (!function_exists('e')) {
    // contact-fields.php is always loaded after includes/init.php, which defines
    // e()/htmlspecialchars helpers. This guard only matters for isolated tooling.
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Master phone country list. Each entry: iso (ISO-3166-1 alpha-2), dial (digits
 * only, no '+'), name. Order here is irrelevant — phone_countries() pins the US
 * first and alphabetises the rest by name.
 */
function phone_country_data() {
    return [
        ['US', '1',   'United States'],
        ['AF', '93',  'Afghanistan'],
        ['AL', '355', 'Albania'],
        ['DZ', '213', 'Algeria'],
        ['AD', '376', 'Andorra'],
        ['AO', '244', 'Angola'],
        ['AG', '1',   'Antigua and Barbuda'],
        ['AR', '54',  'Argentina'],
        ['AM', '374', 'Armenia'],
        ['AU', '61',  'Australia'],
        ['AT', '43',  'Austria'],
        ['AZ', '994', 'Azerbaijan'],
        ['BS', '1',   'Bahamas'],
        ['BH', '973', 'Bahrain'],
        ['BD', '880', 'Bangladesh'],
        ['BB', '1',   'Barbados'],
        ['BY', '375', 'Belarus'],
        ['BE', '32',  'Belgium'],
        ['BZ', '501', 'Belize'],
        ['BJ', '229', 'Benin'],
        ['BT', '975', 'Bhutan'],
        ['BO', '591', 'Bolivia'],
        ['BA', '387', 'Bosnia and Herzegovina'],
        ['BW', '267', 'Botswana'],
        ['BR', '55',  'Brazil'],
        ['BN', '673', 'Brunei'],
        ['BG', '359', 'Bulgaria'],
        ['BF', '226', 'Burkina Faso'],
        ['BI', '257', 'Burundi'],
        ['KH', '855', 'Cambodia'],
        ['CM', '237', 'Cameroon'],
        ['CA', '1',   'Canada'],
        ['CV', '238', 'Cape Verde'],
        ['CF', '236', 'Central African Republic'],
        ['TD', '235', 'Chad'],
        ['CL', '56',  'Chile'],
        ['CN', '86',  'China'],
        ['CO', '57',  'Colombia'],
        ['KM', '269', 'Comoros'],
        ['CG', '242', 'Congo'],
        ['CD', '243', 'Congo (DRC)'],
        ['CR', '506', 'Costa Rica'],
        ['CI', '225', "Cote d'Ivoire"],
        ['HR', '385', 'Croatia'],
        ['CU', '53',  'Cuba'],
        ['CY', '357', 'Cyprus'],
        ['CZ', '420', 'Czech Republic'],
        ['DK', '45',  'Denmark'],
        ['DJ', '253', 'Djibouti'],
        ['DM', '1',   'Dominica'],
        ['DO', '1',   'Dominican Republic'],
        ['EC', '593', 'Ecuador'],
        ['EG', '20',  'Egypt'],
        ['SV', '503', 'El Salvador'],
        ['GQ', '240', 'Equatorial Guinea'],
        ['ER', '291', 'Eritrea'],
        ['EE', '372', 'Estonia'],
        ['SZ', '268', 'Eswatini'],
        ['ET', '251', 'Ethiopia'],
        ['FJ', '679', 'Fiji'],
        ['FI', '358', 'Finland'],
        ['FR', '33',  'France'],
        ['GA', '241', 'Gabon'],
        ['GM', '220', 'Gambia'],
        ['GE', '995', 'Georgia'],
        ['DE', '49',  'Germany'],
        ['GH', '233', 'Ghana'],
        ['GR', '30',  'Greece'],
        ['GD', '1',   'Grenada'],
        ['GT', '502', 'Guatemala'],
        ['GN', '224', 'Guinea'],
        ['GW', '245', 'Guinea-Bissau'],
        ['GY', '592', 'Guyana'],
        ['HT', '509', 'Haiti'],
        ['HN', '504', 'Honduras'],
        ['HK', '852', 'Hong Kong'],
        ['HU', '36',  'Hungary'],
        ['IS', '354', 'Iceland'],
        ['IN', '91',  'India'],
        ['ID', '62',  'Indonesia'],
        ['IR', '98',  'Iran'],
        ['IQ', '964', 'Iraq'],
        ['IE', '353', 'Ireland'],
        ['IL', '972', 'Israel'],
        ['IT', '39',  'Italy'],
        ['JM', '1',   'Jamaica'],
        ['JP', '81',  'Japan'],
        ['JO', '962', 'Jordan'],
        ['KZ', '7',   'Kazakhstan'],
        ['KE', '254', 'Kenya'],
        ['KI', '686', 'Kiribati'],
        ['KW', '965', 'Kuwait'],
        ['KG', '996', 'Kyrgyzstan'],
        ['LA', '856', 'Laos'],
        ['LV', '371', 'Latvia'],
        ['LB', '961', 'Lebanon'],
        ['LS', '266', 'Lesotho'],
        ['LR', '231', 'Liberia'],
        ['LY', '218', 'Libya'],
        ['LI', '423', 'Liechtenstein'],
        ['LT', '370', 'Lithuania'],
        ['LU', '352', 'Luxembourg'],
        ['MO', '853', 'Macau'],
        ['MG', '261', 'Madagascar'],
        ['MW', '265', 'Malawi'],
        ['MY', '60',  'Malaysia'],
        ['MV', '960', 'Maldives'],
        ['ML', '223', 'Mali'],
        ['MT', '356', 'Malta'],
        ['MH', '692', 'Marshall Islands'],
        ['MR', '222', 'Mauritania'],
        ['MU', '230', 'Mauritius'],
        ['MX', '52',  'Mexico'],
        ['FM', '691', 'Micronesia'],
        ['MD', '373', 'Moldova'],
        ['MC', '377', 'Monaco'],
        ['MN', '976', 'Mongolia'],
        ['ME', '382', 'Montenegro'],
        ['MA', '212', 'Morocco'],
        ['MZ', '258', 'Mozambique'],
        ['MM', '95',  'Myanmar'],
        ['NA', '264', 'Namibia'],
        ['NR', '674', 'Nauru'],
        ['NP', '977', 'Nepal'],
        ['NL', '31',  'Netherlands'],
        ['NZ', '64',  'New Zealand'],
        ['NI', '505', 'Nicaragua'],
        ['NE', '227', 'Niger'],
        ['NG', '234', 'Nigeria'],
        ['KP', '850', 'North Korea'],
        ['MK', '389', 'North Macedonia'],
        ['NO', '47',  'Norway'],
        ['OM', '968', 'Oman'],
        ['PK', '92',  'Pakistan'],
        ['PW', '680', 'Palau'],
        ['PS', '970', 'Palestine'],
        ['PA', '507', 'Panama'],
        ['PG', '675', 'Papua New Guinea'],
        ['PY', '595', 'Paraguay'],
        ['PE', '51',  'Peru'],
        ['PH', '63',  'Philippines'],
        ['PL', '48',  'Poland'],
        ['PT', '351', 'Portugal'],
        ['QA', '974', 'Qatar'],
        ['RO', '40',  'Romania'],
        ['RU', '7',   'Russia'],
        ['RW', '250', 'Rwanda'],
        ['KN', '1',   'Saint Kitts and Nevis'],
        ['LC', '1',   'Saint Lucia'],
        ['VC', '1',   'Saint Vincent and the Grenadines'],
        ['WS', '685', 'Samoa'],
        ['SM', '378', 'San Marino'],
        ['ST', '239', 'Sao Tome and Principe'],
        ['SA', '966', 'Saudi Arabia'],
        ['SN', '221', 'Senegal'],
        ['RS', '381', 'Serbia'],
        ['SC', '248', 'Seychelles'],
        ['SL', '232', 'Sierra Leone'],
        ['SG', '65',  'Singapore'],
        ['SK', '421', 'Slovakia'],
        ['SI', '386', 'Slovenia'],
        ['SB', '677', 'Solomon Islands'],
        ['SO', '252', 'Somalia'],
        ['ZA', '27',  'South Africa'],
        ['KR', '82',  'South Korea'],
        ['SS', '211', 'South Sudan'],
        ['ES', '34',  'Spain'],
        ['LK', '94',  'Sri Lanka'],
        ['SD', '249', 'Sudan'],
        ['SR', '597', 'Suriname'],
        ['SE', '46',  'Sweden'],
        ['CH', '41',  'Switzerland'],
        ['SY', '963', 'Syria'],
        ['TW', '886', 'Taiwan'],
        ['TJ', '992', 'Tajikistan'],
        ['TZ', '255', 'Tanzania'],
        ['TH', '66',  'Thailand'],
        ['TL', '670', 'Timor-Leste'],
        ['TG', '228', 'Togo'],
        ['TO', '676', 'Tonga'],
        ['TT', '1',   'Trinidad and Tobago'],
        ['TN', '216', 'Tunisia'],
        ['TR', '90',  'Turkey'],
        ['TM', '993', 'Turkmenistan'],
        ['TV', '688', 'Tuvalu'],
        ['UG', '256', 'Uganda'],
        ['UA', '380', 'Ukraine'],
        ['AE', '971', 'United Arab Emirates'],
        ['GB', '44',  'United Kingdom'],
        ['UY', '598', 'Uruguay'],
        ['UZ', '998', 'Uzbekistan'],
        ['VU', '678', 'Vanuatu'],
        ['VA', '379', 'Vatican City'],
        ['VE', '58',  'Venezuela'],
        ['VN', '84',  'Vietnam'],
        ['YE', '967', 'Yemen'],
        ['ZM', '260', 'Zambia'],
        ['ZW', '263', 'Zimbabwe'],
    ];
}

/**
 * Phone countries as ['iso'=>, 'dial'=>, 'name'=>], United States first, the
 * remainder alphabetical by name. Cached for the request.
 */
function phone_countries() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $rows = phone_country_data();
    $us = null;
    $rest = [];
    foreach ($rows as $r) {
        $entry = ['iso' => $r[0], 'dial' => $r[1], 'name' => $r[2]];
        if ($r[0] === 'US') { $us = $entry; }
        else { $rest[] = $entry; }
    }
    usort($rest, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    $cache = $us ? array_merge([$us], $rest) : $rest;
    return $cache;
}

/**
 * Flag emoji for an ISO-3166-1 alpha-2 code, returned as HTML numeric character
 * references (e.g. "US" -> regional-indicator U + S). Using entities sidesteps
 * any source-file encoding worries while still rendering a real flag in modern
 * browsers; non-supporting platforms degrade to the two-letter code.
 */
function phone_flag_entities($iso) {
    $iso = strtoupper((string)$iso);
    if (!preg_match('/^[A-Z]{2}$/', $iso)) return e($iso);
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $cp = 0x1F1E6 + (ord($iso[$i]) - ord('A'));
        $out .= '&#' . $cp . ';';
    }
    return $out;
}

/**
 * Canonicalise a phone value to E.164 ("+<digits>"). Accepts anything the hidden
 * field might carry; keeps a single leading '+' and digits only. Returns '' for
 * an empty/zero-length result.
 */
function phone_normalize_e164($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '' || $digits === null) return '';
    return '+' . $digits;
}

/**
 * Given a stored E.164 value, work out which country it belongs to (longest
 * dialling-code prefix; ties resolve to the earliest country in display order,
 * which keeps "+1" mapped to the United States). Returns [isoOrNull, nationalDigits].
 */
function phone_split_e164($e164) {
    $digits = preg_replace('/\D+/', '', (string)$e164);
    if ($digits === '' || $digits === null) return [null, ''];
    $best = null; $bestLen = 0;
    foreach (phone_countries() as $c) {
        $dial = $c['dial'];
        $len = strlen($dial);
        if ($len > $bestLen && strncmp($digits, $dial, $len) === 0) {
            $best = $c; $bestLen = $len;
        }
    }
    if ($best === null) return [null, $digits];
    return [$best['iso'], substr($digits, $bestLen)];
}

/**
 * Self-hosted flag-image URL for an ISO-3166-1 alpha-2 code. Real SVG flags
 * (4:3, shown at 24x18) render on every platform -- unlike the flag *emoji*,
 * which Chrome/Windows never displays. Served from app/flags/ ('self', so no
 * external request and no air-gap/egress concern); the assets are the
 * MIT-licensed flag-icons set bundled into the image. A missing file simply
 * shows the styled empty box, so an unknown ISO degrades gracefully.
 */
function phone_flag_img_url($iso) {
    return 'app/flags/' . strtolower((string)$iso) . '.svg';
}

/**
 * The phone-widget stylesheet, emitted at most once per request (static guard).
 * Inline <style> is allowed by the CSP (style-src 'unsafe-inline'); keeping it
 * with the widget means every page that renders a phone field is styled without
 * having to remember to include a separate CSS file.
 */
function phone_widget_css() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>'
        . '.phone-widget-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}'
        . '.phone-cc{position:relative;}'
        . '.phone-cc-toggle{display:flex;align-items:center;gap:6px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;background:#fff;font-size:13px;line-height:1;cursor:pointer;min-width:96px;}'
        . '.phone-cc-toggle:focus{outline:2px solid #35a0a3;outline-offset:1px;}'
        . '.phone-cc-flag{width:24px;height:18px;border-radius:2px;background-size:cover;background-position:center;background-repeat:no-repeat;flex-shrink:0;box-shadow:0 0 0 1px rgba(0,0,0,0.08) inset;}'
        . '.phone-cc-dial{font-weight:500;}'
        . '.phone-cc-caret{margin-left:auto;color:#888;font-size:11px;}'
        . '.phone-cc-pop{position:absolute;z-index:1100;top:calc(100% + 4px);left:0;width:288px;max-width:80vw;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,0.18);display:none;}'
        . '.phone-cc.open .phone-cc-pop{display:block;}'
        . '.phone-cc-search{width:calc(100% - 16px);margin:8px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;box-sizing:border-box;}'
        . '.phone-cc-options{list-style:none;margin:0;padding:0 0 6px;max-height:240px;overflow-y:auto;}'
        . '.phone-cc-option{display:flex;align-items:center;gap:8px;padding:7px 10px;cursor:pointer;font-size:13px;}'
        . '.phone-cc-option:hover,.phone-cc-option.active{background:#eef6f6;}'
        . '.phone-cc-option.hidden{display:none;}'
        . '.phone-cc-option-name{flex:1;}'
        . '.phone-cc-option-dial{color:#888;}'
        . '</style>';
}

/**
 * Render the phone input widget.
 *
 * A custom country dropdown (button + searchable popup of flag rows) drives the
 * international dialling code, and a national-number input holds the rest; JS in
 * app/js/phone-input.js combines them into the canonical E.164 value carried by
 * the hidden input -- that hidden input is the ONLY thing submitted, so callers
 * and autosave are unaffected. The native <select> was replaced because flag
 * icons cannot render inside a native <option> on every platform.
 *
 * NOTE: the page MUST also load app/js/phone-input.js -- the dropdown is inert
 * without it. (vendor-assessment.php and vendor-onboarding.php already do.)
 *
 * @param string $name   The submitted field name (e.g. "primary_contact_phone"
 *                       or "responses[42]"). Applied to the hidden canonical input.
 * @param string $value  Current stored value (E.164 or anything; normalised here).
 * @param array  $opts   id, hidden_class, data_attrs (raw attr string for the
 *                       hidden input, already escaped), national_style.
 */
function phone_render_widget($name, $value, $opts = []) {
    $value = phone_normalize_e164($value);
    list($selIso, $national) = phone_split_e164($value);
    if ($selIso === null) $selIso = 'US';

    $id          = $opts['id'] ?? '';
    $hiddenClass = $opts['hidden_class'] ?? '';
    $dataAttrs   = $opts['data_attrs'] ?? '';
    $natStyle    = $opts['national_style'] ?? 'padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;flex:1;min-width:140px;';

    $countries = phone_countries();
    $selDial = '';
    foreach ($countries as $c) { if ($c['iso'] === $selIso) { $selDial = $c['dial']; break; } }

    $html  = phone_widget_css();
    $html .= '<div class="phone-widget" data-phone-widget>';
    $html .= '<div class="phone-widget-row">';

    // Custom country dropdown. The selected flag is set inline (one image) so it
    // shows before JS runs; the option-row flags are lazy-hydrated on first open.
    $html .= '<div class="phone-cc" data-phone-cc data-iso="' . e($selIso) . '" data-dial="' . e($selDial) . '">';
    $html .= '<button type="button" class="phone-cc-toggle" aria-haspopup="listbox" aria-expanded="false" aria-label="Country dialling code">';
    $html .= '<span class="phone-cc-flag" style="background-image:url(\'' . e(phone_flag_img_url($selIso)) . '\')"></span>';
    $html .= '<span class="phone-cc-dial">+' . e($selDial) . '</span>';
    $html .= '<span class="phone-cc-caret" aria-hidden="true">&#9662;</span>';
    $html .= '</button>';
    $html .= '<div class="phone-cc-pop">';
    $html .= '<input type="text" class="phone-cc-search" placeholder="Search country or code" autocomplete="off" aria-label="Search country">';
    $html .= '<ul class="phone-cc-options" role="listbox">';
    foreach ($countries as $c) {
        $iso = $c['iso']; $dial = $c['dial']; $nm = $c['name'];
        $active = ($iso === $selIso) ? ' active' : '';
        $search = strtolower($nm . ' ' . $iso . ' +' . $dial);
        $html .= '<li class="phone-cc-option' . $active . '" role="option"'
              . ' data-iso="' . e($iso) . '" data-dial="' . e($dial) . '"'
              . ' data-flag="' . e(phone_flag_img_url($iso)) . '" data-search="' . e($search) . '">'
              . '<span class="phone-cc-flag"></span>'
              . '<span class="phone-cc-option-name">' . e($nm) . '</span>'
              . '<span class="phone-cc-option-dial">+' . e($dial) . '</span>'
              . '</li>';
    }
    $html .= '</ul></div></div>';

    $natId = $id ? (' id="' . e($id) . '_national"') : '';
    $html .= '<input type="tel" class="phone-national" inputmode="tel" autocomplete="tel-national"'
          . $natId
          . ' placeholder="3144445544" value="' . e($national) . '" style="' . e($natStyle) . '">';
    $html .= '</div>';

    $idAttr = $id ? (' id="' . e($id) . '"') : '';
    $html .= '<input type="hidden" name="' . e($name) . '"' . $idAttr
          . ($hiddenClass ? (' class="' . e($hiddenClass) . '"') : '')
          . ($dataAttrs ? (' ' . $dataAttrs) : '')
          . ' value="' . e($value) . '">';

    $html .= '<span class="phone-validation-msg" style="display:none;font-size:12px;color:#ef4444;margin-top:4px;"></span>';
    $html .= '</div>';
    return $html;
}

/**
 * Format a stored E.164 value for display ("+1 3144445544"). Falls back to the
 * raw value when it cannot be split.
 */
function phone_display($value) {
    $value = phone_normalize_e164($value);
    if ($value === '') return '';
    list($iso, $national) = phone_split_e164($value);
    if ($iso === null) return $value;
    $dial = '';
    foreach (phone_countries() as $c) { if ($c['iso'] === $iso) { $dial = $c['dial']; break; } }
    return '+' . $dial . ' ' . $national;
}

/* ===================================================================
 * VAT
 * =================================================================== */

/**
 * EU (and VIES-recognised) VAT prefixes -> country name. Note the two prefixes
 * that differ from the ISO code: Greece uses "EL" and Northern Ireland "XI".
 */
function vat_eu_prefixes() {
    return [
        'AT' => 'Austria',          'BE' => 'Belgium',         'BG' => 'Bulgaria',
        'HR' => 'Croatia',          'CY' => 'Cyprus',          'CZ' => 'Czech Republic',
        'DK' => 'Denmark',          'EE' => 'Estonia',         'FI' => 'Finland',
        'FR' => 'France',           'DE' => 'Germany',         'EL' => 'Greece',
        'HU' => 'Hungary',          'IE' => 'Ireland',         'IT' => 'Italy',
        'LV' => 'Latvia',           'LT' => 'Lithuania',       'LU' => 'Luxembourg',
        'MT' => 'Malta',            'NL' => 'Netherlands',     'PL' => 'Poland',
        'PT' => 'Portugal',         'RO' => 'Romania',         'SK' => 'Slovakia',
        'SI' => 'Slovenia',         'ES' => 'Spain',           'SE' => 'Sweden',
        'XI' => 'Northern Ireland',
    ];
}

/**
 * Canonicalise a VAT number: uppercase, drop everything that is not A-Z/0-9.
 */
function vat_normalize($raw) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', (string)$raw));
}

/**
 * Lightweight EU VAT format check. Authoritative validity is left to VIES; this
 * only rejects the obviously-malformed (unknown prefix, wrong length/charset).
 * Returns ['ok'=>bool, 'country'=>2-letter prefix, 'number'=>rest, 'message'=>string].
 */
function vat_validate_format($raw) {
    $vat = vat_normalize($raw);
    if ($vat === '') {
        return ['ok' => false, 'country' => '', 'number' => '', 'message' => 'VAT number is empty'];
    }
    $prefix = substr($vat, 0, 2);
    $rest   = substr($vat, 2);
    if (!isset(vat_eu_prefixes()[$prefix])) {
        return ['ok' => false, 'country' => $prefix, 'number' => $rest,
                'message' => 'VAT number must start with a valid EU country code (e.g. DE, FR, IT)'];
    }
    if (!preg_match('/^[0-9A-Z]{2,13}$/', $rest)) {
        return ['ok' => false, 'country' => $prefix, 'number' => $rest,
                'message' => 'VAT number format looks invalid for ' . vat_eu_prefixes()[$prefix]];
    }
    return ['ok' => true, 'country' => $prefix, 'number' => $rest, 'message' => ''];
}

/**
 * Validate a VAT number against the official EU VIES REST service.
 *
 * VIES queries each member state's live registry (updated continuously by the
 * national tax authorities), is free and needs no API key. The host is a fixed
 * constant — no user-controlled URL — so there is no SSRF surface here.
 *
 * Returns:
 *   ['reachable'=>false]                              when VIES could not be contacted
 *   ['reachable'=>true,'valid'=>bool,'name'=>,'address'=>]  on a definitive answer
 *
 * This is ADVISORY: callers surface the result but never hard-block on it, since
 * VIES has outages and occasional false negatives.
 */
function vat_vies_check($raw) {
    $fmt = vat_validate_format($raw);
    if (!$fmt['ok']) {
        return ['reachable' => true, 'valid' => false, 'name' => '', 'address' => '', 'format_error' => $fmt['message']];
    }
    if (!function_exists('curl_init')) {
        return ['reachable' => false];
    }

    $payload = json_encode(['countryCode' => $fmt['country'], 'vatNumber' => $fmt['number']]);

    // VIES is a federation of national registries; some member states (Italy and
    // Germany especially) are slow and intermittently return transient errors.
    // Use a generous timeout and retry once before giving up, so a momentary
    // slow response does not surface as "could not reach VIES".
    $body = false;
    $code = 0;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init('https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'fairtprm-vat-check',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) {
            break; // got a usable HTTP response
        }
        $body = false;
    }

    if ($body === false) {
        // Network/TLS failure or non-2xx after retry -> genuinely unreachable.
        return ['reachable' => false];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['reachable' => false];
    }

    // VIES returns HTTP 200 even when a member state's registry is temporarily
    // unavailable; in that case there is no boolean "valid" and instead an error
    // marker (e.g. MS_UNAVAILABLE / SERVICE_UNAVAILABLE / TIMEOUT). Treat that as
    // "reachable but could not verify right now" rather than a definitive answer.
    if (!array_key_exists('valid', $data) || $data['valid'] === null) {
        $err = '';
        if (isset($data['userError'])) {
            $err = (string)$data['userError'];
        } elseif (isset($data['errorWrappers'][0]['error'])) {
            $err = (string)$data['errorWrappers'][0]['error'];
        }
        return ['reachable' => true, 'valid' => null, 'unavailable' => true, 'detail' => $err];
    }

    return [
        'reachable'   => true,
        'valid'       => (bool)$data['valid'],
        'name'        => isset($data['name']) ? trim((string)$data['name']) : '',
        'address'     => isset($data['address']) ? trim((string)$data['address']) : '',
        'requestDate' => isset($data['requestDate']) ? trim((string)$data['requestDate']) : '',
    ];
}

/**
 * Render the VAT widget: a primary input, a confirmation input, a hidden canonical
 * value, and a status line. $viesEndpoint, when provided, enables live VIES lookups.
 *
 * @param string $name   submitted field name (applied to the hidden input)
 * @param string $value  current stored VAT value
 * @param array  $opts   id, hidden_class, data_attrs, vies_endpoint, input_style
 */
function vat_render_widget($name, $value, $opts = []) {
    $value = vat_normalize($value);
    $id          = $opts['id'] ?? '';
    $hiddenClass = $opts['hidden_class'] ?? '';
    $dataAttrs   = $opts['data_attrs'] ?? '';
    $endpoint    = $opts['vies_endpoint'] ?? '';
    $inputStyle  = $opts['input_style'] ?? 'padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;width:100%;max-width:320px;';

    $epAttr = $endpoint ? (' data-vies-endpoint="' . e($endpoint) . '"') : '';
    $html  = '<div class="vat-widget" data-vat-widget' . $epAttr . '>';

    $pid = $id ? (' id="' . e($id) . '_primary"') : '';
    $cid = $id ? (' id="' . e($id) . '_confirm"') : '';
    // Primary input + an info (i) button that opens a modal with the VIES company
    // details (name, address, checked date). Hidden until a lookup has details.
    $html .= '<div class="vat-primary-row" style="display:flex; align-items:center; gap:6px;">';
    $html .= '<input type="text" class="vat-primary"' . $pid . ' autocomplete="off" spellcheck="false"'
          . ' placeholder="e.g. DE123456789" value="' . e($value) . '" style="' . e($inputStyle) . '">';
    $html .= '<button type="button" class="vat-info-btn" title="View VAT registration details" aria-label="View VAT registration details"'
          . ' style="display:none; flex:none; width:24px; height:24px; padding:0; border:none; background:none; cursor:pointer; color:#2563eb;">'
          . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
          . '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
          . '</button>';
    $html .= '</div>';
    $html .= '<input type="text" class="vat-confirm"' . $cid . ' autocomplete="off" spellcheck="false"'
          . ' placeholder="Re-enter VAT number" value="' . e($value) . '" style="' . e($inputStyle) . ';margin-top:6px;">';

    $idAttr = $id ? (' id="' . e($id) . '"') : '';
    $html .= '<input type="hidden" name="' . e($name) . '"' . $idAttr
          . ($hiddenClass ? (' class="' . e($hiddenClass) . '"') : '')
          . ($dataAttrs ? (' ' . $dataAttrs) : '')
          . ' value="' . e($value) . '">';

    $html .= '<span class="vat-validation-msg" style="display:none;font-size:12px;margin-top:4px;"></span>';
    $html .= '</div>';
    return $html;
}
