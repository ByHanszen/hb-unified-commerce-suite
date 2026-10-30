<?php
namespace HB\UCS\Modules\Subscriptions\Payments;

if (!defined('ABSPATH')) exit;

/** Creates or recovers one Mollie payment on the existing renewal order. */
final class RenewalPaymentService {
    public const REQUEST = '_hb_ucs_renewal_payment_request';
    public const KEY = '_hb_ucs_renewal_payment_key';
    public const STARTED = '_hb_ucs_renewal_payment_started';
    public const STATE = '_hb_ucs_renewal_payment_state';
    public const ERROR = '_hb_ucs_renewal_payment_error';
    public const CREDENTIAL = '_hb_ucs_renewal_payment_credential';
    public const REQUEST_HASH = '_hb_ucs_renewal_payment_request_hash';
    private $request;

    public function __construct(callable $request) {
        $this->request = $request;
    }

    public function ensure_payment(\WC_Order $order, array $payload, string $credentialFingerprint) {
        $paymentId = (string) $order->get_meta('_hb_ucs_mollie_payment_id', true);
        if ($paymentId !== '') return $paymentId;
        $saved = $order->get_meta(self::REQUEST, true);
        $state = (string) $order->get_meta(self::STATE, true);
        $request = is_array($saved) && !empty($saved) && $state !== 'rejected' ? $saved : $payload;
        if ((int) ($request['metadata']['order_id'] ?? 0) !== (int) $order->get_id() || (int) ($request['metadata']['subscription_id'] ?? 0) <= 0) {
            return new \WP_Error('hb_ucs_payment_identity_mismatch', __('De betaalopdracht hoort niet bij deze vervolgorder.', 'hb-ucs'));
        }
        if (!is_array($saved) || empty($saved) || $state === 'rejected') {
            $saved = $payload;
            $stored = $saved;
            // Webhook URLs contain the shared webhook token. Keep that secret
            // out of order metadata, which order API clients can read.
            if (isset($stored['webhookUrl'])) $stored['webhookUrl'] = '';
            $order->update_meta_data(self::REQUEST, $stored);
            $order->update_meta_data(self::REQUEST_HASH, hash('sha256', wp_json_encode($saved)));
            $order->update_meta_data(self::KEY, wp_generate_uuid4());
            $order->update_meta_data(self::STARTED, time());
            $order->update_meta_data(self::CREDENTIAL, $credentialFingerprint);
            $order->update_meta_data(self::STATE, 'creating');
            $order->save();
        }
        $key = (string) $order->get_meta(self::KEY, true);
        $started = (int) $order->get_meta(self::STARTED, true);
        if (array_key_exists('webhookUrl', $saved)) $saved['webhookUrl'] = (string) ($payload['webhookUrl'] ?? '');
        $sameRequest = hash_equals((string) $order->get_meta(self::REQUEST_HASH, true), hash('sha256', wp_json_encode($saved)));
        // Mollie only retains idempotency keys for one hour and per credential.
        // Outside that safe window reconcile; never blindly repeat a POST.
        if (!$sameRequest || $key === '' || $started <= 0 || time() - $started >= 55 * MINUTE_IN_SECONDS || (string) $order->get_meta(self::CREDENTIAL, true) !== $credentialFingerprint) {
            $payment = $this->reconcile($saved);
        } else {
            $payment = call_user_func($this->request, 'POST', 'payments', $saved, $key);
        }
        if (!is_wp_error($payment) && (!is_array($payment) || empty($payment['id']))) {
            $payment = new \WP_Error('hb_ucs_mollie_no_payment_id', __('Mollie gaf geen payment id terug. De uitkomst wordt gecontroleerd bij herstel.', 'hb-ucs'));
        }
        if (is_wp_error($payment)) {
            $code = (string) $payment->get_error_code();
            $rejected = $code === 'hb_ucs_no_mollie_key' || (preg_match('/^hb_ucs_mollie_http_4\d\d$/', $code) && !in_array($code, ['hb_ucs_mollie_http_408', 'hb_ucs_mollie_http_409', 'hb_ucs_mollie_http_429'], true));
            $order->update_meta_data(self::STATE, $rejected ? 'rejected' : 'uncertain');
            $order->update_meta_data(self::ERROR, sanitize_text_field($payment->get_error_message()));
            $order->save();
            return $payment;
        }
        $paymentId = (string) $payment['id'];
        $order->update_meta_data('_hb_ucs_mollie_payment_id', $paymentId);
        $order->update_meta_data(self::STATE, 'created');
        $order->delete_meta_data(self::ERROR);
        $order->save();
        if (($payment['status'] ?? '') === 'paid' && !$order->is_paid()) $order->payment_complete($paymentId);
        if (in_array($payment['status'] ?? '', ['failed', 'canceled', 'expired'], true)) $order->update_status('failed');
        return $paymentId;
    }

    private function reconcile(array $payload) {
        $path = 'customers/' . rawurlencode((string) ($payload['customerId'] ?? '')) . '/payments?limit=250';
        $matches = [];
        for ($page = 0; $page < 10; $page++) {
            $response = call_user_func($this->request, 'GET', $path, null, '');
            if (is_wp_error($response)) return $response;
            foreach ((array) ($response['_embedded']['payments'] ?? []) as $payment) {
                if ((string) ($payment['metadata']['order_id'] ?? '') !== (string) ($payload['metadata']['order_id'] ?? '') || (string) ($payment['metadata']['subscription_id'] ?? '') !== (string) ($payload['metadata']['subscription_id'] ?? '')) continue;
                if (($payment['amount']['currency'] ?? '') !== ($payload['amount']['currency'] ?? '') || ($payment['amount']['value'] ?? '') !== ($payload['amount']['value'] ?? '') || ($payment['mandateId'] ?? '') !== ($payload['mandateId'] ?? '') || empty($payment['id'])) continue;
                $matches[(string) $payment['id']] = $payment;
            }
            $next = (string) ($response['_links']['next']['href'] ?? '');
            if ($next === '') {
                if (count($matches) === 1) return reset($matches);
                break;
            }
            $query = [];
            parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            if (empty($query['from']) || !is_string($query['from'])) break;
            $path = 'customers/' . rawurlencode((string) ($payload['customerId'] ?? '')) . '/payments?limit=250&from=' . rawurlencode($query['from']);
        }
        return new \WP_Error('hb_ucs_payment_reconciliation_required', __('De betaaluitkomst is onzeker. Controleer deze vervolgorder in Mollie voordat een nieuwe betaalpoging wordt gestart.', 'hb-ucs'));
    }
}
