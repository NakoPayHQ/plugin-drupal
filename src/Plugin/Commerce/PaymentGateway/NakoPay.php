<?php

namespace Drupal\nakopay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the NakoPay off-site payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "nakopay",
 *   label = @Translation("NakoPay - Bitcoin & Crypto"),
 *   display_label = @Translation("Pay with Bitcoin"),
 *   forms = {
 *     "offsite-payment" = "Drupal\nakopay\PluginForm\NakoPayOffsiteForm",
 *   },
 *   modes = {
 *     "test" = @Translation("Test"),
 *     "live" = @Translation("Live"),
 *   },
 * )
 */
class NakoPay extends OffsitePaymentGatewayBase
{
    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration(): array
    {
        return [
            'api_key'        => '',
            'webhook_secret' => '',
        ] + parent::defaultConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state): array
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        $form['api_key'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Secret API Key'),
            '#description'   => $this->t('Starts with sk_test_ or sk_live_. Get one at <a href="https://nakopay.com/dashboard/api-keys" target="_blank">nakopay.com/dashboard/api-keys</a>.'),
            '#default_value' => $this->configuration['api_key'],
            '#required'      => TRUE,
        ];

        $form['webhook_secret'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Webhook Signing Secret'),
            '#description'   => $this->t('Shown once when you create a webhook endpoint.'),
            '#default_value' => $this->configuration['webhook_secret'],
            '#required'      => TRUE,
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void
    {
        parent::submitConfigurationForm($form, $form_state);
        $values = $form_state->getValue($form['#parents']);
        $this->configuration['api_key'] = $values['api_key'];
        $this->configuration['webhook_secret'] = $values['webhook_secret'];
    }

    /**
     * {@inheritdoc}
     */
    public function onReturn(OrderInterface $order, Request $request): void
    {
        // The user returned from NakoPay hosted checkout.
        // Payment confirmation happens asynchronously via webhook.
        // We mark the payment as "authorization" until webhook confirms.
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment = $payment_storage->create([
            'state'           => 'authorization',
            'amount'          => $order->getBalance(),
            'payment_gateway' => $this->parentEntity->id(),
            'order_id'        => $order->id(),
            'remote_id'       => $request->query->get('invoice_id', ''),
        ]);
        $payment->save();
    }

    /**
     * {@inheritdoc}
     */
    public function onCancel(OrderInterface $order, Request $request): void
    {
        $this->messenger()->addWarning($this->t('Payment was cancelled. You may try again.'));
    }
}
