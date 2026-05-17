<?php

namespace Drupal\nakopay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles incoming NakoPay webhook events.
 */
class WebhookController extends ControllerBase
{
    /**
     * Process a webhook POST from NakoPay.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = (string) $request->getContent();
        $signature = (string) $request->headers->get('X-NakoPay-Signature', '');

        // Load payment gateway config to get the webhook secret.
        $gateway_storage = $this->entityTypeManager()->getStorage('commerce_payment_gateway');
        $gateways = $gateway_storage->loadByProperties(['plugin' => 'nakopay']);

        if (empty($gateways)) {
            return new JsonResponse(['error' => 'no NakoPay gateway configured'], 400);
        }

        $gateway = reset($gateways);
        $plugin = $gateway->getPlugin();
        $config = $plugin->getConfiguration();
        $secret = $config['webhook_secret'] ?? '';

        if (!$secret || !$signature) {
            return new JsonResponse(['error' => 'missing signature or secret'], 401);
        }

        /** @var \Drupal\nakopay\NakoPayApiClient $api_client */
        $api_client = \Drupal::service('nakopay.api_client');
        if (!$api_client->verifySignature($payload, $signature, $secret)) {
            return new JsonResponse(['error' => 'invalid signature'], 401);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return new JsonResponse(['error' => 'invalid JSON'], 400);
        }

        $event_type = $event['type'] ?? '';
        $invoice = $event['data']['object'] ?? [];
        $order_id = $invoice['metadata']['order_id'] ?? null;

        if (!$order_id) {
            \Drupal::logger('nakopay')->warning('Webhook missing order_id in metadata: @type', ['@type' => $event_type]);
            return new JsonResponse(['ok' => true], 200);
        }

        $order_storage = $this->entityTypeManager()->getStorage('commerce_order');
        $order = $order_storage->load($order_id);

        if (!$order) {
            return new JsonResponse(['error' => 'order not found'], 404);
        }

        $payment_storage = $this->entityTypeManager()->getStorage('commerce_payment');
        $payments = $payment_storage->loadByProperties([
            'order_id' => $order_id,
            'payment_gateway' => $gateway->id(),
        ]);

        switch ($event_type) {
            case 'invoice.paid':
                foreach ($payments as $payment) {
                    if ($payment->getState()->getId() !== 'completed') {
                        $payment->setState('completed');
                        $payment->setRemoteId($invoice['id'] ?? $payment->getRemoteId());
                        $payment->save();
                    }
                }
                // Place the order if still in draft.
                if ($order->getState()->getId() === 'draft') {
                    $order->getState()->applyTransitionById('place');
                    $order->save();
                }
                break;

            case 'invoice.expired':
            case 'invoice.canceled':
                foreach ($payments as $payment) {
                    if (!in_array($payment->getState()->getId(), ['voided', 'refunded', 'completed'])) {
                        $payment->setState('voided');
                        $payment->save();
                    }
                }
                break;

            default:
                \Drupal::logger('nakopay')->info('Unhandled webhook event: @type', ['@type' => $event_type]);
        }

        return new JsonResponse(['ok' => true], 200);
    }
}
