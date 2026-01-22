<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Company;
use App\Message\SyncAresDataMessage;
use App\MessageHandler\SyncAresDataMessageHandler;
use App\Service\Company\CompanyService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * Integration tests for SyncAresDataMessageHandler.
 */
final class SyncAresDataMessageHandlerTest extends MessageHandlerTestCase
{
    // ==================== Success Cases ====================

    #[Test]
    public function invoke_validIcos_syncsAllCompanies(): void
    {
        $company1 = $this->createMockCompany('12345678');
        $company2 = $this->createMockCompany('87654321');

        $companyService = $this->createMock(CompanyService::class);
        $companyService->expects($this->exactly(2))
            ->method('findOrCreateByIco')
            ->willReturnMap([
                ['12345678', true, $company1],
                ['87654321', true, $company2],
            ]);

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['12345678', '87654321']);

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function invoke_singleIco_syncsSuccessfully(): void
    {
        $company = $this->createMockCompany('11111111');

        $companyService = $this->createMock(CompanyService::class);
        $companyService->expects($this->once())
            ->method('findOrCreateByIco')
            ->with('11111111', true)
            ->willReturn($company);

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['11111111']);
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Empty List Cases ====================

    #[Test]
    public function invoke_emptyIcoList_returnsEarlyWithoutError(): void
    {
        $companyService = $this->createMock(CompanyService::class);
        $companyService->expects($this->never())
            ->method('findOrCreateByIco');

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage([]);

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Partial Failure Cases ====================

    #[Test]
    public function invoke_someIcosFail_continuesProcessing(): void
    {
        $company = $this->createMockCompany('22222222');

        $companyService = $this->createMock(CompanyService::class);

        // First IČO succeeds, second fails, third succeeds
        $companyService->expects($this->exactly(3))
            ->method('findOrCreateByIco')
            ->willReturnCallback(function (string $ico) use ($company) {
                if ($ico === 'invalid') {
                    throw new \RuntimeException('ARES API error');
                }

                return $company;
            });

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['11111111', 'invalid', '33333333']);

        // Should not throw - partial success is OK
        $handler($message);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function invoke_serviceReturnsNull_countsAsFailed(): void
    {
        $company = $this->createMockCompany('44444444');

        $companyService = $this->createMock(CompanyService::class);

        // First returns company, second returns null
        $companyService->method('findOrCreateByIco')
            ->willReturnMap([
                ['44444444', true, $company],
                ['55555555', true, null],
            ]);

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['44444444', '55555555']);

        // Should not throw - partial success
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== All Failed Cases ====================

    #[Test]
    public function invoke_allIcosFail_throwsException(): void
    {
        $companyService = $this->createMock(CompanyService::class);
        $companyService->method('findOrCreateByIco')
            ->willThrowException(new \RuntimeException('ARES unavailable'));

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['12345678', '87654321']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/All ARES syncs failed/');

        $handler($message);
    }

    #[Test]
    public function invoke_allIcosReturnNull_throwsException(): void
    {
        $companyService = $this->createMock(CompanyService::class);
        $companyService->method('findOrCreateByIco')
            ->willReturn(null);

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['12345678', '87654321']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/All ARES syncs failed/');

        $handler($message);
    }

    // ==================== Refresh Cases ====================

    #[Test]
    public function invoke_existingCompanyNeedsRefresh_callsRefresh(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getAresData')->willReturn(['name' => 'Old Data']);
        $company->method('needsAresRefresh')->willReturn(true);
        $company->method('getName')->willReturn('Test Company');

        $companyService = $this->createMock(CompanyService::class);
        $companyService->method('findOrCreateByIco')->willReturn($company);
        $companyService->expects($this->once())
            ->method('refreshAresData')
            ->with($company);

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['12345678']);
        $handler($message);
    }

    #[Test]
    public function invoke_existingCompanyDoesNotNeedRefresh_skipsRefresh(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getAresData')->willReturn(['name' => 'Current Data']);
        $company->method('needsAresRefresh')->willReturn(false);
        $company->method('getName')->willReturn('Test Company');

        $companyService = $this->createMock(CompanyService::class);
        $companyService->method('findOrCreateByIco')->willReturn($company);
        $companyService->expects($this->never())
            ->method('refreshAresData');

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['12345678']);
        $handler($message);
    }

    #[Test]
    public function invoke_newCompanyWithNoAresData_skipsRefresh(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getAresData')->willReturn(null);
        $company->method('getName')->willReturn('New Company');

        $companyService = $this->createMock(CompanyService::class);
        $companyService->method('findOrCreateByIco')->willReturn($company);
        $companyService->expects($this->never())
            ->method('refreshAresData');

        $handler = new SyncAresDataMessageHandler(
            $companyService,
            new NullLogger(),
        );

        $message = new SyncAresDataMessage(['12345678']);
        $handler($message);
    }

    // ==================== Helper Methods ====================

    private function createMockCompany(string $ico): Company
    {
        $company = $this->createMock(Company::class);
        $company->method('getIco')->willReturn($ico);
        $company->method('getName')->willReturn('Test Company ' . $ico);
        $company->method('getAresData')->willReturn(null);

        return $company;
    }
}
