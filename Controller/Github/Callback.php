<?php
/**
 * Landofcoder
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Landofcoder.com license that is
 * available through the world-wide-web at this URL:
 * http://landofcoder.com/license
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category   Landofcoder
 * @package    Lof_SocialLogin
 *
 * @copyright  Copyright (c) 2016 Landofcoder (http://www.landofcoder.com/)
 * @license    http://www.landofcoder.com/LICENSE-1.0.html
 */

namespace Lof\SocialLogin\Controller\Github;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Customer\Model\Session;
use Magento\Customer\Model\CustomerFactory;
use Symfony\Component\Config\Definition\Exception\Exception;
use Lof\SocialLogin\Model\SocialFactory as GithubModelFactory;
use Lof\SocialLogin\Model\ResourceModel\Social\CollectionFactory as GithubCollectionFactory;
use Lof\SocialLogin\Helper\Github\Data as SocialHelper;
use Lof\SocialLogin\Model\Github;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Visitor;

class Callback extends Action
{
    const SOCIAL_TYPE = 'github';
    protected $resultPageFactory;
    protected $github;
    protected $socialHelper;
    protected $accountManagement;
    protected $customerUrl;
    protected $session;
    protected $githubCustomerCollectionFactory;
    protected $githubCustomerModelFactory;
    protected $customerFactory;

    /**
     * @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var \Magento\Framework\Stdlib\Cookie\PhpCookieManager
     */
    private $cookieMetadataManager;

    public function __construct(
        Context $context,
        Github $github,
        StoreManagerInterface $storeManager,
        SocialHelper $socialHelper,
        PageFactory $resultPageFactory,
        AccountManagementInterface $accountManagement,
        CustomerUrl $customerUrl,
        GithubModelFactory $githubCustomerModelFactory,
        GithubCollectionFactory $githubCustomerCollectionFactory,
        CustomerFactory $customerFactory,
        CustomerRepositoryInterface $customerRepository,
        Session $customerSession,
        Visitor $visitor
        ) {
        parent::__construct($context);
        $this->github                          = $github;
        $this->storeManager                    = $storeManager;
        $this->socialHelper                    = $socialHelper;
        $this->resultPageFactory               = $resultPageFactory;
        $this->accountManagement               = $accountManagement;
        $this->customerUrl                     = $customerUrl;
        $this->session                         = $customerSession;
        $this->githubCustomerModelFactory      = $githubCustomerModelFactory;
        $this->githubCustomerCollectionFactory = $githubCustomerCollectionFactory;
        $this->customerFactory                 = $customerFactory;
        $this->customerRepository              = $customerRepository;
        $this->visitor                         = $visitor;
    }

    public function execute()
    {
        $client_id = $this->socialHelper->getApiKey();
        $redirect_uri = $this->socialHelper->getAuthUrl();
        $client_secret = $this->socialHelper->getClientKey();

        $code = $this->getRequest()->getParam('code');
        $state = $this->getRequest()->getParam('state');
        if (! isset($code)) {
            die('Warning! Visitor may have declined access or navigated to the page without being redirected.');
        }
        if ($state !== $_SESSION['wpcc_state_github']) {
            die('Warning! State mismatch. Authentication attempt may have been compromised.');
        }
        $request = 'grant_type=authorization_code';
        $request .= '&code='.$code;
        $request .= '&redirect_uri='.$redirect_uri;
        $request .= '&client_id='.$client_id;
        $request .= '&client_secret='.$client_secret;
        $request .= '&state='.$state;
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://github.com/login/oauth/access_token");
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
            ]);
        $auth = curl_exec($curl);
        if (curl_errno($curl)) {
            echo curl_error($curl);
        }
        $secret = json_decode($auth);
        $access_token = $secret->access_token;
        $curl = curl_init('https://api.github.com/user?access_token='.$access_token);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Authorization: token  '. $access_token ]);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'User-Agent: Landofcoder' ]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $dataUser =  json_decode(curl_exec($curl));
        $data = [];
        $redirect = $this->socialHelper->getConfig('general/redirect_page');
        if(empty($redirect)){
            $link_redirect = "window.opener.location.reload();";
        }else{
            $link_redirect = "window.opener.location= '".$redirect."';";
        };
        if ($dataUser && isset($dataUser->id)) {
            $customerId = $this->getCustomerIdByGithubId($dataUser->id);
            $customer = $this->customerRepository->getById($customerId);
            $customer1 = $this->customerFactory->create()->load($customerId);
            if ($customer->getConfirmation()) {
                try {
                    $customer1->setConfirmation(null);
                    $customer1->save();
                } catch (\Exception $e) {
                    $this->messageManager->addError(__('We can\'t process your request right now. Sorry, that\'s all we know.'));
                }
            }
            if ($this->getCookieManager()->getCookie('mage-cache-sessid')) {
                $metadata = $this->getCookieMetadataFactory()->createCookieMetadata();
                $metadata->setPath('/');
                $this->getCookieManager()->deleteCookie('mage-cache-sessid', $metadata);
            }
            $this->session->setCustomerDataAsLoggedIn($customer);
            $this->messageManager->addSuccess(__('Login successful.'));
            $this->session->regenerateId();
            $this->_eventManager->dispatch('customer_data_object_login', ['customer' => $customer]);
            $this->_eventManager->dispatch('customer_login', ['customer' => $customer1]);

            /** VISITOR */
            $visitor = $this->visitor;
            $visitor->setData($this->session->getVisitorData());
            $visitor->setLastVisitAt((new \DateTime())->format(\Magento\Framework\Stdlib\DateTime::DATETIME_PHP_FORMAT));
            $visitor->setSessionId($this->session->getSessionId());
            $visitor->save();
            $this->_eventManager->dispatch('visitor_init', ['visitor' => $visitor]);
            $this->_eventManager->dispatch('visitor_activity_save', ['visitor' => $visitor]);
            echo "<script type=\"text/javascript\">window.close();".$link_redirect."</script>"; 
            exit;
        }
        if ($dataUser) {
            if (isset($dataUser->email)) {
                $data['id'] = isset($dataUser->id)?$dataUser->id:0;
                $data['email']= $dataUser->email;
                $data['password'] =  $this->socialHelper->createPassword();
                $data['password_confirmation'] = $data['password'];
                $data['first_name'] = $dataUser->name;
                $data['last_name']  = '.';
            } else {
                $data['id'] = $dataUser->id;
                $data['email']= $dataUser->id.'@github.com';
                $data['password'] =  $this->socialHelper->createPassword();
                $data['password_confirmation'] = $data['password'];
                $data['first_name'] = $dataUser->name;
                $data['last_name']  = '.';
            }
            $store_id   = $this->storeManager->getStore()->getStoreId();
            $website_id = $this->storeManager->getStore()->getWebsiteId();
            $customer   = $this->socialHelper->getCustomerByEmail($data['email'], $website_id);
            if (!$customer || !$customer->getId()) {
                $customer = $this->socialHelper->createCustomerMultiWebsite($data, $website_id, $store_id);
                if ($this->socialHelper->sendPassword()) {
                    try {
                        $this->accountManagement->sendPasswordReminderEmail($customer);
                    } catch (Exception $e) {
                        $this->messageManager->addError(__('We can\'t process your request right now. Sorry, that\'s all we know.'));
                    }
                }
            }
            $this->setAuthorCustomer($data['id'], $customer->getId(), $dataUser->url);
            $confirmationStatus = $this->accountManagement->getConfirmationStatus($customer->getId());
            if ($confirmationStatus === AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED) {
                $this->customerUrl->getEmailConfirmationUrl($customer->getEmail());
                                // @codingStandardsIgnoreStart
                $this->messageManager->addSuccess(
                    __( 
                        'You must confirm your account. Please check your email for the confirmation link or <a href="%1">click here</a> for a new link.',
                        $email
                    )
                );
            } else {
                $this->session->setCustomerDataAsLoggedIn($customer);
                $this->messageManager->addSuccess(__('Login successful.'));
                $this->session->regenerateId();
            }
            echo "<script type=\"text/javascript\">window.close();".$link_redirect."</script>"; 
        }
    }

    public function getCustomerIdByGithubId($githubId)
    {
        $customer = $this->githubCustomerCollectionFactory->create();
        $dataUser     = $customer
        ->addFieldToFilter('social_id', $githubId)
        ->addFieldToFilter('type', self::SOCIAL_TYPE)
        ->getFirstItem();
        if ($dataUser && $dataUser->getId()) {
            return $dataUser->getCustomerId();
        } else {
            return null;
        }
    }

    public function setAuthorCustomer($githubId, $customerId, $username)
    {
        $githubCustomer = $this->githubCustomerModelFactory->create();
        $githubCustomer->setData('social_id', $githubId);
        $githubCustomer->setData('username', $username);
        $githubCustomer->setData('customer_id', $customerId);
        $githubCustomer->setData('type', self::SOCIAL_TYPE);
        $githubCustomer->setData('is_send_password_email', $this->socialHelper->sendPassword());
        try {
            $githubCustomer->save();
        } catch (Exception $e) {
            $this->messageManager->addError(__('We can\'t process your request right now. Sorry, that\'s all we know.'));
        }
        return;
    }

    /**
     * Retrieve cookie manager
     *
     * @deprecated
     * @return \Magento\Framework\Stdlib\Cookie\PhpCookieManager
     */
    private function getCookieManager()
    {
        if (!$this->cookieMetadataManager) {
            $this->cookieMetadataManager = \Magento\Framework\App\ObjectManager::getInstance()->get(
                \Magento\Framework\Stdlib\Cookie\PhpCookieManager::class
                );
        }
        return $this->cookieMetadataManager;
    }

    /**
     * Retrieve cookie metadata factory
     *
     * @deprecated
     * @return \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory
     */
    private function getCookieMetadataFactory()
    {
        if (!$this->cookieMetadataFactory) {
            $this->cookieMetadataFactory = \Magento\Framework\App\ObjectManager::getInstance()->get(
                \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory::class
                );
        }
        return $this->cookieMetadataFactory;
    }
}
