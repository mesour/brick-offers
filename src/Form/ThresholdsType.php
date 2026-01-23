<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for dynamic analyzer threshold settings.
 */
class ThresholdsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $settings = $options['settings'];

        foreach ($settings as $key => $setting) {
            $fieldOptions = [
                'label' => $setting['label'],
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ];

            if (isset($setting['min'])) {
                $fieldOptions['attr']['min'] = $setting['min'];
            }
            if (isset($setting['max'])) {
                $fieldOptions['attr']['max'] = $setting['max'];
            }
            if (isset($setting['step'])) {
                $fieldOptions['attr']['step'] = $setting['step'];
            }

            // Set scale based on type
            if ($setting['type'] === 'number') {
                $fieldOptions['scale'] = 2;
            }

            $builder->add($key, NumberType::class, $fieldOptions);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['settings'] = $options['settings'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('settings');
        $resolver->setAllowedTypes('settings', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'thresholds';
    }
}
