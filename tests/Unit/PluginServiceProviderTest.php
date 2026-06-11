<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Providers\PluginServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Locks the `script_loader_tag` module-tagging behaviour to the editor-script
 * handle *captured* from register_block_type (see registerBlockAndShortcode),
 * not WP's `<block>-editor-script` naming convention. A divergence between the
 * two would print the block-editor module bundle as a classic script —
 * SyntaxError on its top-level `import`, dead editor.
 */
final class PluginServiceProviderTest extends TestCase
{
    private const FALLBACK_EDITOR_HANDLE = 'paymentform-form-editor-script';

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    protected function tearDown(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_adds_module_type_to_the_captured_editor_handle_when_it_differs_from_the_literal(): void
    {
        $provider = new PluginServiceProvider();
        $capturedHandle = 'paymentform-form-editor-script-2';
        $this->setCapturedEditorHandle($provider, $capturedHandle);

        $tag = $provider->addModuleTypeToScripts(
            '<script src="block-editor.js" id="' . $capturedHandle . '-js"></script>',
            $capturedHandle
        );

        $this->assertStringContainsString('type="module"', $tag);
    }

    public function test_stale_literal_handle_is_not_tagged_once_a_different_handle_was_captured(): void
    {
        $provider = new PluginServiceProvider();
        $this->setCapturedEditorHandle($provider, 'paymentform-form-editor-script-2');

        $original = '<script src="other.js" id="' . self::FALLBACK_EDITOR_HANDLE . '-js"></script>';
        $tag = $provider->addModuleTypeToScripts($original, self::FALLBACK_EDITOR_HANDLE);

        $this->assertSame($original, $tag);
    }

    public function test_falls_back_to_the_conventional_editor_handle_when_none_was_captured(): void
    {
        $provider = new PluginServiceProvider();

        $tag = $provider->addModuleTypeToScripts(
            '<script src="block-editor.js"></script>',
            self::FALLBACK_EDITOR_HANDLE
        );

        $this->assertStringContainsString('type="module"', $tag);
    }

    public function test_adds_module_type_to_the_admin_spa_handle(): void
    {
        $provider = new PluginServiceProvider();

        $tag = $provider->addModuleTypeToScripts(
            '<script src="admin.js"></script>',
            'paymentform-admin'
        );

        $this->assertStringContainsString('type="module"', $tag);
    }

    public function test_leaves_unrelated_handles_untouched(): void
    {
        $provider = new PluginServiceProvider();

        $original = '<script src="block-view.js"></script>';
        $tag = $provider->addModuleTypeToScripts($original, 'paymentform-form-view-script');

        $this->assertSame($original, $tag);
    }

    public function test_does_not_duplicate_an_existing_module_type_attribute(): void
    {
        $provider = new PluginServiceProvider();

        $original = '<script type="module" src="admin.js"></script>';
        $tag = $provider->addModuleTypeToScripts($original, 'paymentform-admin');

        $this->assertSame($original, $tag);
        $this->assertSame(1, substr_count($tag, 'type="module"'));
    }

    /**
     * Simulates registerBlockAndShortcode having captured the real handle WP
     * assigned (which may differ from the conventional name across versions).
     */
    private function setCapturedEditorHandle(PluginServiceProvider $provider, string $handle): void
    {
        (new ReflectionProperty(PluginServiceProvider::class, 'blockEditorScriptHandle'))
            ->setValue($provider, $handle);
    }
}
