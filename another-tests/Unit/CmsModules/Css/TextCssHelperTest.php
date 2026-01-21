<?php declare(strict_types = 1);

namespace Tests\Unit\CmsModules\Css;

use App\CmsModules\Css\TextCssHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\CmsModules\Css\Stub\StubCssValueResolver;

final class TextCssHelperTest extends TestCase
{
    private TextCssHelper $helper;

    private StubCssValueResolver $stubResolver;

    protected function setUp(): void
    {
        $this->stubResolver = new StubCssValueResolver();
        $this->helper = new TextCssHelper($this->stubResolver);
    }

    // ==================== resolveProperties Tests ====================

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_parsesJsonStringToProperties(): void
    {
        $jsonValue = \json_encode([
            'fontFamily' => 'Arial',
            'fontSize' => 16,
            'fontWeight' => 'bold',
        ], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties(['value' => $jsonValue]);

        self::assertEquals(['value' => 'Arial'], $result['fontFamily']);
        self::assertEquals(['value' => 16, 'unit' => 'px'], $result['fontSize']);
        self::assertEquals('bold', $result['fontWeight']);
    }

    #[Test]
    public function resolveProperties_usesDefaultsForMissingProperties(): void
    {
        $result = $this->helper->resolveProperties(['value' => '{}']);

        self::assertEquals(['value' => 'inherit'], $result['fontFamily']);
        self::assertEquals(['value' => 16, 'unit' => 'px'], $result['fontSize']);
        self::assertEquals(400, $result['fontWeight']);
        self::assertEquals('normal', $result['fontStyle']);
        self::assertEquals(['value' => 'inherit'], $result['lineHeight']);
        self::assertEquals(['value' => 'inherit', 'unit' => 'px'], $result['letterSpacing']);
        self::assertEquals('none', $result['textTransform']);
        self::assertEquals('inherit', $result['textAlign']);
        self::assertEquals('auto', $result['textAlignLast']);
        self::assertEquals(['value' => 0, 'unit' => 'px'], $result['textIndent']);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_usesLinkedVariableValueFromResolver(): void
    {
        $variableValue = \json_encode([
            'fontFamily' => 'Georgia',
            'fontSize' => 24,
            'fontWeight' => 600,
        ], \JSON_THROW_ON_ERROR);

        $this->stubResolver->setVariable(1, ['value' => $variableValue]);

        $result = $this->helper->resolveProperties([
            'value' => '{}',
            'linkedVariable' => [
                'id' => 1,
                'type' => 'text',
            ],
        ]);

        self::assertEquals(['value' => 'Georgia'], $result['fontFamily']);
        self::assertEquals(['value' => 24, 'unit' => 'px'], $result['fontSize']);
        self::assertEquals(600, $result['fontWeight']);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_usesLinkedVariableFallbackValue(): void
    {
        // No variable in resolver - will use fallback from linkedVariable
        $variableValue = \json_encode([
            'fontFamily' => 'Times',
            'fontSize' => 18,
        ], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties([
            'value' => '{}',
            'linkedVariable' => [
                'id' => 999,
                'type' => 'text',
                'value' => $variableValue,
            ],
        ]);

        self::assertEquals(['value' => 'Times'], $result['fontFamily']);
        self::assertEquals(['value' => 18, 'unit' => 'px'], $result['fontSize']);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_normalizesPropertyValueWithUnit(): void
    {
        $jsonValue = \json_encode([
            'fontSize' => ['value' => 18, 'unit' => 'rem'],
        ], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties(['value' => $jsonValue]);

        self::assertEquals(['value' => 18, 'unit' => 'rem'], $result['fontSize']);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_preservesVariableInPropertyValue(): void
    {
        $variable = ['id' => 10, 'type' => 'number', 'value' => 20];
        $jsonValue = \json_encode([
            'fontSize' => ['value' => 16, 'unit' => 'px', 'variable' => $variable],
        ], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties(['value' => $jsonValue]);

        $fontSize = $result['fontSize'];
        self::assertIsArray($fontSize);
        self::assertArrayHasKey('variable', $fontSize);
        self::assertEquals($variable, $fontSize['variable']);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_resolvesVariableOnIndividualProperty(): void
    {
        // Create mock Variable that returns value "24"
        $mockVariable = $this->createMock(\App\Variables\Database\Variable::class);
        $mockVariable->method('getValue')->willReturn('24');

        $this->stubResolver->setVariableEntity(10, $mockVariable);

        $jsonValue = \json_encode([
            'fontSize' => ['value' => 16, 'unit' => 'px', 'variable' => ['id' => 10, 'value' => 18]],
        ], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties(['value' => $jsonValue]);

        $fontSize = $result['fontSize'];
        self::assertIsArray($fontSize);
        // Should use value from repository (24), not original (16) or fallback (18)
        self::assertEquals('24', $fontSize['value']);
        self::assertEquals('px', $fontSize['unit']);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_usesVariableFallbackWhenEntityNotFound(): void
    {
        // No variable entity set - should use fallback value from variable array
        $jsonValue = \json_encode([
            'fontSize' => ['value' => 16, 'unit' => 'px', 'variable' => ['id' => 999, 'value' => 20]],
        ], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties(['value' => $jsonValue]);

        $fontSize = $result['fontSize'];
        self::assertIsArray($fontSize);
        // Should use fallback value (20) since variable entity not found
        self::assertEquals(20, $fontSize['value']);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_handlesTextDecorationAsString(): void
    {
        $jsonValue = \json_encode([
            'textDecoration' => 'underline',
        ], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties(['value' => $jsonValue]);

        self::assertIsArray($result['textDecoration']);
        self::assertEquals('underline', $result['textDecoration']['line']);
        self::assertEquals('solid', $result['textDecoration']['style']);
        self::assertEquals('inherit', $result['textDecoration']['color']);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_handlesTextDecorationAsArray(): void
    {
        $jsonValue = \json_encode([
            'textDecoration' => [
                'line' => 'line-through',
                'style' => 'dashed',
                'color' => '#333',
                'thickness' => 3,
            ],
        ], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties(['value' => $jsonValue]);

        self::assertIsArray($result['textDecoration']);
        self::assertEquals('line-through', $result['textDecoration']['line']);
        self::assertEquals('dashed', $result['textDecoration']['style']);
        self::assertEquals('#333', $result['textDecoration']['color']);
        self::assertEquals(3, $result['textDecoration']['thickness']);
    }

    #[Test]
    public function resolveProperties_handlesNullInput(): void
    {
        $result = $this->helper->resolveProperties(null);

        self::assertEquals(TextCssHelper::DEFAULT_TEXT_PROPERTIES, $result);
    }

    /**
     * @throws \JsonException
     */
    #[Test]
    public function resolveProperties_handlesStringInput(): void
    {
        $jsonValue = \json_encode(['fontFamily' => 'Helvetica'], \JSON_THROW_ON_ERROR);

        $result = $this->helper->resolveProperties($jsonValue);

        self::assertEquals(['value' => 'Helvetica'], $result['fontFamily']);
    }

    // ==================== toCSS Tests ====================

    #[Test]
    public function toCSS_generatesBasicTextProperties(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'Arial'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 700,
            'fontStyle' => 'italic',
            'lineHeight' => ['value' => 1.5],
            'letterSpacing' => ['value' => 2, 'unit' => 'px'],
            'textTransform' => 'uppercase',
            'textAlign' => 'center',
            'textIndent' => ['value' => 10, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('Arial', $css['font-family']);
        self::assertEquals('16px', $css['font-size']);
        self::assertEquals('700', $css['font-weight']);
        self::assertEquals('italic', $css['font-style']);
        self::assertEquals('1.5', $css['line-height']);
        self::assertEquals('2px', $css['letter-spacing']);
        self::assertEquals('uppercase', $css['text-transform']);
        self::assertEquals('center', $css['text-align']);
        self::assertEquals('10px', $css['text-indent']);
    }

    #[Test]
    public function toCSS_handlesInheritValues(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 'inherit'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('inherit', $css['font-family']);
        self::assertEquals('inherit', $css['font-size']);
        self::assertEquals('inherit', $css['line-height']);
        self::assertEquals('inherit', $css['letter-spacing']);
        self::assertEquals('inherit', $css['text-align']);
    }

    #[Test]
    public function toCSS_generatesTextDecorationUnderline(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => [
                'line' => 'underline',
                'color' => '#FF0000',
                'style' => 'solid',
                'thickness' => 2,
                'thicknessUnit' => 'px',
                'underlineOffset' => 4,
                'underlineOffsetUnit' => 'px',
            ],
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('underline', $css['text-decoration-line']);
        self::assertEquals('#FF0000', $css['text-decoration-color']);
        self::assertEquals('solid', $css['text-decoration-style']);
        self::assertEquals('2px', $css['text-decoration-thickness']);
        self::assertEquals('4px', $css['text-underline-offset']);
    }

    #[Test]
    public function toCSS_generatesTextDecorationLineThrough(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => [
                'line' => 'line-through',
                'color' => 'inherit',
                'style' => 'wavy',
                'thickness' => 1,
                'thicknessUnit' => 'px',
            ],
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('line-through', $css['text-decoration-line']);
        self::assertEquals('wavy', $css['text-decoration-style']);
        self::assertArrayNotHasKey('text-underline-offset', $css);
    }

    #[Test]
    public function toCSS_generatesTextDecorationNone(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => [
                'line' => 'none',
            ],
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('none', $css['text-decoration']);
        self::assertArrayNotHasKey('text-decoration-line', $css);
    }

    #[Test]
    public function toCSS_generatesTextShadowWhenEnabled(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => [
                'enabled' => true,
                'x' => 2,
                'y' => 3,
                'blur' => 4,
                'color' => 'rgba(0,0,0,0.8)',
            ],
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('2px 3px 4px rgba(0,0,0,0.8)', $css['text-shadow']);
    }

    #[Test]
    public function toCSS_generatesTextShadowNoneWhenDisabled(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => [
                'enabled' => false,
                'x' => 2,
                'y' => 3,
                'blur' => 4,
                'color' => 'rgba(0,0,0,0.8)',
            ],
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('none', $css['text-shadow']);
    }

    #[Test]
    public function toCSS_addsTextAlignLastForJustify(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'justify',
            'textAlignLast' => 'center',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('justify', $css['text-align']);
        self::assertEquals('center', $css['text-align-last']);
    }

    #[Test]
    public function toCSS_doesNotAddTextAlignLastForNonJustify(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'center',
            'textAlignLast' => 'right',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertArrayNotHasKey('text-align-last', $css);
    }

    #[Test]
    public function toCSS_usesInheritAlignFallbackOption(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties, ['inheritAlignFallback' => 'left']);

        self::assertEquals('left', $css['text-align']);
    }

    #[Test]
    public function toCSS_handlesDifferentUnits(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 1.5, 'unit' => 'rem'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 1.8],
            'letterSpacing' => ['value' => 0.05, 'unit' => 'em'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 2, 'unit' => 'em'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('1.5rem', $css['font-size']);
        self::assertEquals('0.05em', $css['letter-spacing']);
        self::assertEquals('2em', $css['text-indent']);
    }

    #[Test]
    public function toCSS_handlesAutoUnderlineOffset(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => [
                'line' => 'underline',
                'color' => 'inherit',
                'style' => 'solid',
                'thickness' => 1,
                'thicknessUnit' => 'px',
                'underlineOffset' => 'auto',
                'underlineOffsetUnit' => 'px',
            ],
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('auto', $css['text-underline-offset']);
    }

    #[Test]
    public function toCSS_generatesTextDecorationWithMultipleLines(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => [
                'line' => 'underline line-through',
                'color' => '#000',
                'style' => 'double',
                'thickness' => 1,
                'thicknessUnit' => 'px',
            ],
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('underline line-through', $css['text-decoration-line']);
        self::assertEquals('double', $css['text-decoration-style']);
    }

    // ==================== toCSSString Tests ====================

    #[Test]
    public function toCSSString_generatesValidCssString(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'Arial'],
            'fontSize' => ['value' => 18, 'unit' => 'px'],
            'fontWeight' => 700,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 1.6],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'center',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $cssString = $this->helper->toCSSString($properties);

        self::assertStringContainsString('font-family: Arial;', $cssString);
        self::assertStringContainsString('font-size: 18px;', $cssString);
        self::assertStringContainsString('font-weight: 700;', $cssString);
        self::assertStringContainsString('line-height: 1.6;', $cssString);
        self::assertStringContainsString('text-align: center;', $cssString);
        // Should not contain inherit/none/normal values
        self::assertStringNotContainsString('inherit', $cssString);
        self::assertStringNotContainsString('none', $cssString);
    }

    // ==================== getNonDefaultCSS Tests ====================

    #[Test]
    public function getNonDefaultCSS_filtersOutDefaultValues(): void
    {
        $properties = TextCssHelper::DEFAULT_TEXT_PROPERTIES;

        $css = $this->helper->getNonDefaultCSS($properties);

        // All default values should be filtered out
        self::assertEmpty($css);
    }

    #[Test]
    public function getNonDefaultCSS_keepsNonDefaultValues(): void
    {
        $properties = \array_merge(TextCssHelper::DEFAULT_TEXT_PROPERTIES, [
            'fontFamily' => ['value' => 'Georgia'],
            'fontSize' => ['value' => 20, 'unit' => 'px'],
            'fontWeight' => 700,
            'textAlign' => 'center',
        ]);

        $css = $this->helper->getNonDefaultCSS($properties);

        self::assertArrayHasKey('font-family', $css);
        self::assertEquals('Georgia', $css['font-family']);
        self::assertArrayHasKey('font-size', $css);
        self::assertEquals('20px', $css['font-size']);
        self::assertArrayHasKey('font-weight', $css);
        self::assertEquals('700', $css['font-weight']);
        self::assertArrayHasKey('text-align', $css);
        self::assertEquals('center', $css['text-align']);
    }

    // ==================== Default Constants Tests ====================

    #[Test]
    public function defaultTextPropertiesConstant_hasAllRequiredKeys(): void
    {
        $defaults = TextCssHelper::DEFAULT_TEXT_PROPERTIES;

        self::assertArrayHasKey('fontFamily', $defaults);
        self::assertArrayHasKey('fontSize', $defaults);
        self::assertArrayHasKey('fontWeight', $defaults);
        self::assertArrayHasKey('fontStyle', $defaults);
        self::assertArrayHasKey('lineHeight', $defaults);
        self::assertArrayHasKey('letterSpacing', $defaults);
        self::assertArrayHasKey('textDecoration', $defaults);
        self::assertArrayHasKey('textTransform', $defaults);
        self::assertArrayHasKey('textAlign', $defaults);
        self::assertArrayHasKey('textAlignLast', $defaults);
        self::assertArrayHasKey('textIndent', $defaults);
        self::assertArrayHasKey('textShadow', $defaults);
    }

    #[Test]
    public function defaultTextDecorationConstant_hasAllRequiredKeys(): void
    {
        $defaults = TextCssHelper::DEFAULT_TEXT_DECORATION;

        self::assertArrayHasKey('line', $defaults);
        self::assertArrayHasKey('color', $defaults);
        self::assertArrayHasKey('style', $defaults);
        self::assertArrayHasKey('thickness', $defaults);
        self::assertArrayHasKey('thicknessUnit', $defaults);
        self::assertArrayHasKey('underlineOffset', $defaults);
        self::assertArrayHasKey('underlineOffsetUnit', $defaults);
    }

    #[Test]
    public function defaultTextShadowConstant_hasAllRequiredKeys(): void
    {
        $defaults = TextCssHelper::DEFAULT_TEXT_SHADOW;

        self::assertArrayHasKey('enabled', $defaults);
        self::assertArrayHasKey('x', $defaults);
        self::assertArrayHasKey('y', $defaults);
        self::assertArrayHasKey('blur', $defaults);
        self::assertArrayHasKey('color', $defaults);
    }

    // ==================== Edge Cases ====================

    #[Test]
    public function toCSS_handlesEmptyDecoration(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 'inherit'],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => [],
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        // Empty decoration should result in text-decoration: none
        self::assertEquals('none', $css['text-decoration']);
    }

    #[Test]
    public function toCSS_handlesNumericLineHeight(): void
    {
        $properties = [
            'fontFamily' => ['value' => 'inherit'],
            'fontSize' => ['value' => 16, 'unit' => 'px'],
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => ['value' => 1.5],
            'letterSpacing' => ['value' => 'inherit'],
            'textTransform' => 'none',
            'textAlign' => 'inherit',
            'textIndent' => ['value' => 0, 'unit' => 'px'],
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('1.5', $css['line-height']);
    }

    #[Test]
    public function toCSS_handlesSimpleScalarValues(): void
    {
        // Test that scalar values are also accepted (not just arrays with 'value' key)
        $properties = [
            'fontFamily' => 'Arial',
            'fontSize' => 16,
            'fontWeight' => 400,
            'fontStyle' => 'normal',
            'lineHeight' => 1.5,
            'letterSpacing' => 0,
            'textTransform' => 'none',
            'textAlign' => 'center',
            'textIndent' => 0,
            'textDecoration' => TextCssHelper::DEFAULT_TEXT_DECORATION,
            'textShadow' => TextCssHelper::DEFAULT_TEXT_SHADOW,
        ];

        $css = $this->helper->toCSS($properties);

        self::assertEquals('Arial', $css['font-family']);
        self::assertEquals('16px', $css['font-size']);
        self::assertEquals('center', $css['text-align']);
    }
}
