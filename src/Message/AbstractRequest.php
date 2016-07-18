<?php

namespace Omnipay\Cybersource\Message;

use DOMDocument;
use Guzzle\Http\ClientInterface;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use SimpleXMLElement;
use SoapClient;
use SoapFault;

use Omnipay\Cybersource\BankAccount;
use Omnipay\Cybersource;


use Auth;
use Session;
use Cache;
use stdClass;
use App\Producto;
use App\Libraries\Utils\Carretilla;

/**
 * Authorize.Net Abstract Request
 */
abstract class AbstractRequest extends \Omnipay\Common\Message\AbstractRequest
{
    protected $namespace = "http://ics2ws.com/";

    const LIVE_ENDPOINT = 'https://ics2ws.ic3.com/commerce/1.x/transactionProcessor/CyberSourceTransaction_1.109.wsdl';
    const TEST_ENDPOINT = 'https://ics2wstest.ic3.com/commerce/1.x/transactionProcessor/CyberSourceTransaction_1.109.wsdl';

    const VERSION = '0.1';
    const API_VERSION = '1.109';

    /**
     * @var \stdClass The generated SOAP request, saved immediately before a transaction is run.
     */
    protected $request;

    /**
     * @var \stdClass The retrieved SOAP response, saved immediately after a transaction is run.
     */
    protected $response;

    /**
     * @var float The amount of time in seconds to wait for both a connection and a response. Total potential wait time is this value times 2 (connection + response).
     */
    public $timeout = 10;


    /**
     * Create a new Request
     *
     * @param ClientInterface $httpClient  A Guzzle client to make API calls with
     * @param HttpRequest     $httpRequest A Symfony HTTP request object
     */
    public function __construct(ClientInterface $httpClient, HttpRequest $httpRequest)
    {
        parent::__construct($httpClient, $httpRequest);
        $this->request = $this->createRequest();
    }


    #region Cybersource Soap Building

    public function sendData($data)
    {
        $data = $this->getData();

        $this->request->merchantReferenceCode = $this->getMerchantReferenceCode();
        $this->request->merchantID = $this->getMerchantId();

        $context_options = array(
            'http' => array(
                'timeout' => $this->timeout,
            ),
        );

        $context = stream_context_create($context_options);

        // options we pass into the soap client
        $soap_options = array(
            'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE,        // turn on HTTP compression
            'encoding' => 'utf-8',        // set the internal character encoding to avoid random conversions
            'exceptions' => true,        // throw SoapFault exceptions when there is an error
            'connection_timeout' => $this->timeout,
            'stream_context' => $context,
        );

        // if we're in test mode, don't cache the wsdl
        if ($this->getTestMode()) {
            $soap_options['cache_wsdl'] = WSDL_CACHE_NONE;
        } else {
            $soap_options['cache_wsdl'] = WSDL_CACHE_BOTH;
        }


        try {
            // create the soap client
            $soap = new \SoapClient($this->getEndpoint(), $soap_options);
        } catch (SoapFault $sf) {
            throw new \Exception($sf->getMessage(), $sf->getCode());
        }

        // add the wsse token to the soap object, by reference
        $this->addWsseToken($soap);

        // save the request so you can get back what was generated at any point
        $response = $soap->runTransaction($this->request);

        return $this->response = new CybersourceResponse($this->request, $response);
    }


    /**
     * @return \stdClass
     */
    protected function createRequest()
    {
        // build the class for the request
        $request = new \stdClass();

        // some info CyberSource asks us to add for troubleshooting purposes
        $request->clientLibrary = 'CyberSourcePHP';
        $request->clientLibraryVersion = self::VERSION;
        $request->clientEnvironment = php_uname();

        // this also is pretty stupid, particularly the name
        $purchase_totals = new \stdClass();
        $purchase_totals->currency = 'USD';
        $request->purchaseTotals = $purchase_totals;

        return $request;
    }


    /**
     * @return \stdClass
     */
    protected function createCard()
    {
        /** @var \Omnipay\Common\CreditCard $creditCard */
        $creditCard = $this->getCard();

        $cyberSourceCreditCard = new \stdClass();
        $cyberSourceCreditCard->accountNumber = $creditCard->getNumber();
        $cyberSourceCreditCard->expirationMonth = $creditCard->getExpiryMonth();
        $cyberSourceCreditCard->expirationYear = $creditCard->getExpiryYear();

        if (!is_null($creditCard->getCvv())) {
            $cyberSourceCreditCard->cvNumber = $creditCard->getCvv();
        }

        if (!is_null($this->getCardType())) {
            $cyberSourceCreditCard->cardType = $this->getCardType();
        }

        return $cyberSourceCreditCard;
    }

    /**
     * @return \stdClass
     */
    protected function createBankAccount()
    {
        /** @var BankAccount $bankAccount */
        $bankAccount = $this->getBankAccount();

        $cyberSourceBankAccount = new \stdClass();
        $cyberSourceBankAccount->accountNumber = $bankAccount->getAccountNumber();
        $cyberSourceBankAccount->accountType = $bankAccount->getBankAccountType();
        $cyberSourceBankAccount->bankTransitNumber = $bankAccount->getRoutingNumber();

        return $cyberSourceBankAccount;
    }

    /**
     * @return \stdClass
     */
    protected function createBillingAddress()
    {
        /** @var \Omnipay\Common\CreditCard $creditCard */
        $creditCard = $this->getCard();

        /** @var BankAccount $bankAccount */
        $bankAccount = $this->getBankAccount();

        $cyberSourceBillingAddress = new \stdClass();

        if (!is_null($creditCard)) {
            $cyberSourceBillingAddress->firstName = $creditCard->getBillingFirstName();
            $cyberSourceBillingAddress->lastName = $creditCard->getBillingLastName();

            if (!is_null($creditCard->getBillingAddress1())) {
                $cyberSourceBillingAddress->street1 = $creditCard->getBillingAddress1();
            }

            if (!is_null($creditCard->getBillingAddress2())) {
                $cyberSourceBillingAddress->street2 = $creditCard->getBillingAddress2();
            }

            if (!is_null($creditCard->getBillingCity())) {
                $cyberSourceBillingAddress->city = $creditCard->getBillingCity();
            }

            if (!is_null($creditCard->getBillingState())) {
                $cyberSourceBillingAddress->state = $creditCard->getBillingState();
            }

            if (!is_null($creditCard->getBillingPostcode())) {
                $cyberSourceBillingAddress->postalCode = $creditCard->getBillingPostcode();
            }

            if (!is_null($creditCard->getBillingCountry())) {
                $cyberSourceBillingAddress->country = $creditCard->getBillingCountry();
            }

            if (!is_null($creditCard->getEmail())) {
                $cyberSourceBillingAddress->email = $creditCard->getEmail();
            }

            if (!is_null($creditCard->getBillingPhone())) {
                $cyberSourceBillingAddress->phoneNumber = $creditCard->getBillingPhone();
            }
        } elseif (!is_null($bankAccount)) {
            if (!is_null($bankAccount->getCompany())) {
                $cyberSourceBillingAddress->firstName = 'NA';
                $cyberSourceBillingAddress->lastName = $bankAccount->getCompany();
                $cyberSourceBillingAddress->company = $bankAccount->getCompany();
            } else {
                $cyberSourceBillingAddress->firstName = $bankAccount->getBillingFirstName();
                $cyberSourceBillingAddress->lastName = $bankAccount->getBillingLastName();
            }

            if (!is_null($bankAccount->getBillingAddress1())) {
                $cyberSourceBillingAddress->street1 = $bankAccount->getBillingAddress1();
            }

            if (!is_null($bankAccount->getBillingAddress2())) {
                $cyberSourceBillingAddress->street2 = $bankAccount->getBillingAddress2();
            }

            if (!is_null($bankAccount->getBillingCity())) {
                $cyberSourceBillingAddress->city = $bankAccount->getBillingCity();
            }

            if (!is_null($bankAccount->getBillingState())) {
                $cyberSourceBillingAddress->state = $bankAccount->getBillingState();
            }

            if (!is_null($bankAccount->getBillingPostcode())) {
                $cyberSourceBillingAddress->postalCode = $bankAccount->getBillingPostcode();
            }

            if (!is_null($bankAccount->getBillingCountry())) {
                $cyberSourceBillingAddress->country = $bankAccount->getBillingCountry();
            }

            if (!is_null($bankAccount->getEmail())) {
                $cyberSourceBillingAddress->email = $bankAccount->getEmail();
            }

            if (!is_null($bankAccount->getBillingPhone())) {
                $cyberSourceBillingAddress->phoneNumber = $bankAccount->getBillingPhone();
            }
        }

        return $cyberSourceBillingAddress;
    }

    private function addWsseToken(\SoapClient $soap)
    {
        $wsse_namespace = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $type_namespace = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';

        $user = new \SoapVar($this->getMerchantId(), XSD_STRING, null, $wsse_namespace, null, $wsse_namespace);
        $pass = new \SoapVar($this->getTransactionKey(), XSD_STRING, null, $type_namespace, null, $wsse_namespace);

        // create the username token container object
        $username_token = new \stdClass();
        $username_token->Username = $user;
        $username_token->Password = $pass;

        // convert the username token object into a soap var
        $username_token = new \SoapVar($username_token, SOAP_ENC_OBJECT, null, $wsse_namespace, 'UsernameToken', $wsse_namespace);

        // create the security container object
        $security = new \stdClass();
        $security->UsernameToken = $username_token;

        // convert the security container object into a soap var
        $security = new \SoapVar($security, SOAP_ENC_OBJECT, null, $wsse_namespace, 'Security', $wsse_namespace);

        // create the header out of the security soap var
        $header = new \SoapHeader($wsse_namespace, 'Security', $security, true);

        // add the headers to the soap client
        $soap->__setSoapHeaders($header);
    }



    #endregion

    #region CyberSource Parameters

    /**
     * @param string $merchantId
     */
    public function setMerchantId($merchantId)
    {
        $this->setParameter('merchantId', $merchantId);
    }

    /**
     * return string
     */
    public function getMerchantId()
    {
        return $this->getParameter('merchantId');
    }

    /**
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->setParameter('username', $username);
    }

    /**
     * return string
     */
    public function getUsername()
    {
        return $this->getParameter('username');
    }

    /**
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->setParameter('password', $password);
    }

    /**
     * return string
     */
    public function getPassword()
    {
        return $this->getParameter('password');
    }

    /**
     * @param string $transactionKey
     */
    public function setTransactionKey($transactionKey)
    {
        $this->setParameter('transactionKey', $transactionKey);
    }

    /**
     * return string
     */
    public function getTransactionKey()
    {
        return $this->getParameter('transactionKey');
    }

    /**
     * @param BankAccount $bankAccount
     */
    public function setBankAccount($bankAccount)
    {
        $this->setParameter('bankAccount', $bankAccount);
    }

    /**
     * return BankAccount
     */
    public function getBankAccount()
    {
        return $this->getParameter('bankAccount');
    }

    /**
     * The reference number that we set on the Klinche side.
     *
     * @param string $merchantReferenceCode
     */
    public function setMerchantReferenceCode($merchantReferenceCode)
    {
        $this->setParameter('merchantReferenceCode', $merchantReferenceCode);
    }

    /**
     * return string
     */
    public function getMerchantReferenceCode()
    {
        return $this->getParameter('merchantReferenceCode');
    }


    #endregion


    #region Omnipay Stuff

    public function getEndpoint()
    {
        return $this->getTestMode() ? self::TEST_ENDPOINT : self::LIVE_ENDPOINT;
    }

    public function getCardTypes()
    {
        return array(
            'visa' => '001',
            'mastercard' => '002',
            'amex' => '003',
            'discover' => '004',
            'diners_club' => '005',
            'carte_blanche' => '006',
            'jcb' => '007',
        );
    }

    public function getCardType()
    {
        $types = $this->getCardTypes();
        $brand = $this->getCard()->getBrand();
        return empty($types[$brand]) ? null : $types[$brand];
    }

    public function getCustomerID()
    {
        $user = Auth::user();
        return $user->id;
    }

    public function getIPAddress()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    public function getSessionId()
    {
        $user = Auth::user();
        $keyFingerPrint = $user->id . "deviceFingerprint";
        return Cache::get($keyFingerPrint);
    }

    #endregion
}
