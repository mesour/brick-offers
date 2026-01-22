<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Uživatel')
            ->setEntityLabelInPlural('Uživatelé')
            ->setSearchFields(['code', 'name', 'email'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, User::PERMISSION_USERS_MANAGE)
            ->setPermission(Action::EDIT, User::PERMISSION_USERS_MANAGE)
            ->setPermission(Action::DELETE, User::PERMISSION_USERS_MANAGE);
    }

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

        if ($user->isAdmin()) {
            // Admin sees themselves and their sub-accounts
            $qb->andWhere(sprintf('%s.id = :userId OR %s.adminAccount = :userId', $alias, $alias))
                ->setParameter('userId', $user->getId()?->toBinary());
        } else {
            // Non-admin users can only see themselves
            $qb->andWhere(sprintf('%s.id = :userId', $alias))
                ->setParameter('userId', $user->getId()?->toBinary());
        }

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield TextField::new('code')
            ->setLabel('Kód')
            ->setRequired(true)
            ->setHelp('Pouze malá písmena, čísla, podtržítka a pomlčky');

        yield TextField::new('name')
            ->setLabel('Jméno')
            ->setRequired(true);

        yield EmailField::new('email')
            ->setLabel('Email')
            ->setRequired(true);

        yield TextField::new('plainPassword')
            ->setLabel('Heslo')
            ->setFormType(PasswordType::class)
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->onlyOnForms()
            ->setHelp('Ponechte prázdné pro zachování stávajícího hesla');

        yield ChoiceField::new('roles')
            ->setLabel('Role')
            ->setChoices([
                'Admin' => User::ROLE_ADMIN,
                'Uživatel' => User::ROLE_USER,
            ])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->hideOnIndex();

        yield ChoiceField::new('permissions')
            ->setLabel('Oprávnění')
            ->setChoices([
                'Leads - číst' => User::PERMISSION_LEADS_READ,
                'Leads - zapisovat' => User::PERMISSION_LEADS_WRITE,
                'Leads - mazat' => User::PERMISSION_LEADS_DELETE,
                'Leads - analyzovat' => User::PERMISSION_LEADS_ANALYZE,
                'Nabídky - číst' => User::PERMISSION_OFFERS_READ,
                'Nabídky - zapisovat' => User::PERMISSION_OFFERS_WRITE,
                'Nabídky - schvalovat' => User::PERMISSION_OFFERS_APPROVE,
                'Nabídky - odesílat' => User::PERMISSION_OFFERS_SEND,
                'Návrhy - číst' => User::PERMISSION_PROPOSALS_READ,
                'Návrhy - schvalovat' => User::PERMISSION_PROPOSALS_APPROVE,
                'Návrhy - odmítat' => User::PERMISSION_PROPOSALS_REJECT,
                'Analýzy - číst' => User::PERMISSION_ANALYSIS_READ,
                'Analýzy - spouštět' => User::PERMISSION_ANALYSIS_TRIGGER,
                'Konkurence - číst' => User::PERMISSION_COMPETITORS_READ,
                'Konkurence - spravovat' => User::PERMISSION_COMPETITORS_MANAGE,
                'Statistiky - číst' => User::PERMISSION_STATS_READ,
                'Nastavení - číst' => User::PERMISSION_SETTINGS_READ,
                'Nastavení - zapisovat' => User::PERMISSION_SETTINGS_WRITE,
                'Uživatelé - číst' => User::PERMISSION_USERS_READ,
                'Uživatelé - spravovat' => User::PERMISSION_USERS_MANAGE,
            ])
            ->allowMultipleChoices()
            ->hideOnIndex()
            ->setHelp('Relevantní pouze pro ne-admin uživatele');

        yield AssociationField::new('adminAccount')
            ->setLabel('Admin účet')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);

        yield BooleanField::new('active')
            ->setLabel('Aktivní');

        yield ArrayField::new('limits')
            ->setLabel('Limity')
            ->hideOnIndex()
            ->setHelp('Pouze pro admin účty - nastavuje se přes CLI');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt')
            ->setLabel('Aktualizováno')
            ->hideOnForm()
            ->hideOnIndex();
    }

    /**
     * @param User $entityInstance
     */
    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->hashPassword($entityInstance);

        // Set admin account for new sub-users
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->isAdmin() && $entityInstance->getAdminAccount() === null && $entityInstance !== $currentUser) {
            $entityInstance->setAdminAccount($currentUser);
            $entityInstance->setRoles([User::ROLE_USER]);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    /**
     * @param User $entityInstance
     */
    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPassword(User $user): void
    {
        /** @var string|null $plainPassword */
        $plainPassword = $user->getPlainPassword ?? null;

        // Check if plainPassword property exists via reflection (added dynamically by form)
        $ref = new \ReflectionClass($user);
        if ($ref->hasProperty('plainPassword')) {
            $prop = $ref->getProperty('plainPassword');
            $prop->setAccessible(true);
            $plainPassword = $prop->getValue($user);
        }

        if ($plainPassword !== null && $plainPassword !== '') {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
        }
    }
}
