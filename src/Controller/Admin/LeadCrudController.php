<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Lead;
use App\Entity\User;
use App\Enum\Industry;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Enum\LeadType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class LeadCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Lead::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Lead')
            ->setEntityLabelInPlural('Leads')
            ->setSearchFields(['domain', 'companyName', 'email', 'ico'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $analyzeAction = Action::new('analyze', 'Analyzovat', 'fa fa-chart-bar')
            ->linkToCrudAction('triggerAnalysis')
            ->displayIf(fn (Lead $lead) => $this->hasPermission(User::PERMISSION_LEADS_ANALYZE));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $analyzeAction)
            ->add(Crud::PAGE_DETAIL, $analyzeAction)
            ->setPermission(Action::NEW, User::PERMISSION_LEADS_WRITE)
            ->setPermission(Action::EDIT, User::PERMISSION_LEADS_WRITE)
            ->setPermission(Action::DELETE, User::PERMISSION_LEADS_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(array_combine(
                array_map(fn ($s) => $s->value, LeadStatus::cases()),
                LeadStatus::cases()
            )))
            ->add(ChoiceFilter::new('source')->setChoices(array_combine(
                array_map(fn ($s) => $s->value, LeadSource::cases()),
                LeadSource::cases()
            )))
            ->add(ChoiceFilter::new('industry')->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            )))
            ->add(DateTimeFilter::new('createdAt'))
            ->add(DateTimeFilter::new('lastAnalyzedAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield UrlField::new('url')
            ->setLabel('URL')
            ->setRequired(true);

        yield TextField::new('domain')
            ->setLabel('Doména')
            ->hideOnForm();

        yield TextField::new('companyName')
            ->setLabel('Firma')
            ->setRequired(false);

        yield TextField::new('email')
            ->setLabel('Email')
            ->setRequired(false);

        yield ChoiceField::new('status')
            ->setLabel('Status')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->value, LeadStatus::cases()),
                LeadStatus::cases()
            ))
            ->renderAsBadges([
                LeadStatus::NEW->value => 'secondary',
                LeadStatus::POTENTIAL->value => 'info',
                LeadStatus::GOOD->value => 'success',
                LeadStatus::DONE->value => 'primary',
                LeadStatus::DEAL->value => 'warning',
                LeadStatus::VERY_BAD->value => 'danger',
                LeadStatus::BAD->value => 'danger',
                LeadStatus::MIDDLE->value => 'warning',
                LeadStatus::QUALITY_GOOD->value => 'success',
                LeadStatus::SUPER->value => 'success',
            ]);

        yield ChoiceField::new('source')
            ->setLabel('Zdroj')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->value, LeadSource::cases()),
                LeadSource::cases()
            ))
            ->hideOnIndex();

        yield ChoiceField::new('type')
            ->setLabel('Typ')
            ->setChoices(array_combine(
                array_map(fn ($t) => $t->value, LeadType::cases()),
                LeadType::cases()
            ))
            ->hideOnIndex();

        yield ChoiceField::new('industry')
            ->setLabel('Odvětví')
            ->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            ))
            ->setRequired(false);

        yield IntegerField::new('priority')
            ->setLabel('Priorita')
            ->setHelp('1-10, vyšší = důležitější')
            ->hideOnIndex();

        yield AssociationField::new('company')
            ->setLabel('Společnost')
            ->hideOnIndex();

        yield AssociationField::new('latestAnalysis')
            ->setLabel('Poslední analýza')
            ->hideOnForm();

        yield IntegerField::new('analysisCount')
            ->setLabel('Počet analýz')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('lastAnalyzedAt')
            ->setLabel('Analyzováno')
            ->hideOnForm();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt')
            ->setLabel('Aktualizováno')
            ->hideOnForm()
            ->hideOnIndex();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);
    }

    public function triggerAnalysis(): void
    {
        // TODO: Implement analysis trigger via Messenger
        $this->addFlash('info', 'Analýza bude spuštěna na pozadí.');
    }
}
