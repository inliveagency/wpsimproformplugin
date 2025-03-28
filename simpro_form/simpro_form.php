<?php
/**
 * Plugin Name: Simpro form
 * Plugin URI:
 * Description: Added WP simpro form
 * Version: 1
 * Author: Inlive
 **/

use Carbon_Fields\Container;
use Carbon_Fields\Field;

require_once 'vendor/autoload.php';

class SimproFormPlugin
{
    public $recaptchaSiteKey;
    public $recaptchaSecretKey;
    public $recaptcha;

    public function __construct()
    {
        Timber\Timber::init();

        add_action('wp_enqueue_scripts', [$this, 'wp_enqueue_script']);

        add_action( 'after_setup_theme', array( $this,
            'load_carbon_fields'
        ) );

        add_action( 'carbon_fields_register_fields', array( $this,
            'register_carbon_fields'
        ) );

        add_action( 'carbon_fields_fields_registered', array( $this,
            'carbon_fields_values_are_available'
        ) );

        add_shortcode( 'simpro_form', array( $this,
            'simpro_form_shortcode'
        ) );


        add_filter('timber/locations', function ($paths) {
            $paths[] = [plugin_dir_path(__FILE__).'templates'];

            return $paths;
        });

        add_action( 'wp_ajax_simpro_form', [$this, 'form_submitting'] );
        add_action( 'wp_ajax_nopriv_simpro_form', [$this, 'form_submitting'] );
    }

    public function wp_enqueue_script()
    {
        if(!empty($this->recaptchaSiteKey) && !empty($this->recaptchaSecretKey)) {
            wp_enqueue_script( 'google_recaptcha',
                'https://www.google.com/recaptcha/api.js?render='.$this->recaptchaSiteKey,
                [],
                3
            );
        }

        wp_enqueue_script( 'jquery');

        /*wp_enqueue_script( 'simpro_form',
            plugin_dir_url( __FILE__ ) . 'assets/plugin.js',
            array('jquery'),
            md5(plugin_dir_path(__FILE__).'assets/plugin.js')
        );*/
    }


    public function load_carbon_fields()
    {
        \Carbon_Fields\Carbon_Fields::boot();
    }

    public function register_carbon_fields()
    {
        Container::make( 'theme_options', 'SimPro form plugin' )
            ->set_page_parent( 'options-general.php' )
            -> add_fields( array(
                Field::make( 'text', 'simpro_api_token', 'Simpro API token'),
                Field::make( 'text', 'simpro_api_url', 'Simpro API URL'),
                Field::make( 'text', 'simpro_customer', 'Simpro customer'),
                Field::make( 'text', 'simpro_site', 'Simpro site ID'),
                Field::make( 'text', 'simpro_company', 'Simpro company ID'),
                Field::make( 'text', 'simpro_lead_name', 'Lead name')
                    ->set_default_value('Lead From Website'),
                Field::make( 'text', 'simpro_thank_you_text', 'Form "Thank you" text')
                    ->set_default_value('Thank you. The form submitted successfully!'),
                Field::make( 'text', 'simpro_fail_text', 'Form "Fail" text')
                    ->set_default_value('Sorry, please check the fields data or contact with us. The form has not been sent'),
                Field::make( 'checkbox', 'simpro_css_checkbox', 'Disable plugin css'),
                Field::make( 'text', 'simpro_form_shortcode', 'Form shortcode')
                    ->set_attribute( 'readOnly', true )
                    ->set_default_value( '[simpro_form]' ),
                Field::make( 'text', 'google_recaptcha_site_key', 'Google recaptcha site key'),
                Field::make( 'text', 'google_recaptcha_secret_key', 'Google recaptcha secret key'),
            ) );
    }

    public function carbon_fields_values_are_available()
    {
        $this->recaptchaSiteKey = carbon_get_theme_option('google_recaptcha_site_key');
        $this->recaptchaSecretKey = carbon_get_theme_option('google_recaptcha_secret_key');

        if(!empty($this->recaptchaSecretKey)){
            $this->recaptcha = new \ReCaptcha\ReCaptcha($this->recaptchaSecretKey);
        }
    }

    public function simpro_form_shortcode($atts)
    {
        $context = [];

        $context["disable_css"] = empty(carbon_get_theme_option('simpro_css_checkbox'));
        $context["simpro_form_url"] = admin_url('admin-ajax.php?action=simpro_form&nonce='.wp_create_nonce("simpro_form_nonce"));
        $context["thank_you_text"] = carbon_get_theme_option('simpro_thank_you_text');
        $context["fail_text"] = carbon_get_theme_option('simpro_fail_text');

        if(!empty($this->recaptchaSiteKey)) {
            $context["recaptcha_site_key"] = $this->recaptchaSiteKey;
        }

        return Timber::compile('form.twig', $context);
    }

    public function simpro_query($url, $method, $data){
        $apiKey = 'Bearer '.carbon_get_theme_option('simpro_api_token'); //demo key
        // CURL headers
        $headers = array(
            'Content-Type: application/json',
            'Authorization: '.$apiKey,
        );
        // The simpro account to connect to
        $SimproURL = carbon_get_theme_option('simpro_api_url').$url;

        // Initialize curl
        $ch = curl_init();

        // Send request to Server
        curl_setopt($ch, CURLOPT_URL, $SimproURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        if ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // this function is called by curl for each header received
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$headers)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) // ignore invalid headers
                    return $len;

                $name = strtolower(trim($header[0]));
                if (!array_key_exists($name, $headers))
                    $headers[$name] = [trim($header[1])];
                else
                    $headers[$name][] = trim($header[1]);

                return $len;
            }
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Get the response from server
        $result = curl_exec($ch);
        $json = json_decode($result, true);

        // Check for errors and display the error message
        $errno = curl_errno($ch);

        $info = curl_getinfo($ch);

        //decode response into array

        $data = json_decode($result, true);
        $return = array_merge(array('Headers' => $headers), array('Info' => $info), array('Errors' => $errno), array('Data' =>  $data));

        // Close resource
        curl_close($ch);

        return $return;
    }

    public function form_submitting() {
        $address = "";
        if($_POST['address_1']) {
            $address .= "Address 1: " .$_POST['address_1'] . "<br>\n";
        }
        if($_POST['address_2']) {
            $address .= "Address 2: " .$_POST['address_2'] . "<br>\n";
        }
        if($_POST['postcode']) {
            $address .= "Postal code: " .$_POST['postcode'] . "<br>\n";
        }
        if($_POST['city']) {
            $address .= "City: " .$_POST['city'] . "<br>\n";
        }
        if($_POST['county']) {
            $address .= "County: " .$_POST['county'] . "<br>\n";
        }

        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
        $phone = $_POST['phone'];
        $type = $_POST['type'];
        $plumbing = $_POST['plumbing'];
        $heating = $_POST['heating'];
        $message = $_POST['message'];

        $details = "First Name: " . $first_name . "<br>\n";
        $details .= "Last Name: " . $last_name . "<br>\n";
        $details .= "Email: " . $email . "<br>\n";
        $details .= "Phone: " . $phone . "<br>\n";
        $details .= "Enquiry Type: " . $type . "<br>\n";
        $details .= "Sub Enquiry Type: " . $plumbing . " " . $heating . "<br>\n";
        $details .= "Message: " . $message . "<br>\n";
        $details .= $address;

        $date = date('Y-m-d');

        $leadData = array(
            "LeadName" => carbon_get_theme_option('simpro_lead_name'),
            "Customer" => (int) carbon_get_theme_option('simpro_customer'),
            "Site" => (int) carbon_get_theme_option('simpro_site'),
            "FollowUpDate" => $date,
            "Description"=> $details,
            "Notes" => "",
        );

        $leadData = json_encode($leadData);

        $url ='/api/v1.0/companies/'.carbon_get_theme_option('simpro_company').'/leads/';
        $newLead = $this->simpro_query($url, 'POST', $leadData);

        $pluginDir = WP_PLUGIN_DIR . '/simpro_form/';
        $startDateTime = date('j_n_o_');
        $log = fopen($pluginDir."logs/".$startDateTime."log.txt","a");

        if(!empty($this->recaptchaSecretKey) && !$this->recaptcha->verify($_POST['token'])) {
            echo json_encode(['error' => 'Invalid recaptcha', 'status' => 'false']);
            fwrite($log, "[".date('Y-m-d H:i:s')."] FAIL ".'Invalid recaptcha'.PHP_EOL.PHP_EOL);
        } elseif(empty($first_name) || empty($last_name) || $email === false || empty($phone) || empty($type) || empty($message) || empty($_POST['address_1'])) {
            echo json_encode(['error' => 'Please fill the required fields', 'status' => 'false']);
            fwrite($log, "[".date('Y-m-d H:i:s')."] FAIL ".'Please fill the required fields'.PHP_EOL.PHP_EOL);
        } elseif ($newLead['Data']['ID']) {
            echo json_encode(['error' => '', 'status' => 'true']);
            fwrite($log, "[".date('Y-m-d H:i:s')."] OK ".json_encode($newLead).PHP_EOL.PHP_EOL);
        } else {
            fwrite($log, "[".date('Y-m-d H:i:s')."] FAIL ".json_encode($newLead).PHP_EOL.PHP_EOL);
            echo json_encode(['error' => carbon_get_theme_option('simpro_fail_text'), 'status' => 'false']);
        }
        fclose($log);

        die();
    }
}
new SimproFormPlugin();
