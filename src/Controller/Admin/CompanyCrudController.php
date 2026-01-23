<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Company;
use App\Entity\User;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class CompanyCrudController extends AbstractCrudController
{
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $qb;
        }

        $alias = $qb->getRootAliases()[0];
        $qb->join(sprintf('%s.leads', $alias), 'leads');

        if ($user->isAdmin()) {
            $tenantUsers = $user->getTenantUsers();
            $userIds = array_map(static fn (User $u) => $u->getId(), $tenantUsers);
            $qb->andWhere('leads.user IN (:tenantUsers)')
                ->setParameter('tenantUsers', $userIds);
        } else {
            $qb->andWhere('leads.user = :currentUser')
                ->setParameter('currentUser', $user->getId());
        }

        // Use GROUP BY instead of DISTINCT - PostgreSQL can't compare JSON columns
        $qb->groupBy(sprintf('%s.id', $alias));

        return $qb;
    }

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
        $isNew = $pageName === Crud::PAGE_NEW;

        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        $icoField = TextField::new('ico')
            ->setLabel('IČO')
            ->setRequired(true);
        if ($isNew) {
            $icoField->setHelp('Zadejte IČO firmy. Ostatní údaje se doplní automaticky z ARES.');
        }
        yield $icoField;

        yield TextField::new('name')
            ->setLabel('Název')
            ->hideWhenCreating();

        yield TextField::new('city')
            ->setLabel('Město')
            ->hideWhenCreating();

        // Následující pole jsou skrytá při vytváření a v indexu - doplní se z ARES
        if (!$isNew) {
            yield TextField::new('dic')
                ->setLabel('DIČ')
                ->hideOnIndex();

            yield TextField::new('legalForm')
                ->setLabel('Právní forma')
                ->hideOnIndex();

            yield TextField::new('street')
                ->setLabel('Ulice')
                ->hideOnIndex();

            yield TextField::new('postalCode')
                ->setLabel('PSČ')
                ->hideOnIndex();

            yield TextField::new('businessStatus')
                ->setLabel('Status')
                ->hideOnIndex();
        }

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
