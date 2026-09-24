<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ConfigPayload;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\GovernanceSettings;
use OERManager\Service\Llm\LlmSettings;
use PHPUnit\Framework\TestCase;

/**
 * Mapeo entre los settings y el formulario de configuración (TASK-029).
 *
 * Al mover la configuración al menú lateral, estas ~120 líneas dejaron de vivir
 * en `Module.php` —donde no hay forma de probarlas, porque `getConfigForm()`
 * exige el contenedor de Omeka— y pasaron a una clase pura. Lo que aquí se
 * protege es lo que duele si se rompe: que la clave API nunca se devuelva en
 * claro, que un valor vacío no machaque la clave guardada, y que los defectos
 * de cada campo sigan siendo los mismos que antes del traslado.
 */
final class ConfigPayloadTest extends TestCase
{
    /** @param array<string,mixed> $stored */
    private static function reader(array $stored): callable
    {
        return static fn (string $key, mixed $default = null): mixed => $stored[$key] ?? $default;
    }

    public function testTheApiKeyIsNeverSentBackToTheForm(): void
    {
        $data = ConfigPayload::read(self::reader([LlmSettings::API_KEY => 'sk-secreta']));

        $this->assertArrayNotHasKey(LlmSettings::API_KEY, $data);
        $this->assertNotContains('sk-secreta', $data);
    }

    public function testASavedKeySurvivesSubmittingTheFormWithTheFieldBlank(): void
    {
        // El campo se pinta vacío por ser write-only: si el guardado escribiera
        // ese vacío, abrir la configuración y darle a guardar dejaría el módulo
        // sin credenciales sin que nadie lo hubiera pedido.
        $settings = ConfigPayload::write([LlmSettings::API_KEY => '   ']);

        $this->assertArrayNotHasKey(LlmSettings::API_KEY, $settings);
    }

    public function testANewKeyIsSaved(): void
    {
        $settings = ConfigPayload::write([LlmSettings::API_KEY => 'sk-nueva']);

        $this->assertSame('sk-nueva', $settings[LlmSettings::API_KEY]);
    }

    public function testReadFillsInTheDefaultsOfAnUnconfiguredModule(): void
    {
        $data = ConfigPayload::read(self::reader([]));

        $this->assertSame(LlmSettings::PROVIDER_ANTHROPIC, $data[LlmSettings::PROVIDER]);
        $this->assertSame(LlmSettings::DEFAULT_CONTENT_TOKEN_CAP, $data[LlmSettings::CONTENT_TOKEN_CAP]);
        $this->assertSame(LlmSettings::DEFAULT_MAX_TOKENS, $data[LlmSettings::MAX_TOKENS]);
        $this->assertSame(LlmSettings::DEFAULT_VISION_MAX_IMAGES, $data[LlmSettings::VISION_MAX_IMAGES]);
        $this->assertSame(LlmSettings::DEFAULT_VISION_MAX_PDF_BYTES, $data[LlmSettings::VISION_MAX_PDF_BYTES]);
    }

    public function testReadCastsTheSwitchesToBooleanSoTheCheckboxesReflectReality(): void
    {
        $data = ConfigPayload::read(self::reader([
            LlmSettings::ENABLED => '1',
            LlmSettings::VISION_ENABLED => '0',
        ]));

        $this->assertTrue($data[LlmSettings::ENABLED]);
        $this->assertFalse($data[LlmSettings::VISION_ENABLED]);
    }

    public function testReadCoversEveryFieldOfTheForm(): void
    {
        $data = ConfigPayload::read(self::reader([]));

        $expected = array_merge(
            [CurriculumSearch::AXIS_SETTING, CurriculumSearch::FRAMEWORK_SETTING],
            array_values(CurriculumSearch::TYPE_SETTINGS),
            [
                GovernanceSettings::LICENCE_VOCAB_ID,
                GovernanceSettings::RESOURCE_TYPE_VOCAB_ID,
                GovernanceSettings::PUBLISHER_VOCAB_ID,
                GovernanceSettings::REA_TEMPLATE_ID,
                GovernanceSettings::DEFAULT_RIGHTS_HOLDER,
                LlmSettings::ENABLED,
                LlmSettings::PROVIDER,
                LlmSettings::BASE_URL,
                LlmSettings::MODEL,
                LlmSettings::CONTENT_TOKEN_CAP,
                LlmSettings::TEMPERATURE,
                LlmSettings::MAX_TOKENS,
                LlmSettings::EXTRACTION_MODEL,
                LlmSettings::VISION_ENABLED,
                LlmSettings::VISION_MAX_IMAGES,
                LlmSettings::VISION_MAX_PDF_BYTES,
            ]
        );
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $data, "falta $key en el formulario");
        }
    }

    public function testAnUnsetAxisIsStoredAsNullNotAsZero(): void
    {
        // «Sin configurar» tiene que ser null: un 0 sería un id de item válido
        // para el código que luego lo compara.
        $this->assertNull(ConfigPayload::write([CurriculumSearch::AXIS_SETTING => ''])[
            CurriculumSearch::AXIS_SETTING
        ]);
        $this->assertNull(ConfigPayload::write([CurriculumSearch::AXIS_SETTING => '0'])[
            CurriculumSearch::AXIS_SETTING
        ]);
        $this->assertSame(42, ConfigPayload::write([CurriculumSearch::AXIS_SETTING => '42'])[
            CurriculumSearch::AXIS_SETTING
        ]);
    }

    public function testAnUnknownProviderFallsBackInsteadOfBeingStored(): void
    {
        $settings = ConfigPayload::write([LlmSettings::PROVIDER => 'proveedor-inventado']);

        $this->assertSame(LlmSettings::PROVIDER_ANTHROPIC, $settings[LlmSettings::PROVIDER]);
    }

    public function testBothSupportedProvidersAreAccepted(): void
    {
        foreach ([LlmSettings::PROVIDER_ANTHROPIC, LlmSettings::PROVIDER_OPENAI] as $provider) {
            $this->assertSame(
                $provider,
                ConfigPayload::write([LlmSettings::PROVIDER => $provider])[LlmSettings::PROVIDER]
            );
        }
    }

    public function testAnAbsentCheckboxMeansOffNotUnchanged(): void
    {
        // Un checkbox desmarcado no viaja en el POST: si no se interpretara como
        // «apagado», la visión no podría desactivarse nunca desde el formulario.
        $settings = ConfigPayload::write([]);

        $this->assertFalse($settings[LlmSettings::ENABLED]);
        $this->assertFalse($settings[LlmSettings::VISION_ENABLED]);
    }

    public function testZeroedNumbersFallBackToTheirDefault(): void
    {
        $settings = ConfigPayload::write([
            LlmSettings::CONTENT_TOKEN_CAP => '0',
            LlmSettings::VISION_MAX_IMAGES => '0',
        ]);

        $this->assertSame(LlmSettings::DEFAULT_CONTENT_TOKEN_CAP, $settings[LlmSettings::CONTENT_TOKEN_CAP]);
        $this->assertSame(LlmSettings::DEFAULT_VISION_MAX_IMAGES, $settings[LlmSettings::VISION_MAX_IMAGES]);
    }

    public function testAnEmptyTemperatureIsStoredAsEmptySoItIsNotSentToTheProvider(): void
    {
        $this->assertSame('', ConfigPayload::write([LlmSettings::TEMPERATURE => ''])[LlmSettings::TEMPERATURE]);
        $this->assertSame('0.7', ConfigPayload::write([LlmSettings::TEMPERATURE => '0.7'])[
            LlmSettings::TEMPERATURE
        ]);
    }

    public function testTextFieldsAreTrimmed(): void
    {
        $settings = ConfigPayload::write([
            LlmSettings::MODEL => '  claude-opus-5  ',
            CurriculumSearch::FRAMEWORK_SETTING => '  LOMLOE  ',
        ]);

        $this->assertSame('claude-opus-5', $settings[LlmSettings::MODEL]);
        $this->assertSame('LOMLOE', $settings[CurriculumSearch::FRAMEWORK_SETTING]);
    }

    public function testGovernanceIdsDegradeToNullWhenTheyAreNotUsableIds(): void
    {
        $settings = ConfigPayload::write([
            GovernanceSettings::LICENCE_VOCAB_ID => 'no soy un id',
            GovernanceSettings::REA_TEMPLATE_ID => '7',
        ]);

        $this->assertNull($settings[GovernanceSettings::LICENCE_VOCAB_ID]);
        $this->assertSame(7, $settings[GovernanceSettings::REA_TEMPLATE_ID]);
    }

    public function testWriteCoversEveryFieldOfTheFormExceptTheWriteOnlyKey(): void
    {
        $settings = ConfigPayload::write([]);

        foreach (array_keys(ConfigPayload::read(self::reader([]))) as $key) {
            $this->assertArrayHasKey($key, $settings, "el guardado ignora $key");
        }
        $this->assertArrayNotHasKey(LlmSettings::API_KEY, $settings);
    }
}
