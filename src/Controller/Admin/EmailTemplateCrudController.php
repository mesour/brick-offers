<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EmailTemplate;
use App\Enum\Industry;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Controller for global/system email templates (user_id IS NULL).
 * These templates are read-only and serve as defaults.
 */
class EmailTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return EmailTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Systémová šablona')
            ->setEntityLabelInPlural('Systémové šablony')
            ->setSearchFields(['name', 'subjectTemplate'])
            ->setDefaultSort(['name' => 'ASC'])
            ->showEntityActionsInlined();
    }

    /**
     * Show only system templates (user_id IS NULL).
     */
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $alias = $qb->getRootAliases()[0];

        // Only show global/system templates (no user assigned)
        $qb->andWhere(sprintf('%s.user IS NULL', $alias));

        return $qb;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE); // System templates are read-only
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('industry')->setChoices(array_combine(
                array_map(fn ($i) => $i->getLabel(), Industry::cases()),
                array_map(fn ($i) => $i->value, Industry::cases())
            )))
            ->add(BooleanFilter::new('isDefault'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield TextField::new('name')
            ->setLabel('Název');

        yield TextField::new('subjectTemplate')
            ->setLabel('Předmět');

        if ($pageName === Crud::PAGE_DETAIL) {
            yield TextareaField::new('bodyTemplate')
                ->setLabel('HTML obsah')
                ->setTemplatePath('admin/field/html_preview.html.twig');
        } else {
            yield TextareaField::new('bodyTemplate')
                ->setLabel('HTML obsah')
                ->hideOnIndex()
                ->setNumOfRows(15);
        }

        yield ChoiceField::new('industry')
            ->setLabel('Odvětví')
            ->setChoices(array_combine(
                array_map(fn ($i) => $i->getLabel(), Industry::cases()),
                Industry::cases()
            ))
            ->allowMultipleChoices(false)
            ->renderExpanded(false);

        yield BooleanField::new('isDefault')
            ->setLabel('Výchozí');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();
    }
}
