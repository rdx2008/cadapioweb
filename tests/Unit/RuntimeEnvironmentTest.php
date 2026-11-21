<?php

namespace Tests\Unit;

use App\Support\RuntimeEnvironment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RuntimeEnvironmentTest extends TestCase
{
    public function test_coolify_url_candidates_and_missing_domain_have_valid_fallbacks(): void
    {
        $this->assertSame('https://loja.example.test', RuntimeEnvironment::url(['APP_URL' => '', 'SERVICE_URL_WEB_8080' => 'https://loja.example.test/']));
        $this->assertSame('https://legado.example.test', RuntimeEnvironment::url(['SERVICE_URL_WEB_8080' => '/', 'SERVICE_URL_WEB' => 'https://legado.example.test']));
        $this->assertSame('https://manual.example.test', RuntimeEnvironment::url(['APP_URL' => 'https://manual.example.test', 'SERVICE_URL_WEB_8080' => 'https://gerado.example.test']));
        $this->assertSame('http://localhost', RuntimeEnvironment::url([]));
        $this->assertSame('http://localhost', RuntimeEnvironment::url(['APP_URL' => '/']));
    }

    public function test_generated_and_existing_32_byte_keys_are_accepted(): void
    {
        RuntimeEnvironment::validateKey('base64:'.base64_encode(str_repeat('a', 32)));
        RuntimeEnvironment::validateKey(str_repeat('b', 32));
        $this->addToAssertionCount(2);
    }

    public function test_invalid_key_reports_actionable_error_without_disclosing_the_value(): void
    {
        try {
            RuntimeEnvironment::validateKey('base64:secret-but-invalid');
            $this->fail('Chave inválida deveria ser recusada.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_KEY', $exception->getMessage());
            $this->assertStringNotContainsString('secret-but-invalid', $exception->getMessage());
        }
    }
}
