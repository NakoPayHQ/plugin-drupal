<?php

namespace Drupal\nakopay\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Redirects the customer to NakoPay hosted checkout.
 */
class NakoPayOffsiteForm extends BasePaymentOffsiteForm
{
    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state): array
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $this->entity;
        $gateway = $payment->getPaymentGateway()->getPlugin();
        $config = $gateway->getConfiguration();
        $order = $payment->getOrder();

        $api_client = \Drupal::service('nakopay.api_client');
        $amount = $payment->getAmount();

        $result = $api_client->createInvoice(
            $amount->getNumber(),
            $amount->getCurrencyCode(),
            'BTC',
            'Order #' . $order->id(),
            [
                'order_id'  => (string) $order->id(),
                'source'    => 'drupal',
                'store_id'  => (string) $order->getStoreId(),
            ],
            $config['api_key']
        );

        if (!$result['ok']) {
            \Drupal::messenger()->addError(t('Could not create NakoPay invoice. Please try again.'));
            return $form;
        }

        $checkout_url = $result['body']['checkout_url'] ?? '';
        $invoice_id = $result['body']['id'] ?? '';

        if (!$checkout_url) {
            \Drupal::messenger()->addError(t('NakoPay did not return a checkout URL.'));
            return $form;
        }

        // Store invoice ID on the payment for later reference.
        $payment->setRemoteId($invoice_id);
        $payment->save();

        return $this->buildRedirectForm(
            $form,
            $form_state,
            $checkout_url,
            [],
            self::REDIRECT_GET
        );
    }
}
