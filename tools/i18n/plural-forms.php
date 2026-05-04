<?php

declare(strict_types=1);

/**
 * Returns the gettext Plural-Forms string for a given Piwigo locale code.
 * Covers all 72 locales currently shipped with Piwigo 16.
 */
function get_plural_form(string $locale): string
{
    // Strip region for lookup if exact match not found
    $map = plural_forms_map();
    if (isset($map[$locale])) {
        return $map[$locale];
    }
    // Try language prefix only (e.g. 'fr' from 'fr_FR')
    $lang = substr($locale, 0, 2);
    foreach ($map as $key => $form) {
        if (str_starts_with($key, $lang . '_')) {
            return $form;
        }
    }
    // Fallback: English
    return 'nplurals=2; plural=(n != 1);';
}

/** @return array<string,string> */
function plural_forms_map(): array
{
    return [
        'af_ZA' => 'nplurals=2; plural=(n != 1);',
        'ar_EG' => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);',
        'ar_MA' => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);',
        'ar_SA' => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);',
        'az_AZ' => 'nplurals=2; plural=(n != 1);',
        'bg_BG' => 'nplurals=2; plural=(n != 1);',
        'bn_IN' => 'nplurals=2; plural=(n != 1);',
        'br_FR' => 'nplurals=2; plural=(n > 1);',
        'ca_ES' => 'nplurals=2; plural=(n != 1);',
        'cs_CZ' => 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
        'da_DK' => 'nplurals=2; plural=(n != 1);',
        'de_DE' => 'nplurals=2; plural=(n != 1);',
        'dv_MV' => 'nplurals=2; plural=(n != 1);',
        'el_GR' => 'nplurals=2; plural=(n != 1);',
        'en_GB' => 'nplurals=2; plural=(n != 1);',
        'en_UK' => 'nplurals=2; plural=(n != 1);',
        'en_US' => 'nplurals=2; plural=(n != 1);',
        'eo_EO' => 'nplurals=2; plural=(n != 1);',
        'es_AR' => 'nplurals=2; plural=(n != 1);',
        'es_ES' => 'nplurals=2; plural=(n != 1);',
        'es_MX' => 'nplurals=2; plural=(n != 1);',
        'et_EE' => 'nplurals=2; plural=(n != 1);',
        'eu_ES' => 'nplurals=2; plural=(n != 1);',
        'fa_IR' => 'nplurals=2; plural=(n > 1);',
        'fi_FI' => 'nplurals=2; plural=(n != 1);',
        'fr_CA' => 'nplurals=2; plural=(n > 1);',
        'fr_FR' => 'nplurals=2; plural=(n > 1);',
        'ga_IE' => 'nplurals=5; plural=n==1 ? 0 : n==2 ? 1 : (n>2 && n<7) ? 2 : (n>6 && n<11) ? 3 : 4;',
        'gl_ES' => 'nplurals=2; plural=(n != 1);',
        'gu_IN' => 'nplurals=2; plural=(n != 1);',
        'he_IL' => 'nplurals=4; plural=(n==1 ? 0 : n==2 ? 1 : (n<0 || n>10) && n%10==0 ? 2 : 3);',
        'hr_HR' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'hu_HU' => 'nplurals=2; plural=(n != 1);',
        'hy_AM' => 'nplurals=2; plural=(n != 1);',
        'id_ID' => 'nplurals=1; plural=0;',
        'is_IS' => 'nplurals=2; plural=(n%10!=1 || n%100==11);',
        'it_IT' => 'nplurals=2; plural=(n != 1);',
        'ja_JP' => 'nplurals=1; plural=0;',
        'ka_GE' => 'nplurals=1; plural=0;',
        'km_KH' => 'nplurals=1; plural=0;',
        'kn_IN' => 'nplurals=2; plural=(n != 1);',
        'ko_KR' => 'nplurals=1; plural=0;',
        'kok_IN' => 'nplurals=2; plural=(n != 1);',
        'lb_LU' => 'nplurals=2; plural=(n != 1);',
        'lt_LT' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'lv_LV' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n != 0 ? 1 : 2);',
        'mk_MK' => 'nplurals=2; plural=(n==1 || n%10==1 ? 0 : 1);',
        'mn_MN' => 'nplurals=2; plural=(n != 1);',
        'ms_MY' => 'nplurals=1; plural=0;',
        'nb_NO' => 'nplurals=2; plural=(n != 1);',
        'nl_NL' => 'nplurals=2; plural=(n != 1);',
        'nn_NO' => 'nplurals=2; plural=(n != 1);',
        'pl_PL' => 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'pt_BR' => 'nplurals=2; plural=(n > 1);',
        'pt_PT' => 'nplurals=2; plural=(n != 1);',
        'ro_RO' => 'nplurals=3; plural=(n==1 ? 0 : (n==0 || (n%100>0 && n%100<20)) ? 1 : 2);',
        'ru_RU' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'sh_RS' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'sk_SK' => 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
        'sl_SI' => 'nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);',
        'sr_RS' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'sv_SE' => 'nplurals=2; plural=(n != 1);',
        'ta_IN' => 'nplurals=2; plural=(n != 1);',
        'te_IN' => 'nplurals=2; plural=(n != 1);',
        'th_TH' => 'nplurals=1; plural=0;',
        'tr_TR' => 'nplurals=2; plural=(n != 1);',
        'uk_UA' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        'vi_VN' => 'nplurals=1; plural=0;',
        'wo_SN' => 'nplurals=1; plural=0;',
        'zh_CN' => 'nplurals=1; plural=0;',
        'zh_HK' => 'nplurals=1; plural=0;',
        'zh_TW' => 'nplurals=1; plural=0;',
    ];
}
