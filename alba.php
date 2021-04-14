<?php


class PaymentnepalException extends Exception
{
    public function __construct($message, $code)
    {
        $this->code = $code;
        parent::__construct($message);
    }
}


class RecurrentParams
{
    const FIRST = 'first';
    const NEXT = 'next';
    const BY_REQUEST = 'byrequest';

    static function first_pay($url, $comment)
    {
        $fields = array(
            'recurrent_type' => static::FIRST,
            'recurrent_comment' => $comment,
            'recurrent_url' => $url,
            'recurrent_period' => static::BY_REQUEST
        );

        return new RecurrentParams($fields);
    }

    static function next_pay($order_id)
    {
        $fields = array(
            'recurrent_type' => static::NEXT,
            'recurrent_order_id' => $order_id,
        );
        return new RecurrentParams($fields);
    }

    public function __construct($fields)
    {
        $this->fields = $fields;
    }

}


class PaymentnepalService
{
    const BASE_URL = 'https://pay.paymentnepal.com/';
    const CARD_TOKEN_URL = 'https://secure.paymentnepal.com/cardtoken/';
    const CARD_TOKEN_TEST_URL = 'https://test.paymentnepal.com/cardtoken/';
    const CURL_TIMEOUT = 45;

    /**
     * @param integer $service_id service_id
     * @param string $secret secret key from service settings
     */
    public function __construct($service_id, $secret)
    {
        $this->service_id = $service_id;
        $this->secret = $secret;
    }

    /**
     * @brief Logging events, meant to redefine
     * @param string $level debug, info or error
     * @param string $message log message
     */
    protected function _log($level, $message)
    {
        // echo $message . "\n";
    }

    /**
     * @brief Building an RFC 3986 request
     * @param array $queryData request data
     * @param string $argSeparator separator
     * @return string
     */
    protected function _http_build_query_rfc_3986($queryData, $argSeparator='&')
    {
        $r = '';
        $queryData = (array) $queryData;
        if(!empty($queryData))
            {
                foreach($queryData as $k=>$queryVar)
                    {
                        $r .= $argSeparator;
                        $r .= $k;
                        $r .= '=';
                        $r .= rawurlencode($queryVar);
                    }
            }
        return trim($r,$argSeparator);
    }

    /**
     * @brief Create sign from all HTTP request fields
     * @param string $method method: GET, POST, PUT, DELETE
     * @param string $url request URL without params
     * @param string $params GET or POST request params
     * @param string $secretKey secret key from service settings
     * @param string $skipPort in case URL has non standard port
     * @return string
     */
    public function sign($method, $url, $params, $secretKey, $skipPort=False)
    {
        ksort($params, SORT_LOCALE_STRING);

        $url = strtolower($url);
        $urlParsed = parse_url($url);
        $path = isset($urlParsed['path'])?
            rtrim($urlParsed['path'], '/\\').'/': "";
        $host = isset($urlParsed['host'])? $urlParsed['host']: "";
        if (isset($urlParsed['port']) && $urlParsed['port'] != 80) {
            if (!$skipPort) {
                $host .= ":{$urlParsed['port']}";
            }
        }

        $method = strtoupper($method);

        $data = implode("\n",
                        array(
                            $method,
                            $host,
                            $path,
                            $this->_http_build_query_rfc_3986($params)
                        )
        );

        $signature = base64_encode(
            hash_hmac("sha256",
                      "{$data}",
                      "{$secretKey}",
                      TRUE
            )
        );

        return $signature;
    }

    /**
     * @brief HTTP request to Paymentnepal API
     * @param string $url request URL
     * @param string $post request params
     * @throw PaymentnepalException
     * @return array
     */
    protected function _curl($url, $post=False)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, static::CURL_TIMEOUT);

        if ($post) {
            $query = http_build_query($post);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
            $this->_log('info', "Successfully sent POST request to $url, params: $query");
        } else {
            $this->_log('info', "Successfully sent GET request $url");
        }
        $result = curl_exec($ch);

        if ($result === False) {
            $msg = curl_error($ch);
            $this->_log('error', "Unable to send request: $msg");
            throw new PaymentnepalException("Connection error to remote server", 'curl');
        }
        curl_close($ch);

        $answer = json_decode($result);

        if ($answer->status === 'error') {
            $msg = property_exists($answer, 'msg')?$answer->msg:$answer->message;
            $code = property_exists($answer, 'code')?$answer->code:'unknown';
            $this->_log('error', "$msg ($code)");
            throw new Exception($msg, $code);
        } else {
            $this->_log('debug', "Got response: $result");
        }

        return $answer;
    }

    /**
     * @brief Getting a list of payment methods available for service
     * @throw PaymentnepalException
     * @return array of payment methods available
     */
    public function payTypes()
    {
        $check = md5($this->service_id . $this->secret);

        $url = static::BASE_URL . "alba/pay_types/?service_id=$this->service_id&check=$check";
        $answer = $this->_curl($url);
        return $answer->types;
    }

    /**
     * @brief Init payment
     * @param string $pay_type payment method
     * @param string $cost payment amount
     * @param string $name order name
     * @param string $email customer email
     * @param string $order_id unique order id
     * @throw PaymentnepalException
     * @return array
     */
    public function initPayment($pay_type, $cost, $name, $email, $phone,
                                $order_id=False, $commission='partner',
                                $card_token=False,
                                $recurrent_params=False)
    {
        $fields = array(
            "cost" => $cost,
            "name" => $name,
            "email" => $email,
            "phone_number" => $phone,
            "background" => "1",
            "commission" => $commission,
            "type" => $pay_type,
            "service_id" => $this->service_id,
            "version" => "2.0"
        );
        if ($order_id !== False) {
            $fields['order_id'] = $order_id;
        }

        if ($card_token !== False) {
            $fields['card_token'] = $card_token;
        }

        if ($recurrent_params !== False) {
            $fields = array_merge($fields, $recurrent_params->fields);
        }

        $url = static::BASE_URL . "alba/input/";

        $fields['check'] = $this->sign(
            "POST",
            $url,
            $fields,
            $this->secret
        );

        $answer = $this->_curl($url, $fields);
        return $answer;
    }

    /**
     * @brief Getting transaction info
     * @param int $tid transaction id in Paymentnepal billing
     * @return array
     */
    public function transactionDetails($tid)
    {
        $url = static::BASE_URL . "alba/details/";
        $fields = array('tid' => $tid,
                        "version" => "2.0");
        $fields['check'] = $this->sign(
            "POST",
            $url,
            $fields,
            $this->secret
        );
        $answer = $this->_curl($url, $fields);
        return $answer;
    }

    /**
     * @brief Making refund
     * @param string int $tid - transaction id in Paymentnepal billing
     * @param string mixed $amount - refund amount
     * @param string bool $test - is refund test
     * @param string mixed $reason - refund reason
     */
    public function refund($tid, $amount=False, $test=False, $reason=False)
    {
        $url = static::BASE_URL . "alba/refund/";
        $fields = array("version" => "2.0",
                        'tid' => $tid);

        if ($amount) {
            $fields['amount'] = $amount;
        }

        if ($test) {
            $fields['test'] = '1';
        }

        if ($reason) {
            $fields['reason'] = $reason;
        }

        $fields['check'] = $this->sign(
            "POST",
            $url,
            $fields,
            $this->secret
        );
        $answer = $this->_curl($url, $fields);
        return $answer;
    }

    /**
     * @brief getting gate info
     * @param string $gate gate short name
     */
    public function gateDetails($gate)
    {
        $url = static::BASE_URL . "alba/gate_details/";
        $fields = array('version' => "2.0",
                        'gate' => $gate,
                        'service_id' => $this->service_id);
        $fields['check'] = $this->sign(
            "GET",
            $url,
            $fields,
            $this->secret
        );
        $answer = $this->_curl($url . "?" . http_build_query($fields));
        return $answer;
    }

    /**
     * @brief Creating card token
     * @param array $post POST data array
     */
    public function createCardToken($card, $exp_month, $exp_year, $cvc, $test, $card_holder=NULL)
    {
        $month = sprintf('%02s', $exp_month);

        $fields = array(
            'service_id' => $this->service_id,
            'card' => $card,
            'exp_month' => $month,
            'exp_year' => $exp_year,
            'cvc' => $cvc
        );

        if ($card_holder) {
            $fields['card_holder'] = $card_holder;
        }

        $base_url = $test?static::CARD_TOKEN_TEST_URL:static::CARD_TOKEN_URL;

        $answer = $this->_curl($base_url . 'create', $fields);

        return $answer->token;
    }

    /**
     * @brief Handling callback
     * @param array $post POST data array
     */
    public function checkCallbackSign($post)
    {
        $order = array(
            'tid',
            'name',
            'comment',
            'partner_id',
            'service_id',
            'order_id',
            'type',
            'cost',
            'income_total',
            'income',
            'partner_income',
            'system_income',
            'command',
            'phone_number',
            'email',
            'resultStr',
            'date_created',
            'version',
        );
        $params = array();
        foreach($order as $field) {
            if (isset($post[$field])) {
                $params[] = $post[$field];
            }
        }
        $params[] = $this->secret;
        return md5(implode($params)) === $post['check'];
    }
}


class PaymentnepalCallback {

    /**
     * @param array $services a list of services to get callback from
     */
    public function __construct($services)
    {
        $this->services = array();
        foreach($services as $service) {
            $this->services[$service->service_id] = $service;
        }
    }

    /**
     * @brief handling callback
     */
    public function handle($post)
    {
        if (isset($post['service_id'])) {
            $service_id = $post['service_id'];
        } else {
            throw new PaymentnepalException('Required param not found: service_id');
        }

        if (in_array($service_id, array_keys($this->services))) {
            $service = $this->services[$service_id];
            if ($service->checkCallbackSign($post)) {
                $this->callback($post);
            } else {
                throw new PaymentnepalException("Sign error");
            }
        } else {
            throw new PaymentnepalException("Unknown service: $service_id");
        }
    }

    /**
     * @brief handling callback after sign check
     */
    public function callback($data)
    {
        if ($data['command'] === 'process') {
            $this->callbackProcess($data);
        } elseif ($data['command'] === 'success') {
            $this->callbackSuccess($data);
        } elseif ($data['command'] === 'recurrent_cancel') {
            $this->callbackRecurrentCancel($data);
        } elseif ($data['command'] === 'refund') {
            $this->callbackRefund($data);
        } else {
            throw new PaymentnepalException("Unexpected callback type: {$data['command']}");
        }
    }

    /**
     * @brief called for any (including partial) payment
     */
    public function callbackProcess($data)
    {
    }

    /**
     * @brief called for full payment
     */
    public function callbackSuccess($data)
    {
    }

    /**
     * @brief called if cardholder cancelled recurrent payments
     */
    public function callbackRecurrentCancel($data)
    {
    }

    /**
     * @brief refund result
     */
    public function callbackRefund($data)
    {
    }

}
