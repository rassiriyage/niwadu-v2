<?php

namespace App\Http\Controllers;

use App\BookingWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BookingPlanningController extends Controller
{
    public function quote(Request $request, BookingWorkflow $workflow): JsonResponse
    {
        return $this->response($workflow->requestQuote($request->user(), $request->except('_token')));
    }

    public function storeIntent(Request $request, BookingWorkflow $workflow): JsonResponse
    {
        try {
            return $this->response($workflow->createIntent($request->user(), $request->except('_token'), (string) $request->header('Idempotency-Key', '')));
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $messages = [
                'quote_expired' => 'This quote has expired. Request a new quote.',
                'quote_changed' => 'This offer has changed. Review a new quote.',
                'quote_unavailable' => 'This offer is no longer available.',
                'quote_already_used' => 'This quote already has a planning intent. Retry its original request.',
                'idempotency_payload_mismatch' => 'This request key was already used for a different quote.',
            ];
            if (! isset($messages[$exception->getMessage()])) {
                throw $exception;
            }

            return response()->json(['code' => $exception->getMessage(), 'message' => $messages[$exception->getMessage()]], 409);
        }
    }

    public function showIntent(Request $request, string $intent, BookingWorkflow $workflow): JsonResponse
    {
        return $this->response($workflow->getIntent($request->user(), $intent));
    }

    private function response(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['checkout_enabled' => false]]);
    }
}
