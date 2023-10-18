<?php

namespace Codemenco\Gateway\Vendar;

use App\Models\BankAccount\BankAccount;
use DateTime;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Str;
use Codemenco\Gateway\Enum;
use SoapClient;
use Codemenco\Gateway\PortAbstract;
use Codemenco\Gateway\PortInterface;
use Illuminate\Support\Facades\Request;

class Vendar extends PortAbstract implements PortInterface
{

    protected $order_id;
    protected $link;
    protected $validateCard;
    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = 'https://vandar.io/api/ipg/send';

    protected $paymentUrl = 'https://vandar.io/ipg';

    protected $verifyUrl = 'https://vandar.io/api/ipg/verify';

    protected $infoUrl = 'https://vandar.io/api/ipg/2step/transaction';


    public function getOrderId()
    {
        return $this->order_id;
    }

    public function setOrderId($order_id)
    {
        $this->order_id = $order_id;
    }

    public function getLink()
    {
        return $this->link;
    }

    public function setLink($link)
    {
        $this->link = $link;
    }

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount * 10;

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
     * Send pay request to server
     *
     * @return void
     *
     * @throws NextpayException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();
//        if (auth() - check()) {
//            $userCards = auth()->user()->cards->where('status', 'active')->pluck('last_number');
//        }
        $fields = array(
            'api_key' => $this->config->get('gateway.vendar.api_key'),
            'amount' => $this->amount,
            'callback_url' => $this->getCallback(),
            'description' => $this->getCustomDesc()
        );
//        dd($fields);
//        if ($userCards) {
//            array_push($fields, [
//                'allowedCards' => $userCards,
//            ]);
//        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));


        $response = curl_exec($ch);
        $response = json_decode($response);
        curl_close($ch);
        if ($response->status == 1) {
            $this->refId = $response->token;
            $this->link = "$this->paymentUrl/$this->refId";
            $this->transactionSetRefId();

            return true;
        }

        $this->transactionFailed();
        $this->newLog($response->status, $response->errors[0]);
        return redirect()
            ->back()
            ->with(RESPONSE_TYPE_ERROR, $response->errors[0])
            ->withInput();
    }

    /**
     * Gets callback url
     * @return string
     */
    function getCallback()
    {
        if (!$this->callbackUrl) {
            $this->callbackUrl = $this->config->get('gateway.vendar.callback-url');
        }

        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $link = $this->link;

//        dd($this);
        return redirect($link);
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();
        $this->getInfo();
        $this->validateCardNumber();
        if ($this->validateCard) {
        $this->verifyPayment();
        }

        return $this;
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws MellatException
     */
    protected function userPayment()
    {
        $this->refId = Request::get('token');
    }

    public function getInfo()
    {
        $fields = array(
            'api_key' => $this->config->get('gateway.vendar.api_key'),
            'token' => $this->refId,
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->infoUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));


        $response = curl_exec($ch);
        $response = json_decode($response);
        if ($response->status == 1) {
            $this->cardNumber = $response->cardNumber;
            return true;
        } else {
            $this->cardNumber = null;
            return false;
        }
    }

    public function getInfo2()
    {
        $fields = array(
            'api_key' => $this->config->get('gateway.vendar.api_key'),
            'token' => $this->refId,
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->infoUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));


        $response = curl_exec($ch);
        $response = json_decode($response);
        if ($response->status == 1) {
            $this->cardNumber = $response->cardNumber;
        } else {
            $this->cardNumber = null;
        }
        return $response;
    }

    /**
     * Verify user payment from bank server
     *
     * @return bool
     *
     * @throws NextpayException
     * @throws SoapFault
     */
    protected function verifyPayment()
    {
        $fields = array(
            'api_key' => $this->config->get('gateway.vendar.api_key'),
            'token' => $this->refId,
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->verifyUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));


        $response = curl_exec($ch);
        $response = json_decode($response);
//        dd($response->Code);
        curl_close($ch);

        if ($response->status == 1) {
            $this->transactionSucceed();
            $this->newLog('SUCCESS', Enum::TRANSACTION_SUCCEED_TEXT);

            return true;
        }

        $this->transactionFailed();
        $this->newLog($response->status, $response->errors[0]);
        throw new \Exception($response->errors[0]);

    }

    public function validateCardNumber()
    {
        if (auth()->check()) {
            $user_id = auth()->user()->id;
            $last_four = substr($this->cardNumber, -4);
            $userCards = BankAccount::where('user_id',$user_id)
                ->where('is_active', ACTIVE)
                ->where('is_verified',ACTIVE)
                ->whereRaw('SUBSTRING(swift, -4) = '.$last_four)->first();
            if (!empty($userCards)) {
                $this->validateCard = true;
            } else {
                $this->transactionFailed();
                $this->validateCard = false;
                $this->newLog(4444, 'کارت بانکی شما در تریدکس احراز نشده است!');
                throw new \Exception('کارت بانکی شما در تریدکس احراز نشده است!');
            }
        } else {
            $this->validateCard = false;
        }
    }

    /**
     * Sets callback url
     *
     * @param $url
     */
    function setCallback($url)
    {
        $this->callbackUrl = $url;

        return $this;
    }
}
