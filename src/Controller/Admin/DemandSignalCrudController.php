<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DemandSignal;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class DemandSignalCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return DemandSignal::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Signál poptávky')
            ->setEntityLabelInPlural('Signály poptávky')
            ->setSearchFields(['title', 'source', 'content'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield TextField::new('title')
            ->setLabel('Titulek');

        yield ChoiceField::new('source')
            ->setLabel('Zdroj')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->getLabel(), DemandSignalSource::cases()),
                DemandSignalSource::cases()
            ))
            ->formatValue(fn ($value) => $value instanceof DemandSignalSource ? $value->getLabel() : $value);

        yield ChoiceField::new('signalType')
            ->setLabel('Typ signálu')
            ->setChoices(array_combine(
                array_map(fn ($t) => $t->getLabel(), DemandSignalType::cases()),
                DemandSignalType::cases()
            ))
            ->formatValue(fn ($value) => $value instanceof DemandSignalType ? $value->getLabel() : $value);

        yield UrlField::new('sourceUrl')
            ->setLabel('URL zdroje')
            ->hideOnIndex();

        yield TextareaField::new('content')
            ->setLabel('Obsah')
            ->hideOnIndex();

        yield IntegerField::new('relevanceScore')
            ->setLabel('Relevance');

        yield DateTimeField::new('detectedAt')
            ->setLabel('Detekováno');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnIndex();
    }
}
