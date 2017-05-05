<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class Cronjob extends CI_Controller {

    function __construct() {
        parent::__construct();
    }

    public function index() {
        $this->db->select("*");
        $this->db->from('team_subscription');
        $this->db->where('auto_renual_plan', 1);
        $this->db->where('payment_made_by', 'mobile');
        $this->db->where('refresh_token !=', '');
        $result = $this->db->get()->result();
        if (count($result) > 0) {
            foreach ($result as $key => $value) {
                $current_date = date('Y-m-d');
                $expiry_date = date("Y-m-d", strtotime($value->subscription_expiry_date));
                $paymentArr = array(
                    'id' => $value->id,
                    'subscription_plan' => $value->subscription_plan,
                    'amount' => $value->amount,
                    'refresh_token' => $value->refresh_token,
                    'client_metadata_id' => $value->client_metadata_id,
                    'email' => $value->email,
                    'first_name' => $value->first_name
                );
                if ($current_date == $expiry_date) {
                    $this->recurring_payment($paymentArr);
                }
            }
        }
    }

    public function recurring_payment($data) {
        //call paypal outh2 token curl API
        $access_token = $this->obtainOAuth2Tokens($data['refresh_token']);
        //Create a Payment Using a Valid Access Token
        if (!empty($access_token)) {
            $capture_id = $this->createPayment($data, $access_token);
            //capure the Payment Using a Valid capture id
            if (!empty($capture_id)) {
                $state = $this->capturePayment($data, $access_token, $capture_id);
                if (!empty($state) && $state == 'completed') {
                    //update expiry date
                    $payment_date = date('Y-m-d H:i:s');
                    if ($data['subscription_plan'] == 'm') {
                        $subscription_expiry_date = date("Y-m-d H:i:s", strtotime("+1 month", strtotime($payment_date)));
                    } else {
                        $subscription_expiry_date = date("Y-m-d H:i:s", strtotime("+1 year", strtotime($payment_date)));
                    }
                    $this->db->where('id', $data['id']);
                    $this->db->update('team_subscription', array('subscription_date' => $payment_date, "subscription_expiry_date" => $subscription_expiry_date));
                    $msg = "Your Recover & Refuel teams Auto payment successfully done";
                    $this->sendMail($data['email'], $data['first_name'], $msg);
                }
            } else {
                $msg = "Your Recover & Refuel teams Auto payment failed, some internal server error";
                $this->sendMail($data['email'], $data['first_name'], $msg);
            }
        } else {
            $msg = "Your Recover & Refuel teams Auto payment failed, some internal server error";
            $this->sendMail($data['email'], $data['first_name'], $msg);
        }
    }

    //call paypal outh2 token curl API
    public function obtainOAuth2Tokens($refresh_token) {
        $oauth2_url = 'https://api.sandbox.paypal.com/v1/oauth2/token';
        $oauth2_data = 'grant_type=refresh_token&refresh_token=' . $refresh_token . '';
        $curl = curl_init($oauth2_url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_USERPWD, CLIENT_ID . ":" . CLIENT_SECRET);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $oauth2_data);
        $response = curl_exec($curl);
        $result = json_decode($response, true);
        $info = curl_getinfo($curl);
        curl_close($curl);
        if (isset($result['access_token'])) {
            return $result['access_token'];
        }
    }

    //Create a Payment Using a Valid Access Token
    public function createPayment($data, $access_token) {
        $client_metadata_id = $data['client_metadata_id'];
        $amount = $data['amount'];
        $paymentArr = array(
            "intent" => "authorize",
            "redirect_urls" =>
            array(
                "return_url" => "http://www.paypal.com/return",
                "cancel_url" => "http://www.paypal.com/cancel"
            ),
            "payer" => array("payment_method" => "paypal"),
            "transactions" => array(array("amount" => array("currency" => "USD", "total" => $amount), "description" => "future of sauces")
        ));
        $payment_string = json_encode($paymentArr);
        $curl = curl_init('https://api.sandbox.paypal.com/v1/payments/payment');
        curl_setopt_array($curl, array(
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'PayPal-Client-Metadata-Id: ' . $client_metadata_id,
                'Authorization: Bearer ' . $access_token
            ), CURLOPT_POSTFIELDS => $payment_string)
        );
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        $data = json_decode($response, TRUE);
        curl_close($curl);
        if (isset($data['transactions'][0]['related_resources'][0]['authorization']['id'])) {
            return $data['transactions'][0]['related_resources'][0]['authorization']['id'];
        }
    }

    //capure the Payment Using a valid capture id
    public function capturePayment($data, $access_token, $capture_id) {
        $amount = $data['amount'];
        $paymentArr = array("amount" => array("currency" => "USD", "total" => $amount), "is_final_capture" => TRUE);
        $capture_data = json_encode($paymentArr);
        $capture_url = "https://api.sandbox.paypal.com/v1/payments/authorization/" . $capture_id . "/capture";
        $curl = curl_init($capture_url);
        curl_setopt_array($curl, array(
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ), CURLOPT_POSTFIELDS => $capture_data)
        );
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        curl_close($curl);
        $result = json_decode($response, TRUE);
        if (isset($result['state'])) {
            return $result['state'];
        }
    }

    public function sendMail($email, $first_name, $msg) {
        //$message = "<p style='text-align:center;'><img src='" . base_url() . "assets/images/mail_logo.png'></p>";
        //$message .="<br/>";
        //$message .="<br/>";
        $message  = "Hey " . $first_name . ",";
        $message .="<br/>";
        $message .="<br/>";
        $message .=$msg;
        $message .="<br/>";
        $message .="<br/>";
        $message .="Thank you,";
        $message .="<br/>";
        $message .="Recover & Refuel";
        $message .="<p style='color:#999999;'>© Recover & Refuel. All Rights Reserved. R&R is not a TeamSnapservice, but it does use its Application Program Interface to make your sporting life easier.</p>";

//        $headers = 'From: RecoverandRefuel <krunal@cannydoer.com>' . "\r\n";
//        $headers .='Reply-To: <' . $email . '>' . "\r\n";
//        $headers .='X-Mailer: PHP/' . phpversion();
//        $headers .= "MIME-Version: 1.0\r\n";
//        $headers .= "Content-type: text/html; charset=utf-8\r\n";
//        mail($email, 'PayPal Payment Notification', $message, $headers);
        $this->load->library('email');
        $this->email->set_newline("\r\n");
        $this->email->set_mailtype("html");
        $this->email->from('krunal@cannydoer.com', 'RecoverandRefuel');
        $this->email->to($email);
        $this->email->subject('PayPal Payment Notification');
        $this->email->message($message);
        $this->email->send();
    }

}
