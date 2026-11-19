<?php
declare(strict_types=1);

namespace FlowBalance\Finance\Http;

use DomainException;
use FlowBalance\Finance\Application\PaymentDeleted;
use FlowBalance\Finance\Application\CreateClient;
use FlowBalance\Finance\Application\CreateProject;
use FlowBalance\Finance\Application\IncomeNotFound;
use FlowBalance\Finance\Application\IncomeValidation;
use FlowBalance\Finance\Application\OwnedResourceChanges;
use FlowBalance\Finance\Application\PaymentAmounts;
use FlowBalance\Finance\Application\PaymentExcess;
use FlowBalance\Finance\Application\PaymentConversion;
use FlowBalance\Finance\Application\PaymentInput;
use FlowBalance\Finance\Application\RecordPayment;
use FlowBalance\Finance\Application\RecordPaymentCharge;
use FlowBalance\Finance\Infrastructure\PostgresOwnedResourceRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Session\SessionInterface;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class IncomeWriteHandler implements RequestHandlerInterface
{
    public function __construct(private string $operation) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $request->getAttribute(SessionInterface::class)->get('userId');
        $database = $request->getAttribute(PDO::class);
        $body = (array) $request->getParsedBody();
        $key = $request->getHeaderLine('Idempotency-Key');

        try {
            return match ($this->operation) {
                'client' => $this->client($database, $userId, $body),
                'project' => $this->project($database, $userId, $body),
                'preview', 'payment' => $this->payment($database, $userId, $body, $key),
                'charge' => $this->charge($database, $userId, $request->getAttribute('id'), $body, $key),
            };
        } catch (IncomeNotFound) {
            return new JsonResponse(['error' => ['code' => 'not_found', 'message' => 'Recurso no disponible']], 404);
        } catch (IncomeValidation $error) {
            return new JsonResponse(['error' => [
                'code' => 'validation_failed', 'message' => $error->getMessage(),
                'fields' => [$error->field => $error->getMessage()],
            ], ...$error->context], 422);
        } catch (PaymentDeleted $error) {
            return new JsonResponse(['error' => ['code' => 'payment_deleted', 'message' => $error->getMessage()]], 409);
        } catch (DomainException $error) {
            return new JsonResponse(['error' => ['code' => 'idempotency_conflict', 'message' => $error->getMessage()]], 409);
        }
    }

    private function client(PDO $database, string $userId, array $body): JsonResponse
    {
        $name = PaymentInput::text($body['name'] ?? null, 'name', 100);
        return new JsonResponse(['id' => (new CreateClient($database))->create($userId, $name), 'name' => $name], 201);
    }

    private function project(PDO $database, string $userId, array $body): JsonResponse
    {
        $name = PaymentInput::text($body['name'] ?? null, 'name', 100);
        $client = $this->identifier($body['clientId'] ?? null);
        $repository = new PostgresOwnedResourceRepository($database);
        if (!$repository->find('clients', $userId, $client, true)) {
            throw new IncomeNotFound();
        }
        PaymentInput::currency($body['agreedCurrency'] ?? null, 'agreedCurrency');
        $amount = PaymentAmounts::normalize($body['agreedAmount'] ?? null, 'agreedAmount');
        $count = $body['agreedPaymentCount'] ?? null;
        if (!is_int($count) || $count < 1) {
            throw new IncomeValidation('agreedPaymentCount', 'Indica una cantidad entera de pagos mayor que cero.');
        }
        $origin = PaymentInput::text($body['acquisitionSource'] ?? null, 'acquisitionSource', 100);
        $id = (new CreateProject($database))->create($userId, $client, $name, [$amount, 'MXN', $count, $origin]);
        return new JsonResponse($repository->detail('projects', $userId, $id), 201);
    }

    private function payment(PDO $database, string $userId, array $body, string $key): JsonResponse
    {
        $projectId = $this->identifier($body['projectId'] ?? null);
        $excess = new PaymentExcess($database);
        $project = $excess->project($userId, $projectId);
        if (!$project) {
            throw new IncomeNotFound();
        }
        $amounts = new PaymentAmounts($body['clientPaidAmount'] ?? null, $body['platformFee'] ?? '0', $body['withheldTaxAmount'] ?? '0');
        $currency = $body['currency'] ?? ($this->operation === 'preview' ? 'MXN' : null);
        if (!in_array($currency, ['MXN', 'USD'], true)) {
            throw new IncomeValidation('currency', 'Selecciona MXN o USD.');
        }
        $rate = PaymentConversion::rate($currency, $body['exchangeRate'] ?? null);
        if ($this->operation === 'preview') {
            $equivalents = (new PaymentConversion($database))->equivalents($amounts, $currency, $rate);
            return new JsonResponse(['receivedAmount' => $amounts->receivedAmount, 'currency' => $currency, 'equivalents' => $equivalents,
                ...$excess->calculate($userId, $projectId, $project['agreed_amount'], $equivalents['clientPaidAmount'])]);
        }
        $date = PaymentInput::date($body['paidOn'] ?? null);
        [$source, $sourceName] = PaymentInput::source($body);
        $reason = PaymentInput::optionalText($body['excessReason'] ?? null, 'excessReason');
        $result = (new RecordPayment($database))->capture($userId, $projectId, $date, PaymentInput::key($key), $amounts, $source, $sourceName, $reason, $currency, $rate);
        $detail = (new PostgresOwnedResourceRepository($database))->detail('payments', $userId, $result['id']);
        $created = $result['created'];
        unset($result['created']);
        return new JsonResponse([...$detail, ...$result], $created ? 201 : 200);
    }

    private function charge(PDO $database, string $userId, mixed $id, array $body, string $key): JsonResponse
    {
        $id = $this->identifier($id);
        if (!(new PostgresOwnedResourceRepository($database))->find('payments', $userId, $id)) {
            throw new IncomeNotFound();
        }
        PaymentInput::currency($body['currency'] ?? null);
        $type = $body['chargeType'] ?? null;
        if (!in_array($type, ['commission', 'tax'], true)) {
            throw new IncomeValidation('chargeType', 'Selecciona comisión o impuesto.');
        }
        [$charge, $created] = (new RecordPaymentCharge($database))->record($userId, $id, PaymentInput::key($key), $type,
            PaymentInput::date($body['paidOn'] ?? null), PaymentAmounts::normalize($body['amount'] ?? null, 'amount'));
        return new JsonResponse($charge, $created ? 201 : 200);
    }

    private function identifier(mixed $value): string
    {
        if (!OwnedResourceChanges::identifier($value)) {
            throw new IncomeNotFound();
        }
        return $value;
    }
}
