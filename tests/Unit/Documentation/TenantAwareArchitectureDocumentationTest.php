<?php

namespace Tests\Unit\Documentation;

use Tests\TestCase;

class TenantAwareArchitectureDocumentationTest extends TestCase
{
    public function test_tenant_aware_architecture_documentation_exists_and_covers_enforcement_and_audit_trails(): void
    {
        $path = base_path('docs/tenant-aware-operacao-e-auditoria.md');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $this->assertIsString($contents);
        $this->assertStringContainsString('audit_logs', $contents);
        $this->assertStringContainsString('tenant_operational_block_audits', $contents);
        $this->assertStringContainsString('boundary_rejection_audits', $contents);
        $this->assertStringContainsString('tenant.resolve', $contents);
        $this->assertStringContainsString('GuardTenantOperationalCommandAction', $contents);
        $this->assertStringContainsString('IssueTenantAccessTokenAction', $contents);
        $this->assertStringContainsString('Checklist para novos entrypoints tenant-aware', $contents);
        $this->assertStringContainsString('Nao faca assim', $contents);
    }
}
