<?php

if (!defined('ABSPATH')) {
    exit;
}

class Paigami_WC_Webhook {
    
    private $api;
    
    public function __construct() {
        $this->api = new Paigami_WC_API();
    }
    
    public function handle() {
        $this->api->log('Webhook received', 'debug');
        
        $request_body = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_PAIGAMI_SIGNATURE'] ?? '';
        $timestamp = $_SERVER['HTTP_X_PAIGAMI_TIMESTAMP'] ?? '';
        
        if (empty($request_body)) {
            $this->send_error_response('Empty request body');
            return;
        }
        
        if (!$this->api->validate_webhook_signature($request_body, $signature, $timestamp)) {
            $this->api->log('Invalid webhook signature', 'error');
            $this->send_error_response('Invalid signature');
            return;
        }
        
        $data = json_decode($request_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->send_error_response('Invalid JSON');
            return;
        }
        
        try {
            $this->process_webhook($data);
            $this->send_success_response();
        } catch (Exception $e) {
            $this->api->log('Webhook processing error: ' . $e->getMessage(), 'error');
            $this->send_error_response($e->getMessage());
        }
    }
    
    private function process_webhook($data) {
        if (!isset($data['event']) || !isset($data['data'])) {
            throw new Exception('Invalid webhook structure');
        }
        
        $event = $data['event'];
        $payment_data = $data['data'];
        
        switch ($event) {
            case 'payment.success':
                $this->handle_payment_success($payment_data);
                break;
                
            case 'payment.failed':
                $this->handle_payment_failed($payment_data);
                break;
                
            case 'payment.pending':
                $this->handle_payment_pending($payment_data);
                break;
                
            case 'payment.processing':
                $this->handle_payment_processing($payment_data);
                break;
                
            case 'payment.cancelled':
                $this->handle_payment_cancelled($payment_data);
                break;
                
            default:
                $this->api->log('Unknown webhook event: ' . $event, 'warning');
                break;
        }
    }
    
    private function handle_payment_success($payment_data) {
        $reference = $payment_data['reference'] ?? '';
        
        if (empty($reference)) {
            throw new Exception('Payment reference is missing');
        }
        
        $order = $this->get_order_by_reference($reference);
        
        if (!$order) {
            throw new Exception('Order not found for reference: ' . $reference);
        }
        
        if ($order->is_paid()) {
            $this->api->log('Order already paid: ' . $order->get_id(), 'warning');
            return;
        }
        
        $order->payment_complete();
        $order->add_order_note(
            sprintf(
                __('Paigami payment completed. Reference: %s, Amount: %s %s', 'paigami-woocommerce'),
                $reference,
                number_format($payment_data['amount'] / 100, 2),
                $payment_data['currency']
            ),
            false
        );
        
        $order->update_meta_data('_paigami_status', 'success');
        $order->update_meta_data('_paigami_paid_at', current_time('mysql'));
        
        if (isset($payment_data['aggregator'])) {
            $order->update_meta_data('_paigami_aggregator', $payment_data['aggregator']['name'] ?? '');
        }
        
        if (isset($payment_data['fees'])) {
            $order->update_meta_data('_paigami_fees', $payment_data['fees']);
        }
        
        $order->save();
        
        $this->api->log('Payment successful for order: ' . $order->get_id(), 'info');
        
        // Trigger WooCommerce payment complete action
        do_action('woocommerce_payment_complete', $order->get_id());
    }
    
    private function handle_payment_failed($payment_data) {
        $reference = $payment_data['reference'] ?? '';
        
        if (empty($reference)) {
            throw new Exception('Payment reference is missing');
        }
        
        $order = $this->get_order_by_reference($reference);
        
        if (!$order) {
            throw new Exception('Order not found for reference: ' . $reference);
        }
        
        if ($order->has_status('failed')) {
            return;
        }
        
        $order->update_status('failed', 
            sprintf(
                __('Paigami payment failed. Reference: %s, Reason: %s', 'paigami-woocommerce'),
                $reference,
                $payment_data['failure_reason'] ?? 'Unknown error'
            )
        );
        
        $order->update_meta_data('_paigami_status', 'failed');
        $order->update_meta_data('_paigami_failed_at', current_time('mysql'));
        
        if (isset($payment_data['failure_reason'])) {
            $order->update_meta_data('_paigami_failure_reason', $payment_data['failure_reason']);
        }
        
        $order->save();
        
        $this->api->log('Payment failed for order: ' . $order->get_id(), 'warning');
    }
    
    private function handle_payment_pending($payment_data) {
        $reference = $payment_data['reference'] ?? '';
        
        if (empty($reference)) {
            throw new Exception('Payment reference is missing');
        }
        
        $order = $this->get_order_by_reference($reference);
        
        if (!$order) {
            throw new Exception('Order not found for reference: ' . $reference);
        }
        
        if (!$order->has_status('pending')) {
            $order->update_status('pending', 
                __('Paigami payment is pending confirmation.', 'paigami-woocommerce')
            );
        }
        
        $order->update_meta_data('_paigami_status', 'pending');
        $order->save();
        
        $this->api->log('Payment pending for order: ' . $order->get_id(), 'info');
    }
    
    private function handle_payment_processing($payment_data) {
        $reference = $payment_data['reference'] ?? '';
        
        if (empty($reference)) {
            throw new Exception('Payment reference is missing');
        }
        
        $order = $this->get_order_by_reference($reference);
        
        if (!$order) {
            throw new Exception('Order not found for reference: ' . $reference);
        }
        
        if (!$order->has_status(array('pending', 'processing'))) {
            $order->update_status('processing', 
                __('Paigami payment is being processed.', 'paigami-woocommerce')
            );
        }
        
        $order->update_meta_data('_paigami_status', 'processing');
        $order->save();
        
        $this->api->log('Payment processing for order: ' . $order->get_id(), 'info');
    }
    
    private function handle_payment_cancelled($payment_data) {
        $reference = $payment_data['reference'] ?? '';
        
        if (empty($reference)) {
            throw new Exception('Payment reference is missing');
        }
        
        $order = $this->get_order_by_reference($reference);
        
        if (!$order) {
            throw new Exception('Order not found for reference: ' . $reference);
        }
        
        if ($order->has_status('cancelled')) {
            return;
        }
        
        $order->update_status('cancelled', 
            sprintf(
                __('Paigami payment cancelled. Reference: %s', 'paigami-woocommerce'),
                $reference
            )
        );
        
        $order->update_meta_data('_paigami_status', 'cancelled');
        $order->update_meta_data('_paigami_cancelled_at', current_time('mysql'));
        $order->save();
        
        $this->api->log('Payment cancelled for order: ' . $order->get_id(), 'warning');
    }
    
    private function get_order_by_reference($reference) {
        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => '_paigami_reference',
            'meta_value' => $reference,
            'return' => 'ids'
        ));
        
        if (empty($orders)) {
            // Try to find by transaction ID
            $orders = wc_get_orders(array(
                'limit' => 1,
                'transaction_id' => $reference,
                'return' => 'ids'
            ));
        }
        
        return !empty($orders) ? wc_get_order($orders[0]) : null;
    }
    
    private function send_success_response() {
        header('HTTP/1.1 200 OK');
        header('Content-Type: application/json');
        echo json_encode(array('status' => 'success'));
        exit;
    }
    
    private function send_error_response($message) {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: application/json');
        echo json_encode(array(
            'status' => 'error',
            'message' => $message
        ));
        exit;
    }
}