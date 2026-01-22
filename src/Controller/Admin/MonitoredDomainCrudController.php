<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MonitoredDomain;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class MonitoredDomainCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MonitoredDomain::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Monitorovaná doména')
            ->setEntityLabelInPlural('Monitorované domény')
            ->setSearchFields(['domain', 'url'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $checkNowAction = Action::new('checkNow', 'Zkontrolovat nyní', 'fa fa-sync')
            ->linkToCrudAction('triggerCheck');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $checkNowAction)
            ->add(Crud::PAGE_DETAIL, $checkNowAction)
            ->setPermission(Action::NEW, User::PERMISSION_COMPETITORS_MANAGE)
            ->setPermission(Action::EDIT, User::PERMISSION_COMPETITORS_MANAGE)
            ->setPermission(Action::DELETE, User::PERMISSION_COMPETITORS_MANAGE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield TextField::new('domain')
            ->setLabel('Doména')
            ->setRequired(true);

        yield UrlField::new('url')
            ->setLabel('URL')
            ->setRequired(true);

        yield BooleanField::new('active')
            ->setLabel('Aktivní');

        yield TextField::new('checkFrequency')
            ->setLabel('Frekvence kontroly')
            ->hideOnIndex();

        yield DateTimeField::new('lastCheckedAt')
            ->setLabel('Poslední kontrola')
            ->hideOnForm();

        yield DateTimeField::new('nextCheckAt')
            ->setLabel('Další kontrola')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm()
            ->hideOnIndex();
    }

    public function triggerCheck(): void
    {
        // TODO: Implement domain check via Messenger
        $this->addFlash('info', 'Kontrola domény bude provedena na pozadí.');
    }
}
