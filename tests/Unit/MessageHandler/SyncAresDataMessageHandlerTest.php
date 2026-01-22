<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Company;
use App\Message\SyncAresDataMessage;
use App\MessageHandler\SyncAresDataMessageHandler;
use App\Service\Company\CompanyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyncAresDataMessageHandlerTest extends TestCase
{
    private CompanyService&MockObject $companyService;
    private LoggerInterface&MockObject $logger;
    private SyncAresDataMessageHandler $handler;

    protected function setUp(): void
    {
        $this->companyService = $this->createMock(CompanyService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new SyncAresDataMessageHandler(
            $this->companyService,
            $this->logger,
        );
    }

    public function testInvoke_emptyIcos_logsWarningAndReturns(): void
    {
        $message = new SyncAresDataMessage([]);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Empty IČO list for ARES sync');

        $this->companyService->expects(self::never())
            ->method('findOrCreateByIco');

        ($this->handler)($message);
    }

    public function testInvoke_withIcos_syncsEach(): void
    {
        $icos = ['12345678', '87654321'];
        $message = new SyncAresDataMessage($icos);

        $company = $this->createMock(Company::class);
        $company->method('getAresData')->willReturn(null);
        $company->method('getName')->willReturn('Test Company');

        $this->companyService->expects(self::exactly(2))
            ->method('findOrCreateByIco')
            ->willReturn($company);

        $this->logger->expects(self::exactly(4))
            ->method(self::logicalOr('info', 'debug'));

        ($this->handler)($message);
    }

    public function testInvoke_allFailed_throwsException(): void
    {
        $icos = ['12345678', '87654321'];
        $message = new SyncAresDataMessage($icos);

        $this->companyService->expects(self::exactly(2))
            ->method('findOrCreateByIco')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('All ARES syncs failed (2 IČOs)');

        ($this->handler)($message);
    }

    public function testInvoke_partialFailure_doesNotThrow(): void
    {
        $icos = ['12345678', '87654321'];
        $message = new SyncAresDataMessage($icos);

        $company = $this->createMock(Company::class);
        $company->method('getAresData')->willReturn(null);
        $company->method('getName')->willReturn('Test Company');

        $this->companyService->expects(self::exactly(2))
            ->method('findOrCreateByIco')
            ->willReturnCallback(function (string $ico) use ($company) {
                if ($ico === '12345678') {
                    return $company;
                }

                return null;
            });

        // Should complete without throwing
        ($this->handler)($message);
    }
}
