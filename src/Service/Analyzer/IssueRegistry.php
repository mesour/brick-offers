<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Enum\IssueCategory;
use App\Enum\IssueSeverity;

/**
 * Central registry of all issue definitions.
 *
 * Provides metadata for all issue codes used by analyzers.
 * Only code and evidence are stored in the database, metadata is retrieved from here.
 */
final class IssueRegistry
{
    /**
     * @var array<string, array{category: IssueCategory, severity: IssueSeverity, title: string, description: string, impact: string}>
     */
    private static array $definitions = [
        // HTTP Issues
        'ssl_not_https' => [
            'category' => IssueCategory::HTTP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Web nepoužívá HTTPS',
            'description' => 'Stránka není zabezpečena pomocí SSL/TLS certifikátu.',
            'impact' => 'Uživatelé vidí varování o nezabezpečené stránce, což snižuje důvěryhodnost.',
        ],
        'ssl_connection_failed' => [
            'category' => IssueCategory::HTTP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'SSL certifikát je neplatný',
            'description' => 'Nepodařilo se navázat zabezpečené připojení k serveru.',
            'impact' => 'Prohlížeče zobrazí varování a mohou zablokovat přístup.',
        ],
        'ssl_expired' => [
            'category' => IssueCategory::HTTP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'SSL certifikát vypršel',
            'description' => 'SSL certifikát stránky je prošlý.',
            'impact' => 'Prohlížeče zobrazí varování a mohou zablokovat přístup.',
        ],
        'ssl_expiring_soon' => [
            'category' => IssueCategory::HTTP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'SSL certifikát brzy vyprší',
            'description' => 'SSL certifikát stránky brzy vyprší a je třeba ho obnovit.',
            'impact' => 'Po vypršení certifikátu budou prohlížeče zobrazovat varování.',
        ],
        'http_connection_failed' => [
            'category' => IssueCategory::HTTP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Web není dostupný',
            'description' => 'Nepodařilo se navázat spojení s webovou stránkou.',
            'impact' => 'Stránka je pro uživatele nedostupná.',
        ],
        'http_slow_ttfb' => [
            'category' => IssueCategory::HTTP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Pomalá odezva serveru',
            'description' => 'Server odpovídá příliš pomalu (TTFB > 1.5s).',
            'impact' => 'Pomalá odezva serveru negativně ovlivňuje uživatelský zážitek a SEO.',
        ],
        'http_mixed_content' => [
            'category' => IssueCategory::HTTP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Smíšený obsah (Mixed Content)',
            'description' => 'HTTPS stránka načítá nezabezpečené HTTP zdroje.',
            'impact' => 'Prohlížeče mohou blokovat načítání těchto zdrojů.',
        ],
        'http_soft_404' => [
            'category' => IssueCategory::HTTP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Soft 404 - neexistující stránky vracejí 200',
            'description' => 'Server vrací HTTP 200 pro neexistující stránky místo 404.',
            'impact' => 'Toto může negativně ovlivnit SEO a indexování vyhledávači.',
        ],

        // Security Issues
        'security_missing_content_security_policy' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí Content-Security-Policy hlavička',
            'description' => 'Content-Security-Policy (CSP) pomáhá předcházet XSS a data injection útokům.',
            'impact' => 'Web je náchylnější k XSS útokům.',
        ],
        'security_missing_x_frame_options' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí X-Frame-Options hlavička',
            'description' => 'X-Frame-Options zabraňuje vložení stránky do iframe na jiných webech (clickjacking).',
            'impact' => 'Web může být zneužit pro clickjacking útoky.',
        ],
        'security_missing_x_content_type_options' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí X-Content-Type-Options hlavička',
            'description' => 'X-Content-Type-Options zabraňuje MIME type sniffingu.',
            'impact' => 'Prohlížeč může interpretovat obsah jako jiný typ.',
        ],
        'security_missing_strict_transport_security' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí Strict-Transport-Security hlavička',
            'description' => 'HSTS zajistí, že prohlížeč vždy použije HTTPS.',
            'impact' => 'Uživatelé mohou být přesměrováni na HTTP verzi.',
        ],
        'security_missing_referrer_policy' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí Referrer-Policy hlavička',
            'description' => 'Referrer-Policy kontroluje, jaké informace se posílají v Referer hlavičce.',
            'impact' => 'Citlivé URL parametry mohou uniknout třetím stranám.',
        ],
        'security_missing_permissions_policy' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí Permissions-Policy hlavička',
            'description' => 'Permissions-Policy (dříve Feature-Policy) omezuje funkce prohlížeče.',
            'impact' => 'Web nemá kontrolu nad použitím citlivých API (geolokace, kamera, atd.).',
        ],
        'security_server_version_disclosure' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Server odhaluje verzi software',
            'description' => 'Server hlavička obsahuje informace o verzi software.',
            'impact' => 'Útočníci mohou využít známé zranitelnosti pro danou verzi.',
        ],
        'security_x_powered_by' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Přítomna X-Powered-By hlavička',
            'description' => 'Server odhaluje technologii použitou k běhu webu.',
            'impact' => 'Útočníci mohou využít známé zranitelnosti pro danou technologii.',
        ],
        'security_insecure_form' => [
            'category' => IssueCategory::SECURITY,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Formulář odesílá data přes HTTP',
            'description' => 'Nalezen formulář, který odesílá data na nezabezpečenou HTTP adresu.',
            'impact' => 'Data z formuláře mohou být zachycena útočníkem.',
        ],

        // SEO Issues
        'seo_missing_title' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí title tag',
            'description' => 'Stránka nemá definovaný title tag.',
            'impact' => 'Title je klíčový pro SEO a zobrazuje se ve výsledcích vyhledávání.',
        ],
        'seo_short_title' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Title tag je příliš krátký',
            'description' => 'Title tag by měl mít alespoň 10 znaků.',
            'impact' => 'Krátký title neposkytuje dostatek informací pro uživatele a vyhledávače.',
        ],
        'seo_long_title' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Title tag je příliš dlouhý',
            'description' => 'Title tag by neměl přesáhnout 70 znaků.',
            'impact' => 'Dlouhý title bude ve výsledcích vyhledávání zkrácen.',
        ],
        'seo_missing_description' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí meta description',
            'description' => 'Stránka nemá definovaný meta description.',
            'impact' => 'Meta description se zobrazuje ve výsledcích vyhledávání.',
        ],
        'seo_short_description' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Meta description je příliš krátký',
            'description' => 'Meta description by měl mít alespoň 50 znaků.',
            'impact' => 'Krátký popis neposkytuje dostatek informací.',
        ],
        'seo_long_description' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Meta description je příliš dlouhý',
            'description' => 'Meta description by neměl přesáhnout 160 znaků.',
            'impact' => 'Dlouhý popis bude ve výsledcích vyhledávání zkrácen.',
        ],
        'seo_missing_og_tags' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí Open Graph tagy',
            'description' => 'Stránka nemá definované některé Open Graph tagy pro sdílení na sociálních sítích.',
            'impact' => 'Při sdílení na sociálních sítích nebude mít příspěvek optimální vzhled.',
        ],
        'seo_missing_viewport' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí viewport meta tag',
            'description' => 'Stránka nemá definovaný viewport meta tag pro mobilní zařízení.',
            'impact' => 'Stránka nebude správně zobrazena na mobilních zařízeních.',
        ],
        'seo_missing_h1' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí H1 nadpis',
            'description' => 'Stránka nemá definovaný H1 nadpis.',
            'impact' => 'H1 nadpis je důležitý pro SEO a strukturu stránky.',
        ],
        'seo_multiple_h1' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Více H1 nadpisů na stránce',
            'description' => 'Stránka obsahuje více než jeden H1 nadpis.',
            'impact' => 'Doporučuje se mít pouze jeden H1 nadpis na stránku.',
        ],
        'seo_missing_sitemap' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí sitemap.xml',
            'description' => 'Stránka nemá dostupný soubor sitemap.xml.',
            'impact' => 'Sitemap pomáhá vyhledávačům indexovat všechny stránky webu.',
        ],
        'seo_missing_robots' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí robots.txt',
            'description' => 'Stránka nemá dostupný soubor robots.txt.',
            'impact' => 'Robots.txt řídí, jak vyhledávače procházejí web.',
        ],
        'seo_images_missing_alt' => [
            'category' => IssueCategory::SEO,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Obrázky bez alt atributu',
            'description' => 'Některé obrázky na stránce nemají alt atribut.',
            'impact' => 'Alt atributy jsou důležité pro SEO a přístupnost.',
        ],

        // Libraries Issues
        'lib_outdated_jquery' => [
            'category' => IssueCategory::LIBRARIES,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Zastaralá verze jQuery',
            'description' => 'Nalezena zastaralá verze knihovny jQuery.',
            'impact' => 'Zastaralé knihovny mohou obsahovat bezpečnostní zranitelnosti.',
        ],
        'lib_outdated_bootstrap' => [
            'category' => IssueCategory::LIBRARIES,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Zastaralá verze Bootstrap',
            'description' => 'Nalezena zastaralá verze knihovny Bootstrap.',
            'impact' => 'Zastaralé knihovny mohou obsahovat bezpečnostní zranitelnosti.',
        ],
        'lib_jquery_with_modern_framework' => [
            'category' => IssueCategory::LIBRARIES,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'jQuery použito spolu s moderním frameworkem',
            'description' => 'Stránka používá jQuery společně s moderním frameworkem.',
            'impact' => 'jQuery je většinou zbytečné při použití moderního frameworku, zvyšuje velikost stránky.',
        ],
        'lib_multiple_frameworks' => [
            'category' => IssueCategory::LIBRARIES,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Více frontend frameworků',
            'description' => 'Stránka používá více moderních frontend frameworků současně.',
            'impact' => 'Použití více frameworků výrazně zvyšuje velikost stránky a složitost.',
        ],

        // Outdated Code Issues
        'outdated_missing_doctype' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí DOCTYPE deklarace',
            'description' => 'Stránka nemá definovanou DOCTYPE deklaraci.',
            'impact' => 'Bez DOCTYPE může prohlížeč renderovat stránku v quirks módu, což způsobuje nekonzistentní zobrazení.',
        ],
        'outdated_old_doctype' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Zastaralý DOCTYPE',
            'description' => 'Stránka používá zastaralou DOCTYPE deklaraci místo HTML5.',
            'impact' => 'Doporučujeme použít moderní HTML5 DOCTYPE: <!DOCTYPE html>',
        ],
        'outdated_deprecated_tags' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Zastaralé HTML tagy',
            'description' => 'Stránka používá zastaralé HTML tagy, které nejsou podporovány v HTML5.',
            'impact' => 'Tyto tagy by měly být nahrazeny moderními CSS styly a sémanickým HTML.',
        ],
        'outdated_no_semantic_html' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí HTML5 sémantické elementy',
            'description' => 'Stránka nepoužívá žádné HTML5 sémantické elementy pro strukturování obsahu.',
            'impact' => 'Sémantické HTML5 elementy zlepšují přístupnost, SEO a čitelnost kódu.',
        ],
        'outdated_excessive_inline_styles' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Nadměrné použití inline stylů',
            'description' => 'Stránka obsahuje příliš mnoho inline stylů, což naznačuje zastaralý přístup ke stylování.',
            'impact' => 'Inline styly ztěžují údržbu a znemožňují efektivní cachování CSS.',
        ],
        'outdated_table_layout' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Tabulkový layout',
            'description' => 'Stránka používá tabulky pro layout místo moderního CSS.',
            'impact' => 'Tabulkový layout je zastaralý, špatně responzivní a problematický pro přístupnost. Použijte CSS Grid nebo Flexbox.',
        ],
        'outdated_flash_content' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Flash obsah',
            'description' => 'Stránka obsahuje Flash obsah, který není podporován v moderních prohlížečích.',
            'impact' => 'Adobe Flash byl ukončen v roce 2020. Flash obsah nefunguje v žádném moderním prohlížeči.',
        ],
        'outdated_java_applet' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Java applet',
            'description' => 'Stránka obsahuje Java applet, který není podporován v moderních prohlížečích.',
            'impact' => 'Java applety nejsou podporovány v moderních prohlížečích z bezpečnostních důvodů.',
        ],
        'outdated_fixed_width' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Fixní šířka v pixelech',
            'description' => 'Stránka používá pevně definované šířky v pixelech, což brání responzivitě.',
            'impact' => 'Fixní šířky v pixelech způsobují problémy na různých velikostech obrazovek. Použijte relativní jednotky (%, rem, vw).',
        ],
        'outdated_jquery' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Používá jQuery',
            'description' => 'Stránka používá knihovnu jQuery, která je dnes považována za zastaralou technologii.',
            'impact' => 'jQuery zvyšuje velikost stránky a není nutné pro moderní JavaScript. Moderní prohlížeče mají nativní API.',
        ],
        'outdated_blocking_scripts' => [
            'category' => IssueCategory::OUTDATED_CODE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Blokující skripty v hlavičce',
            'description' => 'Stránka obsahuje velké JavaScript soubory v <head> bez atributu async nebo defer.',
            'impact' => 'Blokující skripty zpomalují vykreslení stránky, protože prohlížeč musí čekat na jejich stažení a spuštění.',
        ],

        // Performance Issues
        'perf_lcp_poor' => [
            'category' => IssueCategory::PERFORMANCE,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Velmi pomalé načítání hlavního obsahu (LCP)',
            'description' => 'Largest Contentful Paint (LCP) výrazně překračuje doporučený limit.',
            'impact' => 'Uživatelé čekají příliš dlouho na zobrazení hlavního obsahu. Google toto hodnotí negativně pro SEO.',
        ],
        'perf_lcp_needs_improvement' => [
            'category' => IssueCategory::PERFORMANCE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Pomalé načítání hlavního obsahu (LCP)',
            'description' => 'Largest Contentful Paint (LCP) překračuje optimální hodnotu.',
            'impact' => 'Zlepšení LCP může pozitivně ovlivnit hodnocení ve vyhledávačích a spokojenost uživatelů.',
        ],
        'perf_fcp_poor' => [
            'category' => IssueCategory::PERFORMANCE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Velmi pomalé první vykreslení (FCP)',
            'description' => 'First Contentful Paint (FCP) překračuje limit.',
            'impact' => 'Uživatelé vidí prázdnou stránku příliš dlouho, což vede k vyšší míře odchodu.',
        ],
        'perf_fcp_needs_improvement' => [
            'category' => IssueCategory::PERFORMANCE,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Pomalé první vykreslení (FCP)',
            'description' => 'First Contentful Paint (FCP) je nad doporučenou hodnotou.',
            'impact' => 'Rychlejší FCP zlepšuje vnímanou rychlost webu.',
        ],
        'perf_cls_poor' => [
            'category' => IssueCategory::PERFORMANCE,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Výrazné posuny layoutu (CLS)',
            'description' => 'Cumulative Layout Shift (CLS) výrazně překračuje limit.',
            'impact' => 'Prvky na stránce se během načítání výrazně posouvají, což frustruje uživatele a může vést k nechtěným kliknutím.',
        ],
        'perf_cls_needs_improvement' => [
            'category' => IssueCategory::PERFORMANCE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Posuny layoutu (CLS)',
            'description' => 'Cumulative Layout Shift (CLS) je nad optimální hodnotou.',
            'impact' => 'Stabilnější layout zlepšuje uživatelský zážitek, zejména na mobilních zařízeních.',
        ],
        'perf_ttfb_poor' => [
            'category' => IssueCategory::PERFORMANCE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Velmi pomalá odezva serveru (TTFB)',
            'description' => 'Time to First Byte (TTFB) překračuje limit.',
            'impact' => 'Server odpovídá příliš pomalu. To může být způsobeno pomalým hostingem, neoptimalizovanou databází nebo složitým backend kódem.',
        ],
        'perf_ttfb_needs_improvement' => [
            'category' => IssueCategory::PERFORMANCE,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Pomalá odezva serveru (TTFB)',
            'description' => 'Time to First Byte (TTFB) je nad doporučenou hodnotou.',
            'impact' => 'Rychlejší odezva serveru zlepší celkovou rychlost načítání webu.',
        ],

        // Responsiveness Issues
        'resp_missing_viewport_meta' => [
            'category' => IssueCategory::RESPONSIVENESS,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí viewport meta tag',
            'description' => 'Stránka nemá definovaný viewport meta tag pro mobilní zařízení.',
            'impact' => 'Stránka nebude správně škálovat na mobilních zařízeních a bude se zobrazovat jako zmenšená verze desktop webu.',
        ],
        'resp_horizontal_overflow_mobile' => [
            'category' => IssueCategory::RESPONSIVENESS,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Horizontální přetečení na mobilu',
            'description' => 'Na mobilním zobrazení stránka přetéká horizontálně.',
            'impact' => 'Uživatelé na mobilu musí scrollovat horizontálně, což je velmi špatná uživatelská zkušenost.',
        ],
        'resp_horizontal_overflow_tablet' => [
            'category' => IssueCategory::RESPONSIVENESS,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Horizontální přetečení na tabletu',
            'description' => 'Na tabletovém zobrazení stránka přetéká horizontálně.',
            'impact' => 'Stránka není správně optimalizována pro tablety.',
        ],
        'resp_small_touch_targets_mobile' => [
            'category' => IssueCategory::RESPONSIVENESS,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Příliš malé klikatelné prvky na mobilu',
            'description' => 'Nalezeny interaktivní prvky menší než 48x48 px.',
            'impact' => 'Malé klikatelné prvky jsou těžko ovladatelné na dotykových zařízeních. Doporučená minimální velikost je 48x48 px.',
        ],
        'resp_small_touch_targets_tablet' => [
            'category' => IssueCategory::RESPONSIVENESS,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Příliš malé klikatelné prvky na tabletu',
            'description' => 'Nalezeny interaktivní prvky menší než 48x48 px.',
            'impact' => 'Malé klikatelné prvky jsou těžko ovladatelné na dotykových zařízeních. Doporučená minimální velikost je 48x48 px.',
        ],

        // Visual Issues
        'visual_inconsistent_padding' => [
            'category' => IssueCategory::VISUAL,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Nekonzistentní odsazení',
            'description' => 'Na stránce je použito příliš mnoho různých hodnot paddingu.',
            'impact' => 'Nekonzistentní odsazení působí neprofesionálně a snižuje vizuální soudržnost designu.',
        ],
        'visual_many_fonts' => [
            'category' => IssueCategory::VISUAL,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Příliš mnoho fontů',
            'description' => 'Na stránce je použito příliš mnoho různých fontů.',
            'impact' => 'Příliš mnoho fontů zpomaluje načítání a působí nekonzistentně.',
        ],
        'visual_inconsistent_typography' => [
            'category' => IssueCategory::VISUAL,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Nekonzistentní velikosti písma',
            'description' => 'Na stránce je použito příliš mnoho různých velikostí písma.',
            'impact' => 'Typografická škála by měla být konzistentní a obsahovat omezený počet velikostí pro lepší čitelnost a design.',
        ],
        'visual_upscaled_images' => [
            'category' => IssueCategory::VISUAL,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Roztažené obrázky (nízká kvalita)',
            'description' => 'Nalezeny obrázky, které jsou zobrazeny ve větší velikosti než jejich skutečné rozlišení.',
            'impact' => 'Roztažené obrázky jsou rozmazané a působí neprofesionálně. Použijte obrázky s dostatečným rozlišením.',
        ],

        // Design Modernity Issues
        'design_very_outdated' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Velmi zastaralý design',
            'description' => 'Web nepoužívá téměř žádné moderní CSS techniky a působí zastarale.',
            'impact' => 'Moderní design využívá CSS Grid, Flexbox, proměnné, gradienty a další techniky pro lepší UX.',
        ],
        'design_outdated' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Zastaralý design',
            'description' => 'Web používá minimum moderních CSS technik.',
            'impact' => 'Zvažte modernizaci designu pomocí CSS Grid, Flexbox a dalších moderních technik.',
        ],
        'design_no_modern_layout' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí moderní layout',
            'description' => 'Web nepoužívá CSS Grid ani Flexbox pro layout.',
            'impact' => 'CSS Grid a Flexbox jsou moderní standardy pro vytváření responzivních layoutů.',
        ],
        'design_float_layout' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Float-based layout',
            'description' => 'Web používá float elementy bez moderních alternativ.',
            'impact' => 'Float byl určen pro obtékání obrázků, ne pro layout. Použijte Flexbox nebo CSS Grid.',
        ],
        'design_table_display' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Display table pro layout',
            'description' => 'Web používá display: table/table-cell pro layout.',
            'impact' => 'Display table pro layout je zastaralá technika. Použijte CSS Grid nebo Flexbox.',
        ],
        'design_no_css_variables' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Nepoužívá CSS proměnné',
            'description' => 'Web nepoužívá CSS custom properties (proměnné).',
            'impact' => 'CSS proměnné usnadňují údržbu stylů a umožňují snadné theming (např. dark mode).',
        ],
        'design_no_gradients' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Nepoužívá CSS gradienty',
            'description' => 'Web nepoužívá žádné CSS gradienty.',
            'impact' => 'Gradienty dodávají designu hloubku a moderní vzhled.',
        ],
        'design_no_animations' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí animace a přechody',
            'description' => 'Web nepoužívá CSS transitions ani animations.',
            'impact' => 'Jemné animace a přechody zlepšují uživatelský zážitek a působí moderně.',
        ],
        'design_no_visual_effects' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí vizuální efekty',
            'description' => 'Web minimálně využívá box-shadow a border-radius.',
            'impact' => 'Stíny a zaoblené rohy dodávají designu hloubku a moderní vzhled.',
        ],
        'design_excessive_important' => [
            'category' => IssueCategory::DESIGN_MODERNITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Nadměrné použití !important',
            'description' => 'V CSS je použito příliš mnoho !important pravidel.',
            'impact' => 'Nadměrné !important značí problémy s CSS architekturou a specificitou.',
        ],

        // Accessibility Issues (base definitions, dynamic codes are handled separately)
        'a11y_color_contrast' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Nedostatečný barevný kontrast',
            'description' => 'Některé texty na stránce nemají dostatečný kontrast oproti pozadí.',
            'impact' => 'Text je špatně čitelný, zejména pro lidi se zhoršeným zrakem.',
        ],
        'a11y_image_alt' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Obrázky bez alt textu',
            'description' => 'Některé obrázky na stránce nemají alt atribut.',
            'impact' => 'Čtečky obrazovky nemohou popsat obsah obrázků nevidomým uživatelům.',
        ],
        'a11y_label' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Formulářové prvky bez popisků',
            'description' => 'Některé formulářové prvky nemají přiřazený label.',
            'impact' => 'Uživatelé nevidí, co mají do formulářového pole zadat.',
        ],
        'a11y_link_name' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Odkazy bez textu',
            'description' => 'Některé odkazy na stránce nemají textový popis.',
            'impact' => 'Čtečky obrazovky nedokážou popsat, kam odkaz vede.',
        ],
        'a11y_html_has_lang' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí atribut lang na HTML',
            'description' => 'HTML element nemá definovaný atribut lang.',
            'impact' => 'Čtečky obrazovky neví, v jakém jazyce má obsah číst.',
        ],
        'a11y_duplicate_id' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Duplicitní ID atributy',
            'description' => 'Některé elementy mají stejné ID.',
            'impact' => 'Může způsobit problémy s navigací pomocí klávesnice a čteček.',
        ],
        'a11y_button_name' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Tlačítka bez názvu',
            'description' => 'Některá tlačítka nemají přístupný název.',
            'impact' => 'Čtečky obrazovky nedokážou popsat, co tlačítko dělá.',
        ],
        'a11y_document_title' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí title stránky',
            'description' => 'Stránka nemá definovaný title element.',
            'impact' => 'Uživatelé nevidí název stránky v záložce prohlížeče.',
        ],
        'a11y_frame_title' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Iframe bez title',
            'description' => 'Některé iframe elementy nemají title atribut.',
            'impact' => 'Čtečky nedokážou popsat obsah vloženého rámce.',
        ],
        'a11y_heading_order' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Nesprávné pořadí nadpisů',
            'description' => 'Nadpisy na stránce nejsou v logickém pořadí.',
            'impact' => 'Struktura stránky není logická pro čtečky obrazovky.',
        ],
        'a11y_meta_viewport' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Viewport blokuje zoom',
            'description' => 'Viewport meta tag zakazuje nebo omezuje zoom.',
            'impact' => 'Uživatelé nemohou přiblížit obsah, což je problém pro slabozraké.',
        ],
        'a11y_list' => [
            'category' => IssueCategory::ACCESSIBILITY,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Nesprávná struktura seznamů',
            'description' => 'Některé seznamy na stránce mají nesprávnou strukturu.',
            'impact' => 'Čtečky obrazovky špatně interpretují seznam položek.',
        ],

        // ===========================================
        // E-SHOP INDUSTRY ISSUES
        // ===========================================

        'eshop_no_product_pages' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Nenalezeny produktové stránky',
            'description' => 'E-shop nemá detekované produktové stránky se strukturovanými daty.',
            'impact' => 'Produkty se nezobrazují ve vyhledávačích s obrázky a cenami.',
        ],
        'eshop_no_product_schema' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí Product schema markup',
            'description' => 'Produktové stránky nepoužívají strukturovaná data schema.org/Product.',
            'impact' => 'Google nezobrazuje rozšířené informace o produktech ve výsledcích vyhledávání.',
        ],
        'eshop_no_cart' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Nenalezen nákupní košík',
            'description' => 'E-shop nemá viditelný nebo funkční nákupní košík.',
            'impact' => 'Zákazníci nemohou nakupovat, což znemožňuje prodej.',
        ],
        'eshop_cart_ux_issues' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Problémy s UX košíku',
            'description' => 'Nákupní košík má problémy s použitelností (chybí počet položek, celková cena).',
            'impact' => 'Zákazníci mohou opustit nákup kvůli nejasnostem.',
        ],
        'eshop_no_payment_methods' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí informace o platebních metodách',
            'description' => 'E-shop nezobrazuje dostupné platební metody.',
            'impact' => 'Zákazníci nevědí, jak mohou zaplatit, což snižuje konverze.',
        ],
        'eshop_limited_payment_methods' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Omezené platební metody',
            'description' => 'E-shop nabízí málo platebních metod (pouze 1-2).',
            'impact' => 'Zákazníci preferující jiné metody odejdou ke konkurenci.',
        ],
        'eshop_no_shipping_info' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí informace o dopravě',
            'description' => 'E-shop nezobrazuje informace o možnostech doručení.',
            'impact' => 'Zákazníci nevědí, jak a kdy obdrží zboží.',
        ],
        'eshop_no_free_shipping_threshold' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí hranice pro dopravu zdarma',
            'description' => 'E-shop nenabízí nebo nezobrazuje hranici pro dopravu zdarma.',
            'impact' => 'Doprava zdarma zvyšuje průměrnou hodnotu objednávky.',
        ],
        'eshop_no_ssl_seal' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí bezpečnostní pečeť',
            'description' => 'E-shop nezobrazuje SSL seal nebo bezpečnostní certifikát.',
            'impact' => 'Zákazníci mohou mít obavy o bezpečnost platby.',
        ],
        'eshop_no_reviews' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí zákaznické recenze',
            'description' => 'E-shop nezobrazuje hodnocení a recenze produktů nebo obchodu.',
            'impact' => 'Recenze zvyšují důvěru a konverze.',
        ],
        'eshop_no_trust_badges' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí trust signály',
            'description' => 'E-shop nezobrazuje certifikáty, záruky nebo loga důvěryhodnosti.',
            'impact' => 'Trust badges (Heureka, Zboží.cz, apod.) zvyšují důvěru zákazníků.',
        ],
        'eshop_no_return_policy' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí informace o vrácení zboží',
            'description' => 'E-shop nemá viditelné informace o možnosti vrácení zboží.',
            'impact' => 'Zákazníci se bojí nakoupit, když nevědí, zda mohou vrátit.',
        ],
        'eshop_no_contact_info' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí kontaktní informace',
            'description' => 'E-shop nezobrazuje telefonní číslo ani e-mail pro zákaznickou podporu.',
            'impact' => 'Zákazníci nemohou kontaktovat obchod v případě problémů.',
        ],
        'eshop_no_search' => [
            'category' => IssueCategory::INDUSTRY_ESHOP,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí vyhledávání produktů',
            'description' => 'E-shop nemá funkci vyhledávání produktů.',
            'impact' => 'Zákazníci nemohou rychle najít konkrétní produkty.',
        ],

        // ===========================================
        // WEBDESIGN INDUSTRY ISSUES
        // ===========================================

        'webdesign_no_portfolio' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí portfolio/reference',
            'description' => 'Web webdesign studia nezobrazuje ukázky realizovaných projektů.',
            'impact' => 'Klienti nemohou posoudit kvalitu práce, což znemožňuje získání zakázek.',
        ],
        'webdesign_few_portfolio_items' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Málo položek v portfoliu',
            'description' => 'Portfolio obsahuje méně než 5 projektů.',
            'impact' => 'Více ukázek zvyšuje důvěryhodnost a demonstruje zkušenosti.',
        ],
        'webdesign_no_case_studies' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí case studies',
            'description' => 'Web neobsahuje podrobné případové studie realizovaných projektů.',
            'impact' => 'Case studies demonstrují proces a hodnotu, kterou studio přináší.',
        ],
        'webdesign_no_pricing' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí informace o cenách',
            'description' => 'Web nezobrazuje orientační ceny nebo ceníky služeb.',
            'impact' => 'Potenciální klienti nevědí, zda si služby mohou dovolit.',
        ],
        'webdesign_no_services' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí popis služeb',
            'description' => 'Web jasně nepopisuje nabízené služby (webdesign, vývoj, SEO, apod.).',
            'impact' => 'Klienti nevědí, co studio nabízí a zda odpovídá jejich potřebám.',
        ],
        'webdesign_no_testimonials' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí reference/testimonials',
            'description' => 'Web nezobrazuje vyjádření spokojených klientů.',
            'impact' => 'Testimonials zvyšují důvěryhodnost a pomáhají získat nové klienty.',
        ],
        'webdesign_no_team' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí představení týmu',
            'description' => 'Web nezobrazuje informace o týmu nebo zakladatelích.',
            'impact' => 'Lidský prvek zvyšuje důvěru a napomáhá navázání vztahu.',
        ],
        'webdesign_outdated_own_design' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Zastaralý vlastní design',
            'description' => 'Web webdesign studia sám působí zastarale nebo neprofesionálně.',
            'impact' => 'Vlastní web je vizitkou studia. Zastaralý design odrazuje klienty.',
        ],
        'webdesign_no_cta' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí výrazné CTA',
            'description' => 'Web nemá jasné výzvy k akci (Kontaktujte nás, Získat nabídku).',
            'impact' => 'Bez jasného CTA návštěvníci nevědí, jak pokračovat.',
        ],
        'webdesign_no_contact_form' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí kontaktní formulář',
            'description' => 'Web nemá kontaktní formulář pro poptávky.',
            'impact' => 'Potenciální klienti nemohou snadno odeslat poptávku.',
        ],
        'webdesign_no_blog' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí blog/novinky',
            'description' => 'Web nemá blog nebo sekci s novinkami.',
            'impact' => 'Blog zlepšuje SEO a demonstruje odbornost.',
        ],
        'webdesign_no_social_proof' => [
            'category' => IssueCategory::INDUSTRY_WEBDESIGN,
            'severity' => IssueSeverity::OPTIMIZATION,
            'title' => 'Chybí social proof',
            'description' => 'Web nezobrazuje loga klientů, počet realizací nebo ocenění.',
            'impact' => 'Social proof zvyšuje důvěryhodnost a přesvědčivost.',
        ],

        // ===========================================
        // REAL ESTATE INDUSTRY ISSUES (skeleton)
        // ===========================================

        'realestate_no_listings' => [
            'category' => IssueCategory::INDUSTRY_REAL_ESTATE,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí nabídky nemovitostí',
            'description' => 'Web realitní kanceláře nezobrazuje aktuální nabídky.',
            'impact' => 'Klienti nemohou procházet dostupné nemovitosti.',
        ],
        'realestate_no_search_filters' => [
            'category' => IssueCategory::INDUSTRY_REAL_ESTATE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí filtry vyhledávání',
            'description' => 'Web neumožňuje filtrovat nemovitosti podle typu, ceny, lokality.',
            'impact' => 'Klienti nemohou efektivně najít relevantní nabídky.',
        ],
        'realestate_no_contact_agent' => [
            'category' => IssueCategory::INDUSTRY_REAL_ESTATE,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí kontakt na makléře',
            'description' => 'U nabídek chybí kontaktní informace na odpovědného makléře.',
            'impact' => 'Klienti nemohou rychle kontaktovat makléře ohledně konkrétní nabídky.',
        ],

        // ===========================================
        // AUTOMOBILE INDUSTRY ISSUES (skeleton)
        // ===========================================

        'automobile_no_inventory' => [
            'category' => IssueCategory::INDUSTRY_AUTOMOBILE,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí nabídka vozidel',
            'description' => 'Web autosalonu nezobrazuje aktuální nabídku vozidel.',
            'impact' => 'Zákazníci nemohou procházet dostupná vozidla.',
        ],
        'automobile_no_financing' => [
            'category' => IssueCategory::INDUSTRY_AUTOMOBILE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí informace o financování',
            'description' => 'Web nenabízí informace o možnostech financování nebo leasingu.',
            'impact' => 'Zákazníci hledající financování odejdou ke konkurenci.',
        ],
        'automobile_no_test_drive' => [
            'category' => IssueCategory::INDUSTRY_AUTOMOBILE,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí objednání testovací jízdy',
            'description' => 'Web neumožňuje online objednání testovací jízdy.',
            'impact' => 'Moderní zákazníci očekávají online rezervaci.',
        ],

        // ===========================================
        // RESTAURANT INDUSTRY ISSUES (skeleton)
        // ===========================================

        'restaurant_no_menu' => [
            'category' => IssueCategory::INDUSTRY_RESTAURANT,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí jídelní lístek',
            'description' => 'Web restaurace nezobrazuje aktuální menu.',
            'impact' => 'Zákazníci nemohou zjistit, co restaurace nabízí.',
        ],
        'restaurant_menu_pdf_only' => [
            'category' => IssueCategory::INDUSTRY_RESTAURANT,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Menu pouze v PDF',
            'description' => 'Jídelní lístek je dostupný pouze jako PDF ke stažení.',
            'impact' => 'PDF menu je špatně čitelné na mobilu a není indexované vyhledávači.',
        ],
        'restaurant_no_reservation' => [
            'category' => IssueCategory::INDUSTRY_RESTAURANT,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí online rezervace',
            'description' => 'Web neumožňuje online rezervaci stolu.',
            'impact' => 'Zákazníci preferují online rezervace před telefonováním.',
        ],

        // ===========================================
        // MEDICAL INDUSTRY ISSUES (skeleton)
        // ===========================================

        'medical_no_appointment' => [
            'category' => IssueCategory::INDUSTRY_MEDICAL,
            'severity' => IssueSeverity::CRITICAL,
            'title' => 'Chybí online objednání',
            'description' => 'Web zdravotnického zařízení neumožňuje online objednání.',
            'impact' => 'Pacienti musí telefonovat, což je neefektivní.',
        ],
        'medical_no_services' => [
            'category' => IssueCategory::INDUSTRY_MEDICAL,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí přehled služeb',
            'description' => 'Web nezobrazuje nabízené zdravotnické služby.',
            'impact' => 'Pacienti nevědí, jaké služby jsou k dispozici.',
        ],
        'medical_no_doctor_profiles' => [
            'category' => IssueCategory::INDUSTRY_MEDICAL,
            'severity' => IssueSeverity::RECOMMENDED,
            'title' => 'Chybí profily lékařů',
            'description' => 'Web nezobrazuje informace o lékařích a jejich specializacích.',
            'impact' => 'Pacienti si chtějí vybrat lékaře podle jeho profilu.',
        ],
    ];

    /**
     * Get issue definition by code.
     *
     * @return array{category: IssueCategory, severity: IssueSeverity, title: string, description: string, impact: string}|null
     */
    public static function get(string $code): ?array
    {
        return self::$definitions[$code] ?? null;
    }

    /**
     * Get title for issue code.
     */
    public static function getTitle(string $code): string
    {
        return self::$definitions[$code]['title'] ?? $code;
    }

    /**
     * Get severity for issue code.
     */
    public static function getSeverity(string $code): IssueSeverity
    {
        return self::$definitions[$code]['severity'] ?? IssueSeverity::OPTIMIZATION;
    }

    /**
     * Get category for issue code.
     */
    public static function getCategory(string $code): IssueCategory
    {
        return self::$definitions[$code]['category'] ?? IssueCategory::HTTP;
    }

    /**
     * Get description for issue code.
     */
    public static function getDescription(string $code): string
    {
        return self::$definitions[$code]['description'] ?? '';
    }

    /**
     * Get impact for issue code.
     */
    public static function getImpact(string $code): string
    {
        return self::$definitions[$code]['impact'] ?? '';
    }

    /**
     * Check if issue code exists in registry.
     */
    public static function has(string $code): bool
    {
        return isset(self::$definitions[$code]);
    }

    /**
     * Get all registered issue codes.
     *
     * @return array<string>
     */
    public static function getAllCodes(): array
    {
        return array_keys(self::$definitions);
    }

    /**
     * Get all issues for a specific category.
     *
     * @return array<string, array{category: IssueCategory, severity: IssueSeverity, title: string, description: string, impact: string}>
     */
    public static function getByCategory(IssueCategory $category): array
    {
        return array_filter(
            self::$definitions,
            fn (array $def) => $def['category'] === $category
        );
    }

    /**
     * Get all issues for a specific severity.
     *
     * @return array<string, array{category: IssueCategory, severity: IssueSeverity, title: string, description: string, impact: string}>
     */
    public static function getBySeverity(IssueSeverity $severity): array
    {
        return array_filter(
            self::$definitions,
            fn (array $def) => $def['severity'] === $severity
        );
    }
}
