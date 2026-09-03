<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
    ) {}

    /**
     * Listado de invoices Stripe del cliente (Mi Negocio).
     * Query: ?limit=24&starting_after=in_xxx
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->integer('limit', 24);
        $startingAfter = $request->query('starting_after');
        $startingAfter = is_string($startingAfter) && $startingAfter !== ''
            ? $startingAfter
            : null;

        try {
            $result = $this->stripe->listInvoicesForUser(
                $request->user(),
                $limit,
                $startingAfter,
            );
        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'invoices' => $result['invoices'],
                'has_more' => $result['has_more'],
            ],
            'errors' => null,
        ]);
    }

    /**
     * Detalle de un invoice (incluye invoice_pdf y hosted_invoice_url).
     */
    public function show(Request $request, string $invoiceId): JsonResponse
    {
        try {
            $invoice = $this->stripe->getInvoiceForUser($request->user(), $invoiceId);
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        } catch (ApiErrorException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'no such invoice') ? 404 : 502;

            return response()->json([
                'success' => false,
                'message' => $status === 404 ? 'Factura no encontrada.' : $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'invoice' => $invoice,
            ],
            'errors' => null,
        ]);
    }
}
