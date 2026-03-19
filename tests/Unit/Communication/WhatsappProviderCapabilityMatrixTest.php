<?php

namespace Tests\Unit\Communication;

use App\Infrastructure\Integration\Whatsapp\WhatsappProviderCapabilityMatrix;
use Tests\TestCase;

class WhatsappProviderCapabilityMatrixTest extends TestCase
{
    public function test_it_exposes_real_capabilities_for_each_provider(): void
    {
        $matrix = app(WhatsappProviderCapabilityMatrix::class);

        $this->assertTrue($matrix->isImplemented('whatsapp_cloud', 'template'));
        $this->assertTrue($matrix->isImplemented('evolution_api', 'text'));
        $this->assertTrue($matrix->isPrepared('evolution_api', 'instance_management'));
        $this->assertContains('template', $matrix->unsupportedFor('gowa'));
        $this->assertTrue($matrix->isImplemented('fake', 'media'));
    }
}
