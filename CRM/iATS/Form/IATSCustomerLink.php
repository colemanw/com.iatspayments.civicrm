<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_iATS_Form_IATSCustomerLink extends CRM_Core_Form {

  /**
   * Get the field names and labels expected by iATS CustomerLink,
   * and the corresponding fields in CiviCRM
   *
   * @return array
   */
  public function getFields() {
    $civicrm_fields = array(
      'firstName' => 'billing_first_name',
      'lastName' => 'billing_last_name',
      'address' => 'street_address',
      'city' => 'city',
      'state' => 'state_province',
      'zipCode' => 'postal_code',
      'creditCardNum' => 'credit_card_number',
      'creditCardExpiry' => 'credit_card_expiry',
      'mop' => 'credit_card_type',
    );
    // when querying using CustomerLink
    $iats_fields = array(
      'creditCardCustomerName' => 'CSTN', // FLN
      'address' => 'ADD',
      'city' => 'CTY',
      'state' => 'ST',
      'zipCode' => 'ZC',
      'creditCardNum' => 'CCN',
      'creditCardExpiry' => 'EXP',
      'mop' => 'MP'
    );
    $labels = array(
      //'firstName' => 'First Name',
      // 'lastName' => 'Last Name',
      'creditCardCustomerName' => 'Name on Card',
      'address' => 'Street Address',
      'city' => 'City',
      'state' => 'State or Province',
      'zipCode' => 'Postal Code or Zip Code',
      'creditCardNum' => 'Credit Card Number',
      'creditCardExpiry' => 'Credit Card Expiry Date',
      'mop' => 'Credit Card Type',
    );
    return array($civicrm_fields, $iats_fields, $labels);
  }

  protected function getCustomerCodeDetail($params) {
    require_once("CRM/iATS/iATSService.php");
    $credentials = iATS_Service_Request::credentials($params['paymentProcessorId'], $params['is_test']);
    $iats_service_params = array('type' => 'customer', 'iats_domain' => $credentials['domain'], 'method' => 'get_customer_code_detail');
    $iats = new iATS_Service_Request($iats_service_params);
    // print_r($iats); die();
    $request = array('customerCode' => $params['customerCode']);
    // make the soap request
    $response = $iats->request($credentials,$request);
    $customer = $iats->result($response, FALSE); // note: don't log this to the iats_response table
    // print_r($customer); die();
    $ac1 = $customer['ac1']; // this is a SimpleXMLElement Object
    $card = get_object_vars($ac1->CC);
    return $customer + $card;
  }

  protected function updateCreditCardCustomer($params) {
    require_once("CRM/iATS/iATSService.php");
    $credentials = iATS_Service_Request::credentials($params['paymentProcessorId'], $params['is_test']);
    unset($params['paymentProcessorId']);
    unset($params['is_test']);
    unset($params['domain']);
    $iats_service_params = array('type' => 'customer', 'iats_domain' => $credentials['domain'], 'method' => 'update_credit_card_customer');
    $iats = new iATS_Service_Request($iats_service_params);
    // print_r($iats); die();
    $params['updateCreditCardNum'] = (0 < strlen($params['creditCardNum']) && 0 === strpos($params['creditCardNum'],'*')) ? 1 : 0;
    if (empty($params['updateCreditCardNum'])) {
      unset($params['creditCardNum']);
      unset($params['updateCreditCardNum']);
    }
    $params['customerIPAddress'] = (function_exists('ip_address') ? ip_address() : $_SERVER['REMOTE_ADDR']);
    foreach(array('qfKey','entryURL','firstName','lastName','_qf_default','_qf_IATSCustomerLink_submit') as $key) {
      if (isset($params[$key])) {
        unset($params[$key]);
      }
    }
    // make the soap request
    $response = $iats->request($credentials,$params);
    $result = $iats->result($response, TRUE); // note: don't log this to the iats_response table
    return $result;
  }   

  function buildQuickForm() {

    list($civicrm_fields, $iats_fields, $labels) = $this->getFields();
    foreach($labels as $name => $label) {
      $this->add('text', $name, $label);
    }
    $this->add('hidden','customerCode');
    $this->add('hidden','paymentProcessorId');
    $this->add('hidden','is_test');
    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));
    $customerCode = CRM_Utils_Request::retrieve('customerCode', 'String');
    $paymentProcessorId = CRM_Utils_Request::retrieve('paymentProcessorId', 'Positive');
    $is_test = CRM_Utils_Request::retrieve('is_test', 'Integer');
    $defaults = array(
      'customerCode' => $customerCode,
      'paymentProcessorId' => $paymentProcessorId,
      'is_test' => $is_test,
    );
    if (empty($_POST)) {
      $customer = $this->getCustomerCodeDetail($defaults);
      foreach(array_keys($labels) as $name) {
        $iats_field = $iats_fields[$name];
        if (is_string($customer[$iats_field])) {
          $defaults[$name] = $customer[$iats_field];
        }
      }
    } 
    $this->setDefaults($defaults);
    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  function postProcess() {
    $values = $this->exportValues();
    // send update to iATS
    // print_r($values); die();
    $result = $this->updateCreditCardCustomer($values);
    $message = '<pre>'.print_r($result,TRUE).'</pre>';
    CRM_Core_Session::setStatus($message, 'Customer Updated'); // , $type, $options);
    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }
}
