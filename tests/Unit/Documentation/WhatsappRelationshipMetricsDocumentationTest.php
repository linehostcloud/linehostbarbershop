<?php

namespace Tests\Unit\Documentation;

use Tests\TestCase;

class WhatsappRelationshipMetricsDocumentationTest extends TestCase
{
    public function test_whatsapp_relationship_metrics_documentation_exists_and_covers_direct_and_inferred_cards(): void
    {
        $path = base_path('docs/whatsapp-relacionamento-metricas.md');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $this->assertIsString($contents);
        $this->assertStringContainsString('Lembretes enfileirados', $contents);
        $this->assertStringContainsString('Confirmações manuais enviadas', $contents);
        $this->assertStringContainsString('Clientes ignorados no período', $contents);
        $this->assertStringContainsString('Leituras inferidas', $contents);
        $this->assertStringContainsString('Lembretes com confirmação registrada', $contents);
        $this->assertStringContainsString('Reativações com novo agendamento', $contents);
    }
}
