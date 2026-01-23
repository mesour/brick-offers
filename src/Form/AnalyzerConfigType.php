<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\Industry;
use App\Service\AnalyzerConfigService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for configuring analyzers with an intuitive UI.
 */
class AnalyzerConfigType extends AbstractType
{
    public function __construct(
        private readonly AnalyzerConfigService $analyzerConfigService,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $industry = $options['industry'];

        // Get analyzer schemas for the industry
        $schemas = $this->analyzerConfigService->getAnalyzerSchemas($industry);

        foreach ($schemas as $categoryCode => $schema) {
            $builder->add($categoryCode, AnalyzerItemType::class, [
                'label' => false,
                'schema' => $schema,
            ]);
        }

        // Transform data on submit
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($industry): void {
            $data = $event->getData() ?? [];
            $mergedData = $this->analyzerConfigService->mergeWithDefaults($data, $industry);
            $event->setData($mergedData);
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            // Clean up the data structure for storage
            $cleanData = [];
            foreach ($data as $categoryCode => $config) {
                if (!is_array($config)) {
                    continue;
                }
                $cleanData[$categoryCode] = [
                    'enabled' => $config['enabled'] ?? true,
                    'priority' => (int) ($config['priority'] ?? 5),
                    'thresholds' => $config['thresholds'] ?? [],
                    'ignoreCodes' => array_values(array_filter($config['ignoreCodes'] ?? [])),
                ];
            }
            $event->setData($cleanData);
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['industry'] = $options['industry'];
        $view->vars['schemas'] = $this->analyzerConfigService->getAnalyzerSchemas($options['industry']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'industry' => null,
        ]);
        $resolver->setAllowedTypes('industry', ['null', Industry::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'analyzer_config';
    }
}
