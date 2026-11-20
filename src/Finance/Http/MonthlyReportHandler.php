<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Http;

use FlowBalance\Finance\Infrastructure\SqlMonthlyReportReader;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Stream;
use Mezzio\Session\SessionInterface;
use PDO;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final readonly class MonthlyReportHandler implements RequestHandlerInterface
{
    public function __construct(private bool $export = false) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $month = $query['month'] ?? '';
        if (!is_string($month) || !preg_match('/\A[1-9][0-9]{3}-(0[1-9]|1[0-2])\z/', $month) || ($query['currency'] ?? '') !== 'MXN') {
            return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'Revisa el mes y la moneda']], 422);
        }
        $reader = new SqlMonthlyReportReader($request->getAttribute(PDO::class));
        $report = $reader->read($request->getAttribute(SessionInterface::class)->get('userId'), $month, 'MXN');
        if (!$this->export) {
            return new JsonResponse($report);
        }
        return $this->download($report);
    }

    private function download(array $report): ResponseInterface
    {
        $file = fopen('php://temp', 'w+b');
        fputcsv($file, ['Periodo', 'Fecha', 'Concepto', 'ID de cobro', 'ID de movimiento', 'Moneda original', 'MXN por USD',
            'Abonado original', 'Comisión original', 'Impuesto original', 'Abonado MXN', 'Comisión MXN', 'Impuesto MXN', 'Neto MXN'], escape: '');
        foreach ($report['movements'] as $movement) {
            $category = match ($movement['kind']) {
                'payment' => 'Abono y deducciones',
                'commission' => 'Comisión pagada por separado',
                'tax' => 'Impuesto pagado por separado',
            };
            $category .= $movement['clientPaidAmount'] === null ? ' (sin convertir)' : '';
            $currency = $movement['currency'];
            if (!preg_match('/\A[A-Z]{3}\z/', $currency)) {
                $currency = "'" . $currency;
            }
            fputcsv($file, [$report['month'], $movement['paidOn'], $category, $movement['paymentId'], $movement['movementId'],
                $currency, $movement['exchangeRate'], $movement['originalPaidAmount'], $movement['originalPlatformFee'],
                $movement['originalTaxAmount'], $movement['clientPaidAmount'], $movement['platformFee'], $movement['taxAmount'],
                $movement['netAmount']], escape: '');
        }
        rewind($file);
        return new Response(new Stream($file), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="flow-balance-' . $report['month'] . '.csv"',
        ]);
    }
}
