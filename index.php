<?php

if (class_exists('Rcl_Payment')) {

add_action('init','rcl_add_paymaster_payment');
function rcl_add_paymaster_payment(){
    $pm = new Rcl_Paymaster_Payment();
    $pm->register_payment('paymaster');
}

class Rcl_Paymaster_Payment extends Rcl_Payment{

    public $form_pay_id;

    function register_payment($form_pay_id){
        $this->form_pay_id = $form_pay_id;
        parent::add_payment($this->form_pay_id, array(
            'class'=>get_class($this),
            'request'=>'PMR_RCL_BAGGAGE',
            'name'=>'Paymaster',
            'image'=>rcl_addon_url('assets/paymaster.jpg',__FILE__)
            ));
        if(is_admin()) $this->add_options();
    }

    function add_options(){
        add_filter('rcl_pay_option',(array($this,'options')));
        add_filter('rcl_pay_child_option',(array($this,'child_options')));
    }

    function options($options){
        $options[$this->form_pay_id] = 'Paymaster';
        return $options;
    }

    function child_options($child){

        $opt = new Rcl_Options();

        $child .= $opt->child(
            array(
                'name'=>'connect_sale',
                'value'=>$this->form_pay_id
            ),
            array(
                $opt->options_box( __('Настройки подключения Paymaster'), array(
                    array(
                        'type' => 'text',
                        'slug' => 'pmr_merchant_id',
                        'title' => __('Идентификатор продавца')
                    ),
                    array(
                        'type' => 'text',
                        'slug' => 'pmr_skey',
                        'title' => __('Секретный ключ')
                    ),
                    array(
                        'type' => 'custom',
                        'slug' => 'notice',
                        'content' => __('Страница настроек: https://merchant.paymaster.ua/index/myshops/<br>'
                                . 'Метод формирования контрольной подписи: SHA256<br>'
                                . 'В полях RESULT, FAIL и SUCCESS указать URL на страницы созданные для этих целей на сайте.<br>'
                                . 'Метод отправки данных: POST')
                    )
                ))
            )
        );

        return $child;
    }

    function pay_form($data){
        global $rmag_options;

        $desc = ($data->description)? $data->description: 'Платеж от '.get_the_author_meta('user_email',$data->user_id);

        $baggage_data = ($data->baggage_data)? $data->baggage_data: false;

        $fields = array(
            'LMI_MERCHANT_ID'=>$rmag_options['pmr_merchant_id'],
            'LMI_PAYMENT_AMOUNT'=>$data->pay_summ,
            'LMI_PAYMENT_NO'=>$data->pay_id,
            'LMI_PAYMENT_DESC_BASE64'=>base64_encode($desc),
            'LMI_HASH' => strtoupper(
                hash( 'sha256',
                    $rmag_options['pmr_merchant_id']
                  . $data->pay_id
                  . $data->pay_summ
                  . $rmag_options['pmr_skey']
                )
            ),
            'PMR_USER_ID'=>$data->user_id,
            'PMR_TYPE'=>$data->pay_type,
            'PMR_RCL_BAGGAGE'=>$baggage_data
        );

        $form = parent::form($fields,$data,"https://lmi.paymaster.ua");

        return $form;
    }

    function result($data){
        global $rmag_options;

        if($_REQUEST['LMI_MERCHANT_ID'] != $rmag_options['pmr_merchant_id']){
            echo 'ERROR'; exit;
        }

        if($_REQUEST['LMI_PREREQUEST'] == 1){
            echo 'YES'; exit;
        }

        $hash = strtoupper(hash( 'sha256',
            $_POST['LMI_MERCHANT_ID']
          . $_POST['LMI_PAYMENT_NO']
          . $_POST['LMI_SYS_PAYMENT_ID']
          . $_POST['LMI_SYS_PAYMENT_DATE']
          . $_POST['LMI_PAYMENT_AMOUNT']
          . $_POST['LMI_PAID_AMOUNT']
          . $_POST['LMI_PAYMENT_SYSTEM']
          . $_POST['LMI_MODE']
          . $rmag_options['pmr_skey']
        ));

        if($hash != $_REQUEST['LMI_HASH']){
            rcl_mail_payment_error($hash);
            exit;
        }

        $data->pay_summ = $_REQUEST['LMI_PAYMENT_AMOUNT'];
        $data->pay_id = $_REQUEST['LMI_PAYMENT_NO'];
        $data->user_id = $_REQUEST['PMR_USER_ID'];
        $data->pay_type = $_REQUEST['PMR_TYPE'];
        $data->baggage_data = $_REQUEST['PMR_RCL_BAGGAGE'];

        if(!parent::get_pay($data)){
            parent::insert_pay($data);
            echo 'OK';
            exit;
        }
    }

    function success(){
        global $rmag_options;

        $data = array(
            'pay_id' => $_REQUEST['LMI_PAYMENT_NO'],
            'user_id' => $_REQUEST['PMR_USER_ID']
        );

        if(parent::get_pay((object)$data)){
            wp_redirect(get_permalink($rmag_options['page_successfully_pay'])); exit;
        } else {
            wp_die('Платеж не найден в базе данных');
        }

    }

}

}