<?php

/**
 * 自己写别抄，抄NMB抄
 */

namespace App\Payments;

use Stripe\Charge;
use Stripe\Error\SignatureVerification;
use Stripe\Source;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeWepay
{
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'currency' => [
                'label' => '货币单位',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => '',
                'type' => 'input',
            ],
            'return_domain' => [
                'label' => '返回域名',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $currency = $this->config['currency'];
        $exchange = $this->exchange('CNY', strtoupper($currency));
        if (!$exchange) {
            abort(500, 'Currency conversion has timed out, please try again later');
        }
        Stripe::setApiKey($this->config['stripe_sk_live']);

        if (!empty($this->config['return_domain'])) {
            $returnUrl = $this->config['return_domain'] . '#/order/' . $order['trade_no'];
        } else {
            $returnUrl = $order['return_url'];
        }

        $source = Source::create([
            'amount' => floor($order['total_amount'] * $exchange),
            'currency' => $currency,
            'type' => 'wechat',
            'statement_descriptor' => $order['trade_no'],
            'metadata' => [
                'user_id' => $order['user_id'],
                'out_trade_no' => $order['trade_no'],
                'identifier' => ''
            ],
            'redirect' => [
                'return_url' => $returnUrl
            ]
        ]);
        if (!$source['wechat']['qr_code_url']) {
            abort(500, 'Payment gateway request failed');
        }
        return [
            'type' => 0,
            'data' => $source['wechat']['qr_code_url']
        ];
    }

    public function notify($params)
    {
        Stripe::setApiKey($this->config['stripe_sk_live']);
        try {
            $event = Webhook::constructEvent(
                file_get_contents('php://input'),
                $_SERVER['HTTP_STRIPE_SIGNATURE'],
                $this->config['stripe_webhook_key']
            );
        } catch (SignatureVerification $e) {
            abort(400);
        }
        switch ($event->type) {
            case 'source.chargeable':
                $object = $event->data->object;
                Charge::create([
                    'amount' => $object->amount,
                    'currency' => $object->currency,
                    'source' => $object->id,
                    'metadata' => json_decode($object->metadata, true)
                ]);
                break;
            case 'charge.succeeded':
                $object = $event->data->object;
                if ($object->status === 'succeeded') {
                    $metaData = isset($object->metadata->out_trade_no) ? $object->metadata : $object->source->metadata;
                    $tradeNo = $metaData->out_trade_no;
                    return [
                        'trade_no' => $tradeNo,
                        'callback_no' => $object->balance_transaction
                    ];
                }
                break;
            default:
                abort(500, 'event is not support');
        }
        die('success');
    }

    private function exchange($from, $to)
    {
        $result = file_get_contents('https://api.exchangerate.host/latest?symbols=' . $to . '&base=' . $from);
        $result = json_decode($result, true);
        return $result['rates'][$to];
    }
}