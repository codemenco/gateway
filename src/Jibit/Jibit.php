<?php

namespace Codemenco\Gateway\Jibit;

use Codemenco\Gateway\Jibit\JibitException;
use Codemenco\Gateway\Jibit\PhpFileCache;
use DateTime;
use Illuminate\Support\Facades\Request;
use Codemenco\Gateway\Enum;
use Codemenco\Gateway\PortAbstract;
use Codemenco\Gateway\PortInterface;

class Jibit extends PortAbstract implements PortInterface
{
    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = 'https://napi.jibit.ir/ppg/v3';
    public $accessToken;
    private $cache;

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;
        $this->cache = new PhpFileCache();
        return $this;
    }

    public function setCustom($user_id, $order_id) {

        $this->user_id = $user_id;
        $this->order_id = $order_id;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        return redirect($this->pspSwitchingUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();
        $this->verifyPayment();

        return $this;
    }

    /**
     * Sets callback url
     * @param $url
     */
    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    /**
     * Gets callback url
     * @return string
     */
    function getCallback()
    {
        if (!$this->callbackUrl)
            $this->callbackUrl = $this->config->get('gateway.jibit.callback-url');

        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }

    /**
     * @return mixed
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }


    /**
     * @param mixed $accessToken
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * @param mixed $refreshToken
     */
    public function setRefreshToken($refreshToken)
    {
        $refreshToken1 = $refreshToken;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws JibitException
     */
    protected function sendPayRequest()
    {
        $dateTime = new DateTime();

        $this->newTransaction();

        $this->generateToken();

        $data = [
            'additionalData' => null,
            'description' => null,
            'clientReferenceNumber' => $this->transactionId(),
            'currency' => 'IRR',
            'userIdentifier' => $this->transactionId(),
            'amount' => $this->amount,
            'localDate' => $dateTime->format('Ymd'),
            'localTime' => $dateTime->format('His'),
            'callbackUrl' => $this->getCallback(),
        ];

        try {
            $response = $this->callCurl('/purchases', $data, true);
        } catch (Exception $e) {
            $this->transactionFailed();
            $this->newLog('JibitCode', $e->getMessage());
            throw $e;
        }

        if (empty($response["purchaseId"])) {
            $this->transactionFailed();
            $cache = new PhpFileCache();
            $error = new JibitException();
            $cache->eraseExpired();
            $this->newLog($response['errors'][0]['code'], JibitException::$errors[$response['errors'][0]['code']]);
            throw $error($response['errors'][0]['code']);
        }
        $this->refId = $response["purchaseId"];
        $this->pspSwitchingUrl = $response["pspSwitchingUrl"];
        $this->transactionSetRefId();

    }

    /**
     * @param bool $isForce
     * @return string
     * @throws Exception
     */
    private function generateToken($isForce = false)
    {
        $cache = new PhpFileCache();
        $cache->eraseExpired();

        if ($isForce === false && $cache->isCached('accessToken')) {
            return $this->setAccessToken($cache->retrieve('accessToken'));
        } else if ($cache->isCached('refreshToken')) {
            $refreshToken = $this->refreshTokens();
            if ($refreshToken !== 'ok') {
                $this->generateNewToken();
            }
        } else {
            $this->generateNewToken();
        }
        return 'unExcepted Err in generateToken.';
    }

    private function refreshTokens()
    {
        echo 'refreshing';
        $data = [
            'accessToken' => str_replace('Bearer ', '', $this->cache->retrieve('accessToken')),
            'refreshToken' => $this->cache->retrieve('refreshToken'),
        ];
        $result = $this->callCurl('/tokens/refresh', $data, false);
        if (empty($result['accessToken'])) {
            return 'Err in refresh token.';
        }
        if (!empty($result['accessToken'])) {
            $this->cache->store('accessToken', 'Bearer ' . $result['accessToken'], 24 * 60 * 60 - 60);
            $this->cache->store('refreshToken', $result['refreshToken'], 48 * 60 * 60 - 60);
            $this->setAccessToken('Bearer ' . $result['accessToken']);
            $this->setRefreshToken($result['refreshToken']);
            return 'ok';
        }

        return 'unExcepted Err in refreshToken.';
    }


    private function generateNewToken()
    {
        $data = [
            'apiKey' => $this->config->get('gateway.jibit.apiKey'),
            'secretKey' => $this->config->get('gateway.jibit.secretKey'),
        ];
        $result = $this->callCurl('/tokens', $data);

        if (empty($result['accessToken'])) {
            return 'Err in generate new token.';
        }
        if (!empty($result['accessToken'])) {
            $this->cache->store('accessToken', 'Bearer ' . $result['accessToken'], 24 * 60 * 60 - 60);
            $this->cache->store('refreshToken', $result['refreshToken'], 48 * 60 * 60 - 60);
            $this->setAccessToken('Bearer ' . $result['accessToken']);
            $this->setRefreshToken($result['refreshToken']);
            return 'ok';
        }
        return 'unExcepted Err in generateNewToken.';
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws JibitException
     */
    protected function userPayment()
    {
        $this->authority = Request::input('Authority');
        $status = Request::input('Status');

        if ($status == 'OK') {
            return true;
        }

//        $this->transactionFailed();
//        $this->newLog(-22, JibitException::$errors[-22]);
//        throw new JibitException(-22);
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws JibitException
     * @throws Verify
     */
    protected function verifyPayment()
    {

        $apiKey = $this->config->get('gateway.jibit.apiKey');
        $apiSecret = $this->config->get('gateway.jibit.secretKey');
        $refNum = $this->refId;

        $jibit = new Jibit($apiKey, $apiSecret);

        // Making payment verify
        $requestResult = $jibit->paymentVerify($refNum);
        if (!empty($requestResult['status']) && $requestResult['status'] === 'SUCCESSFUL') {
            //successful result
            return true;

            //show session detail
            $order = $jibit->getOrderById($refNum);
            if (!empty($order['elements'][0]['pspMaskedCardNumber'])){
                echo 'payer card pan mask: ' .$order['elements'][0]['pspMaskedCardNumber'];
            }

        }
        $this->transactionFailed();
        $this->newLog(0, __('Invalid'));
        throw new JibitException(0);

        return false;
    }

    /**
     * @param $url
     * @param $arrayData
     * @param bool $haveAuth
     * @param int $try
     * @param string $method
     * @return bool|mixed|string
     * @throws Exception
     * @throws JibitException
     */
    private function callCurl($url, $arrayData, $haveAuth = false, $try = 0, $method = 'POST')
    {
        $data = $arrayData;
        $jsonData = json_encode($data);
        $accessToken = '';
        if ($haveAuth) {
            $accessToken = $this->getAccessToken();
        }
        $ch = curl_init($this->serverUrl . $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Jibit.class Rest Api');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: ' . $accessToken,
            'Content-Length: ' . strlen($jsonData)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true);
        curl_close($ch);

        if ($err) {
            $this->transactionFailed();
            $this->newLog(0, $err);
            throw new JibitException($result['errors'][0]['code']);
            //return 'cURL Error #:' . $err;
        }
        if (empty($result['errors'])) {
            return $result;
        }

        if ($haveAuth === true && $result['errors'][0]['code'] === 'security.auth_required') {
            $this->generateToken(true);
            if ($try === 0) {
                return $this->callCurl($url, $arrayData, $haveAuth, 1, $method);
            }

            $this->transactionFailed();
            $this->newLog(0,$result['errors'][0]['code']);
            throw new JibitException($result['errors'][0]['code']);
        }

        return $result;

    }

    /**
     * Send settle request
     *
     * @return bool
     *
     * @throws JibitException
     * @throws SoapFault
     */
    public function paymentVerify($purchaseId)
    {
        $this->generateToken();
        $data = [];
        return $this->callCurl('/purchases/' . $purchaseId . '/verify', $data, true, 0, 'GET');
    }
}
