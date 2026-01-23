<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AnalysisResult;
use App\Entity\User;
use App\Enum\AnalysisStatus;
use App\Enum\IssueCategory;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

class AnalysisResultCrudController extends AbstractCrudController
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
        $qb->join(sprintf('%s.analysis', $alias), 'analysis')
            ->addSelect('analysis')
            ->join('analysis.lead', 'lead');

        if ($user->isAdmin()) {
            $tenantUsers = $user->getTenantUsers();
            $userIds = array_map(static fn (User $u) => $u->getId(), $tenantUsers);
            $qb->andWhere('lead.user IN (:tenantUsers)')
                ->setParameter('tenantUsers', $userIds);
        } else {
            $qb->andWhere('lead.user = :currentUser')
                ->setParameter('currentUser', $user->getId());
        }

        return $qb;
    }

    public static function getEntityFqcn(): string
    {
        return AnalysisResult::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Výsledek analýzy')
            ->setEntityLabelInPlural('Výsledky analýz')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(array_combine(
                array_map(fn ($s) => $s->value, AnalysisStatus::cases()),
                AnalysisStatus::cases()
            )));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield AssociationField::new('analysis')
            ->setLabel('Analýza');

        yield ChoiceField::new('category')
            ->setLabel('Kategorie')
            ->setChoices(array_combine(
                array_map(fn (IssueCategory $c) => $c->getLabel(), IssueCategory::cases()),
                IssueCategory::cases()
            ));

        yield ChoiceField::new('status')
            ->setLabel('Status')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->value, AnalysisStatus::cases()),
                AnalysisStatus::cases()
            ))
            ->renderAsBadges([
                AnalysisStatus::PENDING->value => 'secondary',
                AnalysisStatus::RUNNING->value => 'warning',
                AnalysisStatus::COMPLETED->value => 'success',
                AnalysisStatus::FAILED->value => 'danger',
            ]);

        yield IntegerField::new('score')
            ->setLabel('Skóre');

        yield IntegerField::new('issueCount')
            ->setLabel('Počet problémů')
            ->hideOnForm();

        yield IntegerField::new('criticalIssueCount')
            ->setLabel('Kritických')
            ->hideOnForm();

        yield TextareaField::new('errorMessage')
            ->setLabel('Chyba')
            ->hideOnIndex();

        yield DateTimeField::new('startedAt')
            ->setLabel('Zahájeno')
            ->hideOnIndex();

        yield DateTimeField::new('completedAt')
            ->setLabel('Dokončeno')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnIndex();
    }
}
