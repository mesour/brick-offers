<?php

declare(strict_types=1);

namespace App\Admin\Field;

use App\Enum\Industry;
use App\Form\AnalyzerConfigType;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;

/**
 * Custom EasyAdmin field for intuitive analyzer configuration.
 */
final class AnalyzerConfigField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, ?string $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label ?? 'Konfigurace analyzérů')
            ->setFormType(AnalyzerConfigType::class)
            ->setTemplatePath('admin/field/analyzer_config.html.twig')
            ->setFormTypeOption('industry', null)
            ->addCssClass('field-analyzer-config');
    }

    public function setIndustry(?Industry $industry): self
    {
        $this->setFormTypeOption('industry', $industry);

        return $this;
    }
}
