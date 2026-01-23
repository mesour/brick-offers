<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\AnalyzerConfigField;
use App\Entity\DiscoveryProfile;
use App\Entity\User;
use App\Enum\Industry;
use App\Enum\LeadSource;
use App\Repository\DiscoveryProfileRepository;
use App\Service\AnalyzerConfigService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

class DiscoveryProfileCrudController extends AbstractTenantCrudController
{
    public function __construct(
        private readonly DiscoveryProfileRepository $profileRepository,
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly AnalyzerConfigService $analyzerConfigService,
    ) {}

    public static function getEntityFqcn(): string
    {
        return DiscoveryProfile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Discovery Profil')
            ->setEntityLabelInPlural('Discovery Profily')
            ->setSearchFields(['name', 'description'])
            ->setDefaultSort(['name' => 'ASC'])
            ->showEntityActionsInlined()
            ->addFormTheme('admin/form/analyzer_config_theme.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $duplicateAction = Action::new('duplicate', 'Duplikovat', 'fa fa-copy')
            ->linkToCrudAction('duplicateProfile')
            ->displayIf(fn (DiscoveryProfile $profile) => $this->hasPermission(User::PERMISSION_SETTINGS_WRITE));

        $setDefaultAction = Action::new('setDefault', 'Nastavit jako výchozí', 'fa fa-star')
            ->linkToCrudAction('setAsDefault')
            ->displayIf(fn (DiscoveryProfile $profile) => !$profile->isDefault() && $this->hasPermission(User::PERMISSION_SETTINGS_WRITE));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $duplicateAction)
            ->add(Crud::PAGE_INDEX, $setDefaultAction)
            ->add(Crud::PAGE_DETAIL, $duplicateAction)
            ->add(Crud::PAGE_DETAIL, $setDefaultAction)
            ->setPermission(Action::NEW, User::PERMISSION_SETTINGS_WRITE)
            ->setPermission(Action::EDIT, User::PERMISSION_SETTINGS_WRITE)
            ->setPermission(Action::DELETE, User::PERMISSION_SETTINGS_WRITE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isDefault'))
            ->add(BooleanFilter::new('discoveryEnabled'))
            ->add(BooleanFilter::new('autoAnalyze'))
            ->add(ChoiceFilter::new('industry')->setChoices(array_combine(
                array_map(fn ($i) => $i->getLabel(), Industry::cases()),
                Industry::cases()
            )));
    }

    public function configureFields(string $pageName): iterable
    {
        $isNew = $pageName === Crud::PAGE_NEW;
        $isEdit = $pageName === Crud::PAGE_EDIT;
        $isDetail = $pageName === Crud::PAGE_DETAIL;

        // Basic Information
        yield FormField::addTab('Základní informace')->setIcon('fa fa-info-circle');

        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield TextField::new('name')
            ->setLabel('Název')
            ->setRequired(true)
            ->setHelp('Krátký název profilu, např. "E-shopy Praha"');

        yield TextareaField::new('description')
            ->setLabel('Popis')
            ->setRequired(false)
            ->hideOnIndex();

        yield ChoiceField::new('industry')
            ->setLabel('Odvětví')
            ->setChoices(array_combine(
                array_map(fn ($i) => $i->getLabel(), Industry::cases()),
                Industry::cases()
            ))
            ->setRequired(false)
            ->setHelp('Určuje které industry-specific analyzery se použijí');

        yield BooleanField::new('isDefault')
            ->setLabel('Výchozí profil')
            ->setHelp('Pouze jeden profil může být výchozí');

        // Discovery Settings Tab
        yield FormField::addTab('Discovery nastavení')->setIcon('fa fa-search');

        yield BooleanField::new('discoveryEnabled')
            ->setLabel('Discovery aktivní')
            ->setHelp('Aktivuje profil pro batch discovery');

        yield ChoiceField::new('discoverySource')
            ->setLabel('Zdroj')
            ->setChoices($this->getSourceChoices())
            ->setRequired(true)
            ->setHelp('Vyberte zdroj pro hledání leadů')
            ->setFormTypeOption('attr', ['data-source-selector' => 'true']);

        // Query-based sources: textarea for queries
        yield TextareaField::new('discoveryQueriesText')
            ->setLabel('Vyhledávací dotazy')
            ->setHelp('Jeden dotaz na řádek (pro Google, Seznam, Firmy.cz, eKatalog apod.)')
            ->onlyOnForms()
            ->setFormTypeOption('row_attr', ['class' => 'source-field query-based-field']);

        // Atlas Školství: school types (multi-select)
        yield ChoiceField::new('schoolTypes')
            ->setLabel('Typy škol')
            ->setChoices([
                'Základní školy' => 'zakladni-skoly',
                'Střední školy' => 'stredni-skoly',
                'Vysoké školy' => 'vysoke-skoly',
                'Vyšší odborné školy' => 'vyssi-odborne-skoly',
                'Jazykové školy' => 'jazykove-skoly',
            ])
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setHelp('Vyberte typy škol pro Atlas Školství')
            ->onlyOnForms()
            ->setFormTypeOption('row_attr', ['class' => 'source-field atlas-skolstvi-field']);

        // Atlas Školství: regions (multi-select, optional)
        yield ChoiceField::new('schoolRegions')
            ->setLabel('Kraje')
            ->setChoices([
                'Praha' => 'praha',
                'Středočeský' => 'stredocesky',
                'Jihočeský' => 'jihocesky',
                'Plzeňský' => 'plzensky',
                'Karlovarský' => 'karlovarsky',
                'Ústecký' => 'ustecky',
                'Liberecký' => 'liberecky',
                'Královéhradecký' => 'kralovehradecky',
                'Pardubický' => 'pardubicky',
                'Vysočina' => 'vysocina',
                'Jihomoravský' => 'jihomoravsky',
                'Olomoucký' => 'olomoucky',
                'Zlínský' => 'zlinsky',
                'Moravskoslezský' => 'moravskoslezsky',
            ])
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setHelp('Volitelně omezit na vybrané kraje (Atlas Školství)')
            ->onlyOnForms()
            ->setFormTypeOption('row_attr', ['class' => 'source-field atlas-skolstvi-field']);

        // Seznam Škol: school types (multi-select) - uses same sourceSettings keys
        yield ChoiceField::new('schoolTypes')
            ->setLabel('Typy škol')
            ->setFormTypeOption('property_path', 'schoolTypes')
            ->setChoices([
                'Mateřské školy' => 'materske-skoly',
                'Základní školy' => 'zakladni-skoly',
                'Základní umělecké školy' => 'zakladni-umelecke-skoly',
            ])
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setHelp('Vyberte typy škol pro Seznam Škol')
            ->onlyOnForms()
            ->setFormTypeOption('row_attr', ['class' => 'source-field seznam-skol-field']);

        // Seznam Škol: regions (multi-select, optional)
        yield ChoiceField::new('schoolRegions')
            ->setLabel('Kraje')
            ->setFormTypeOption('property_path', 'schoolRegions')
            ->setChoices([
                'Praha' => 'praha',
                'Středočeský kraj' => 'stredocesky-kraj',
                'Jihočeský kraj' => 'jihocesky-kraj',
                'Plzeňský kraj' => 'plzensky-kraj',
                'Karlovarský kraj' => 'karlovarsky-kraj',
                'Ústecký kraj' => 'ustecky-kraj',
                'Liberecký kraj' => 'liberecky-kraj',
                'Královéhradecký kraj' => 'kralovehradecky-kraj',
                'Pardubický kraj' => 'pardubicky-kraj',
                'Kraj Vysočina' => 'kraj-vysocina',
                'Jihomoravský kraj' => 'jihomoravsky-kraj',
                'Olomoucký kraj' => 'olomoucky-kraj',
                'Zlínský kraj' => 'zlinsky-kraj',
                'Moravskoslezský kraj' => 'moravskoslezsky-kraj',
            ])
            ->allowMultipleChoices()
            ->setRequired(false)
            ->setHelp('Volitelně omezit na vybrané kraje (Seznam Škol)')
            ->onlyOnForms()
            ->setFormTypeOption('row_attr', ['class' => 'source-field seznam-skol-field']);

        if ($isDetail) {
            yield TextareaField::new('discoveryQueriesText')
                ->setLabel('Vyhledávací dotazy')
                ->formatValue(fn ($value) => str_replace("\n", ', ', $value));

            yield ChoiceField::new('schoolTypes')
                ->setLabel('Typy škol')
                ->setChoices([
                    'Základní školy' => 'zakladni-skoly',
                    'Střední školy' => 'stredni-skoly',
                    'Vysoké školy' => 'vysoke-skoly',
                    'Vyšší odborné školy' => 'vyssi-odborne-skoly',
                    'Jazykové školy' => 'jazykove-skoly',
                ])
                ->allowMultipleChoices();

            yield ChoiceField::new('schoolRegions')
                ->setLabel('Kraje')
                ->setChoices([
                    'Praha' => 'praha',
                    'Středočeský' => 'stredocesky',
                    'Jihočeský' => 'jihocesky',
                    'Plzeňský' => 'plzensky',
                    'Karlovarský' => 'karlovarsky',
                    'Ústecký' => 'ustecky',
                    'Liberecký' => 'liberecky',
                    'Královéhradecký' => 'kralovehradecky',
                    'Pardubický' => 'pardubicky',
                    'Vysočina' => 'vysocina',
                    'Jihomoravský' => 'jihomoravsky',
                    'Olomoucký' => 'olomoucky',
                    'Zlínský' => 'zlinsky',
                    'Moravskoslezský' => 'moravskoslezsky',
                ])
                ->allowMultipleChoices();
        }

        yield IntegerField::new('discoveryLimit')
            ->setLabel('Limit')
            ->setHelp('Maximální počet leadů na jeden dotaz (1-500)')
            ->hideOnIndex();

        yield IntegerField::new('priority')
            ->setLabel('Priorita')
            ->setHelp('1-10, vyšší = důležitější')
            ->hideOnIndex();

        yield BooleanField::new('extractData')
            ->setLabel('Extrahovat data')
            ->setHelp('Extrahovat kontaktní údaje z webu')
            ->hideOnIndex();

        yield BooleanField::new('linkCompany')
            ->setLabel('Propojit s firmou')
            ->setHelp('Propojit lead s firmou podle IČO')
            ->hideOnIndex();

        // Analysis Settings Tab
        yield FormField::addTab('Nastavení analýzy')->setIcon('fa fa-chart-bar');

        yield BooleanField::new('autoAnalyze')
            ->setLabel('Auto-analyze')
            ->setHelp('Automaticky spustit analýzu po discovery');

        // Get industry from the current entity for the field configuration
        $industry = $this->getProfileIndustry();

        yield AnalyzerConfigField::new('analyzerConfigs')
            ->setLabel('Konfigurace analyzérů')
            ->setIndustry($industry)
            ->setHelp('Nastavte které analyzéry se mají spouštět a jejich parametry')
            ->hideOnIndex();

        // Timestamps & Meta
        if (!$isNew) {
            yield FormField::addTab('Metadata')->setIcon('fa fa-clock');

            yield AssociationField::new('user')
                ->setLabel('Vlastník')
                ->hideOnForm();

            yield IntegerField::new('leads.count')
                ->setLabel('Počet leadů')
                ->hideOnForm()
                ->formatValue(fn ($value, DiscoveryProfile $entity) => $entity->getLeads()->count());

            yield DateTimeField::new('createdAt')
                ->setLabel('Vytvořeno')
                ->hideOnForm();

            yield DateTimeField::new('updatedAt')
                ->setLabel('Aktualizováno')
                ->hideOnForm();
        }
    }

    /**
     * Get the industry from the current user.
     */
    private function getProfileIndustry(): ?Industry
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $user->getIndustry();
        }

        return null;
    }

    /**
     * Get analyzer schemas for the current industry (used by views).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAnalyzerSchemas(): array
    {
        return $this->analyzerConfigService->getAnalyzerSchemas($this->getProfileIndustry());
    }

    /**
     * Get discovery source choices filtered by user's industry.
     *
     * @return array<string, string>
     */
    private function getSourceChoices(): array
    {
        $industry = $this->getProfileIndustry();
        $choices = [];

        foreach (LeadSource::cases() as $source) {
            // Skip manual - it's not a discovery source
            if ($source === LeadSource::MANUAL) {
                continue;
            }

            // Skip sources not available for this industry
            if (!$source->isAvailableForIndustry($industry)) {
                continue;
            }

            $choices[$source->value] = $source->value;
        }

        return $choices;
    }

    /**
     * Duplicate a profile.
     */
    public function duplicateProfile(AdminContext $context): RedirectResponse
    {
        /** @var DiscoveryProfile $original */
        $original = $context->getEntity()->getInstance();

        $duplicate = new DiscoveryProfile();
        $duplicate->setUser($original->getUser());
        $duplicate->setName($original->getName() . ' (kopie)');
        $duplicate->setDescription($original->getDescription());
        $duplicate->setIndustry($original->getIndustry());
        $duplicate->setIsDefault(false); // Never duplicate as default
        $duplicate->setDiscoveryEnabled($original->isDiscoveryEnabled());
        $duplicate->setDiscoverySource($original->getDiscoverySource());
        $duplicate->setSourceSettings($original->getSourceSettings());
        $duplicate->setDiscoveryLimit($original->getDiscoveryLimit());
        $duplicate->setExtractData($original->isExtractData());
        $duplicate->setLinkCompany($original->isLinkCompany());
        $duplicate->setPriority($original->getPriority());
        $duplicate->setAutoAnalyze($original->isAutoAnalyze());
        $duplicate->setAnalyzerConfigs($original->getAnalyzerConfigs());

        $this->em->persist($duplicate);
        $this->em->flush();

        $this->addFlash('success', sprintf('Profil "%s" byl zduplikován.', $original->getName()));

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($duplicate->getId()?->toRfc4122())
            ->generateUrl();

        return $this->redirect($url);
    }

    /**
     * Set profile as default.
     */
    public function setAsDefault(AdminContext $context): RedirectResponse
    {
        /** @var DiscoveryProfile $profile */
        $profile = $context->getEntity()->getInstance();

        $fallbackUrl = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        $user = $profile->getUser();
        if ($user === null) {
            $this->addFlash('error', 'Profil nemá přiřazeného uživatele.');

            return $this->redirect($context->getReferrer() ?? $fallbackUrl);
        }

        // Clear default flag for all profiles of this user
        $this->profileRepository->clearDefaultForUser($user);

        // Set this profile as default
        $profile->setIsDefault(true);
        $this->em->flush();

        $this->addFlash('success', sprintf('Profil "%s" byl nastaven jako výchozí.', $profile->getName()));

        return $this->redirect($context->getReferrer() ?? $fallbackUrl);
    }

}
