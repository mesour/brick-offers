<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Add default email templates in Czech.
 */
final class Version20260123034349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default email templates in Czech for outreach emails';
    }

    public function up(Schema $schema): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Generic default template (all industries)
        $this->addSql("INSERT INTO email_templates (id, user_id, name, subject_template, body_template, industry, is_default, variables, created_at, updated_at) VALUES (
            :id,
            NULL,
            'Výchozí šablona',
            'Váš web {{domain}} - nalezeno {{issues_count}} problémů',
            :body,
            NULL,
            true,
            :variables,
            :now,
            :now
        )", [
            'id' => Uuid::v4()->toRfc4122(),
            'body' => $this->getGenericTemplate(),
            'variables' => json_encode($this->getVariablesDoc()),
            'now' => $now,
        ]);

        // Webdesign industry template
        $this->addSql("INSERT INTO email_templates (id, user_id, name, subject_template, body_template, industry, is_default, variables, created_at, updated_at) VALUES (
            :id,
            NULL,
            'Webdesign - redesign nabídka',
            'Návrh moderního redesignu pro {{domain}}',
            :body,
            'webdesign',
            true,
            :variables,
            :now,
            :now
        )", [
            'id' => Uuid::v4()->toRfc4122(),
            'body' => $this->getWebdesignTemplate(),
            'variables' => json_encode($this->getVariablesDoc()),
            'now' => $now,
        ]);

        // E-shop template
        $this->addSql("INSERT INTO email_templates (id, user_id, name, subject_template, body_template, industry, is_default, variables, created_at, updated_at) VALUES (
            :id,
            NULL,
            'E-shop - optimalizace',
            'Zvyšte konverze vašeho e-shopu {{domain}}',
            :body,
            'eshop',
            true,
            :variables,
            :now,
            :now
        )", [
            'id' => Uuid::v4()->toRfc4122(),
            'body' => $this->getEshopTemplate(),
            'variables' => json_encode($this->getVariablesDoc()),
            'now' => $now,
        ]);

        // Real estate template
        $this->addSql("INSERT INTO email_templates (id, user_id, name, subject_template, body_template, industry, is_default, variables, created_at, updated_at) VALUES (
            :id,
            NULL,
            'Reality - prezentace nemovitostí',
            'Lepší prezentace nemovitostí na {{domain}}',
            :body,
            'real_estate',
            true,
            :variables,
            :now,
            :now
        )", [
            'id' => Uuid::v4()->toRfc4122(),
            'body' => $this->getRealEstateTemplate(),
            'variables' => json_encode($this->getVariablesDoc()),
            'now' => $now,
        ]);

        // Restaurant template
        $this->addSql("INSERT INTO email_templates (id, user_id, name, subject_template, body_template, industry, is_default, variables, created_at, updated_at) VALUES (
            :id,
            NULL,
            'Restaurace - online rezervace',
            'Více hostů pro {{company_name}} díky lepšímu webu',
            :body,
            'restaurant',
            true,
            :variables,
            :now,
            :now
        )", [
            'id' => Uuid::v4()->toRfc4122(),
            'body' => $this->getRestaurantTemplate(),
            'variables' => json_encode($this->getVariablesDoc()),
            'now' => $now,
        ]);

        // Medical template
        $this->addSql("INSERT INTO email_templates (id, user_id, name, subject_template, body_template, industry, is_default, variables, created_at, updated_at) VALUES (
            :id,
            NULL,
            'Zdravotnictví - důvěryhodná prezentace',
            'Profesionální web pro {{company_name}}',
            :body,
            'medical',
            true,
            :variables,
            :now,
            :now
        )", [
            'id' => Uuid::v4()->toRfc4122(),
            'body' => $this->getMedicalTemplate(),
            'variables' => json_encode($this->getVariablesDoc()),
            'now' => $now,
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM email_templates WHERE user_id IS NULL AND is_default = true");
    }

    private function getGenericTemplate(): string
    {
        return <<<'HTML'
<p>Dobrý den,</p>

<p>provedli jsme analýzu vašeho webu <strong>{{domain}}</strong> a zjistili jsme několik oblastí, které by bylo vhodné zlepšit.</p>

<h3>Výsledky analýzy</h3>
<ul>
    <li><strong>Celkové skóre:</strong> {{total_score}}/100</li>
    <li><strong>Nalezené problémy:</strong> {{issues_count}}</li>
</ul>

<p>Mezi nejzávažnější problémy patří:</p>
{{top_issues}}

<p>Rádi bychom vám nabídli bezplatnou konzultaci, kde vám ukážeme, jak tyto problémy vyřešit a zlepšit tak výkon vašeho webu.</p>

<p>Máte zájem o více informací?</p>

<p>S pozdravem,<br>
{{sender_name}}</p>

{{tracking_pixel}}
HTML;
    }

    private function getWebdesignTemplate(): string
    {
        return <<<'HTML'
<p>Dobrý den,</p>

<p>při procházení webů v oboru webdesignu jsme narazili na váš web <strong>{{domain}}</strong>. Zaujal nás váš přístup, ale všimli jsme si několika technických nedostatků, které by mohly odrazovat potenciální klienty.</p>

<h3>Co jsme zjistili</h3>
<ul>
    <li><strong>Skóre webu:</strong> {{total_score}}/100</li>
    <li><strong>Nalezené problémy:</strong> {{issues_count}}</li>
</ul>

{{top_issues}}

<p>Připravili jsme pro vás <strong>návrh moderního redesignu</strong>, který řeší tyto problémy a zároveň prezentuje vaši práci v tom nejlepším světle.</p>

{{proposal_link}}

<p><strong>{{proposal_title}}</strong></p>
<p>{{proposal_summary}}</p>

<p>Rádi s vámi probereme detaily. Kdy by se vám hodil krátký call?</p>

<p>S pozdravem,<br>
{{sender_name}}</p>

{{tracking_pixel}}
HTML;
    }

    private function getEshopTemplate(): string
    {
        return <<<'HTML'
<p>Dobrý den,</p>

<p>analyzovali jsme váš e-shop <strong>{{domain}}</strong> a našli jsme několik oblastí, které mohou negativně ovlivňovat vaše konverze a prodeje.</p>

<h3>Hlavní zjištění</h3>
<ul>
    <li><strong>Celkové skóre:</strong> {{total_score}}/100</li>
    <li><strong>Problémových oblastí:</strong> {{issues_count}}</li>
</ul>

<p>Nejkritičtější problémy:</p>
{{top_issues}}

<p>Každý z těchto problémů může způsobovat ztrátu zákazníků ještě předtím, než dokončí nákup. Rychlost načítání, mobilní zobrazení a bezpečnost jsou klíčové faktory, které ovlivňují rozhodování zákazníků.</p>

<p>Připravili jsme pro vás <strong>konkrétní návrhy na zlepšení</strong>, které pomohou zvýšit konverzní poměr vašeho e-shopu.</p>

<p>Máte zájem o bezplatnou konzultaci?</p>

<p>S pozdravem,<br>
{{sender_name}}</p>

{{tracking_pixel}}
HTML;
    }

    private function getRealEstateTemplate(): string
    {
        return <<<'HTML'
<p>Dobrý den,</p>

<p>jako realitní kancelář víte, že první dojem je klíčový. Analyzovali jsme váš web <strong>{{domain}}</strong> a našli několik oblastí ke zlepšení.</p>

<h3>Výsledky analýzy</h3>
<ul>
    <li><strong>Skóre webu:</strong> {{total_score}}/100</li>
    <li><strong>Nalezené nedostatky:</strong> {{issues_count}}</li>
</ul>

{{top_issues}}

<p>V realitním byznysu rozhoduje kvalitní prezentace nemovitostí. Pomalý web nebo špatné zobrazení na mobilu může znamenat ztrátu potenciálních klientů dříve, než vůbec uvidí vaši nabídku.</p>

<p>Nabízíme vám <strong>bezplatnou konzultaci</strong>, kde vám ukážeme:</p>
<ul>
    <li>Jak zrychlit načítání stránek s nemovitostmi</li>
    <li>Jak zlepšit zobrazení na mobilech (60% návštěvníků)</li>
    <li>Jak optimalizovat prezentaci nemovitostí pro lepší konverze</li>
</ul>

<p>Kdy by se vám hodilo krátké 15minutové představení?</p>

<p>S pozdravem,<br>
{{sender_name}}</p>

{{tracking_pixel}}
HTML;
    }

    private function getRestaurantTemplate(): string
    {
        return <<<'HTML'
<p>Dobrý den,</p>

<p>hledali jsme restaurace v okolí a narazili jsme na <strong>{{company_name}}</strong>. Váš web {{domain}} vypadá zajímavě, ale všimli jsme si několika věcí, které mohou odrazovat potenciální hosty.</p>

<h3>Co jsme zjistili</h3>
<ul>
    <li><strong>Skóre webu:</strong> {{total_score}}/100</li>
    <li><strong>Problémové oblasti:</strong> {{issues_count}}</li>
</ul>

{{top_issues}}

<p>Většina lidí hledá restaurace na mobilu - cestou, v práci, nebo když plánují večeři. Pokud se váš web na mobilu špatně zobrazuje nebo pomalu načítá, hosté jednoduše půjdou jinam.</p>

<p>Nabízíme vám:</p>
<ul>
    <li>Moderní web optimalizovaný pro mobily</li>
    <li>Online rezervační systém</li>
    <li>Přehledné menu s fotografiemi</li>
    <li>Napojení na Google Maps a recenze</li>
</ul>

<p>Máte zájem o nezávaznou konzultaci?</p>

<p>S pozdravem,<br>
{{sender_name}}</p>

{{tracking_pixel}}
HTML;
    }

    private function getMedicalTemplate(): string
    {
        return <<<'HTML'
<p>Dobrý den,</p>

<p>při vyhledávání zdravotnických zařízení jsme narazili na váš web <strong>{{domain}}</strong>. Pro pacienty je důvěryhodnost a profesionalita webu velmi důležitá.</p>

<h3>Výsledky naší analýzy</h3>
<ul>
    <li><strong>Celkové skóre:</strong> {{total_score}}/100</li>
    <li><strong>Nalezené nedostatky:</strong> {{issues_count}}</li>
</ul>

{{top_issues}}

<p>Ve zdravotnictví je důvěra klíčová. Bezpečnost webu (HTTPS), rychlost načítání a profesionální vzhled přímo ovlivňují, zda si pacient vybere právě vás.</p>

<p>Specializujeme se na weby pro zdravotnická zařízení a nabízíme:</p>
<ul>
    <li>Moderní, důvěryhodný design</li>
    <li>Online objednávkový systém</li>
    <li>Zabezpečení dle GDPR</li>
    <li>Optimalizaci pro vyhledávače</li>
</ul>

<p>Rádi vám připravíme nezávazný návrh. Máte zájem?</p>

<p>S pozdravem,<br>
{{sender_name}}</p>

{{tracking_pixel}}
HTML;
    }

    /**
     * @return array<string, string>
     */
    private function getVariablesDoc(): array
    {
        return [
            'domain' => 'Doména webu',
            'company_name' => 'Název firmy',
            'contact_name' => 'Jméno kontaktu',
            'total_score' => 'Celkové skóre analýzy (0-100)',
            'issues_count' => 'Počet nalezených problémů',
            'top_issues' => 'Seznam nejzávažnějších problémů',
            'proposal_title' => 'Název návrhu/proposalu',
            'proposal_summary' => 'Shrnutí návrhu',
            'proposal_link' => 'Odkaz na návrh',
            'sender_name' => 'Jméno odesílatele',
            'tracking_pixel' => 'Sledovací pixel (automaticky)',
        ];
    }
}
