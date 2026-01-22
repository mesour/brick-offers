<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Company;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class CompanyCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Company::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Firma')
            ->setEntityLabelInPlural('Firmy')
            ->setSearchFields(['ico', 'name', 'city'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $refreshAresAction = Action::new('refreshAres', 'Aktualizovat ARES', 'fa fa-refresh')
            ->linkToCrudAction('refreshAresData')
            ->displayIf(fn (Company $c) => $c->needsAresRefresh());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $refreshAresAction)
            ->add(Crud::PAGE_DETAIL, $refreshAresAction);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('city'))
            ->add(TextFilter::new('businessStatus'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield TextField::new('ico')
            ->setLabel('IČO')
            ->setRequired(true);

        yield TextField::new('dic')
            ->setLabel('DIČ')
            ->hideOnIndex();

        yield TextField::new('name')
            ->setLabel('Název')
            ->setRequired(true);

        yield TextField::new('legalForm')
            ->setLabel('Právní forma')
            ->hideOnIndex();

        yield TextField::new('street')
            ->setLabel('Ulice')
            ->hideOnIndex();

        yield TextField::new('city')
            ->setLabel('Město');

        yield TextField::new('postalCode')
            ->setLabel('PSČ')
            ->hideOnIndex();

        yield TextField::new('businessStatus')
            ->setLabel('Status')
            ->hideOnIndex();

        yield TextField::new('fullAddress')
            ->setLabel('Adresa')
            ->hideOnForm()
            ->hideOnDetail();

        yield DateTimeField::new('aresUpdatedAt')
            ->setLabel('ARES aktualizace')
            ->hideOnForm();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('updatedAt')
            ->setLabel('Aktualizováno')
            ->hideOnForm()
            ->hideOnIndex();
    }

    public function refreshAresData(): void
    {
        // TODO: Implement ARES refresh via Messenger
        $this->addFlash('info', 'ARES data budou aktualizována na pozadí.');
    }
}
