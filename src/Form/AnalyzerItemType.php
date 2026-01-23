<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for a single analyzer configuration item.
 */
class AnalyzerItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $schema = $options['schema'];

        // Enabled checkbox
        $builder->add('enabled', CheckboxType::class, [
            'label' => false,
            'required' => false,
        ]);

        // Priority slider (1-10)
        $builder->add('priority', RangeType::class, [
            'label' => 'Priorita',
            'attr' => [
                'min' => 1,
                'max' => 10,
                'step' => 1,
                'class' => 'form-range',
            ],
            'required' => false,
        ]);

        // Ignore codes multi-select (only if there are available issue codes)
        if (!empty($schema['availableIssueCodes'])) {
            $choices = [];
            foreach ($schema['availableIssueCodes'] as $code => $def) {
                $choices[$def['title']] = $code;
            }

            $builder->add('ignoreCodes', ChoiceType::class, [
                'label' => 'Ignorovat issue kódy',
                'choices' => $choices,
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'select2',
                ],
            ]);
        }

        // Custom thresholds (dynamically based on settings)
        if (!empty($schema['settings'])) {
            $builder->add('thresholds', ThresholdsType::class, [
                'label' => false,
                'settings' => $schema['settings'],
            ]);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['schema'] = $options['schema'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('schema');
        $resolver->setAllowedTypes('schema', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'analyzer_item';
    }
}
