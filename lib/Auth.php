<?php
//
// SSO Authentication Library.
//
namespace SSOAuth;

class Auth
{

    private $session_encryption_key;
    private $session_name;
    private $session_lifetime;
    private $session_domain;
    private $session_path;
    private $session_secure;

    private $valid_providers = array('radius');
    private $auth_providers = array ('order' => array ('radius'), 'radius' => array('servers' => array()));

    private $has_error = false;
    private $error_message;

    private $attributes;

    function __construct(
        $session_encryption_key = 'eaChey8quoo1ahch0en9eebe',
        $session_name = 'ssoauth',
        $session_domain = '',
        $session_path = '/',
        $session_idletime = 3600,
        $session_lifetime = 0,
        $session_secure = false
    ) {

        $this->session_encryption_key = $session_encryption_key;
        $this->session_name = $session_name;
        $this->session_domain = $session_domain;
        $this->session_path = $session_path;
        $this->session_secure = $session_secure;
        $this->session_lifetime = $session_lifetime;
        $this->session_idletime = $session_idletime;
    }

    public function hasError()
    {
        return $this->has_error;
    }

    public function errorMessage()
    {
        return $this->error_message;
    }

    public function login($username, $password)
    {

        // Try the providers/servers in order of preference.
        //
        foreach ($this->auth_providers['order'] as $provider) {
            if (!isset($this->auth_providers[$provider]['servers']) || empty($this->auth_providers[$provider]['servers'])) {
                 $this->has_error = true;
                 $this->error_message = 'No servers found for provider: ' . $provider;
                 return false;
            } else {
                $result = $this->doLogin($provider, $username, $password);
            }

            // Found a valid login, create session.
            //
            if ($result) {
                $this->loginSuccess($username, $result);
                return true;
            }
        }

        return false;
    }

    public function logout()
    {

        session_name($this->session_name);

        // Unset all of the session variables.
        $_SESSION = array();

        // If it's desired to kill the session, also delete the session cookie.
        // Note: This will destroy the session, and not just the session data!
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }

        session_destroy();

        unset($_SESSION);

        return true;
    }

    public function checkLogin()
    {
        // Make sure we save to files and set up encryption on the sessions files.
        ini_set('session.save_handler', 'files');
        $handler = new EncryptedSessionHandler($this->session_encryption_key);
        session_set_save_handler($handler, true);

        // Set session name and set up the session cookie values.
        session_name($this->session_name);
        session_set_cookie_params($this->session_lifetime, $this->session_path, $this->session_domain, $this->session_secure);

        // Start the Session.
        session_start();

        // Check session says we are logged in.
        //
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
            // Compare the signature on the session.
            if (isset($_SESSION['signature']) && $this->compareSignature()) {
                // Make sure the idle time has not expired.
                if ((isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] < $this->session_idletime))) {
                    // Update activity time.
                    $_SESSION['LAST_ACTIVITY'] = time();
                    return true;
                }
            }
        }

        return false;
    }

    public function getIp()
    {
        // Check IP address either in X-Forward-For or Remote-Addr
        //
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) === true) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        if (isset($_SERVER['REMOTE_ADDR']) === true) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return '(unknown_ip_address)';
    }

    // Generate form tokens, if a user loads a login page
    // from multiple hosts (and sessions are for a domain or subdomain)
    // the last page opened will be the only valid login page as the 
    // session is destroyed on login.php so the tokens won't match on 
    // previously opened pages. Where a session is tied to a host all 
    // loaded login pages will remain valid.
    // 
    public function getFormTokenKey()
    {
        return md5($_SERVER['PHP_SELF']);
    }

    public function getFormToken()
    {
        return md5(uniqid($_SERVER['PHP_SELF'], true));
    }

    private function compareSignature()
    {
        if (isset($_SESSION['signature'])) {
            $signature = $this->generateSignature($_SESSION['signature']);
            if ($signature === $_SESSION['signature']) {
                return true;
            }
        }
        return false;
    }

    private function generateSignature($salt = null)
    {

        $useragent = md5($_SERVER['HTTP_USER_AGENT']);
        $ip = md5($this->getIp());
        return $this->generateHash($ip.$useragent, $salt);
    }

    private function generateHash($text, $salt = null, $length = 24)
    {

        if ($salt === null) {
            $salt = bin2hex(random_bytes($length/2));
            // $salt = substr(md5(uniqid(rand(), true)), 0, $length);
        } else {
            $salt = substr($salt, 0, $length);
        }

        return $salt . hash('sha512', $salt . $text);
    }

    public function setProviderOrder($order)
    {

        if (!is_array($order)) {
            throw new \Exception('Authentication provider order is not an array');
        }

        $invalid_providers = array_diff($order, $this->valid_providers);
        if (count($invalid_providers)) {
            throw new \Exception('Unknown authentication providers found: ' . join(", ", $invalid_providers));
        }

        $this->auth_providers['order'] = $order;
    }

    public function addRadiusServer($hostname, $port = 1812, $secret = 'secret', $timeout = 5, $max_tries = 3)
    {
        $this->auth_providers['radius']['servers'][$hostname] = array ('port' => $port, 'secret' => $secret, 'timeout' => $timeout, 'max_tries' => $max_tries);
    }

    private function loginSuccess($username)
    {
        session_regenerate_id();
        $_SESSION['username'] = $username;
        $_SESSION['logged_in'] = true;
        $_SESSION['LAST_ACTIVITY'] = time();
        $_SESSION['signature'] = $this->generateSignature();

        return true;
    }


    private function doLogin($provider, $username, $password)
    {
        if ($provider === 'radius') {
            return $this->loginRadius($username, $password);
        } else {
            throw new \Exception('Authentication provider not supported.');
        }
    }

    private function getRadiusAttr($radius)
    {
        while ($resa = radius_get_attr($radius)) {
            if (!is_array($resa)) {
                return false;
            }

            $attr = $resa['attr'];
            $data = $resa['data'];

            if ($attr == RADIUS_VENDOR_SPECIFIC) {
                $resv = radius_get_vendor_attr($data);
                if (is_array($resv)) {
                    $this->attributes[RADIUS_VENDOR_SPECIFIC][] = $resv;
                }
            } else {
                $this->attributes[$attr] = $data;
            }
        }
    }

    private function loginRadius($username, $password)
    {

        $radius = radius_auth_open();

        foreach ($this->auth_providers['radius']['servers'] as $hostname => $server) {
            radius_add_server($radius, $hostname, $server['port'], $server['secret'], $server['timeout'], $server['max_tries']);
        }

        radius_create_request($radius, RADIUS_ACCESS_REQUEST);
        radius_put_attr($radius, RADIUS_USER_NAME, $username);
        radius_put_attr($radius, RADIUS_USER_PASSWORD, $password);

        $result = radius_send_request($radius);

        switch ($result) {
            case RADIUS_ACCESS_ACCEPT:
                //$this->getRadiusAttr($radius);
                return true;
                break;
            case RADIUS_ACCESS_REJECT:
                 $this->has_error = true;
                 $this->error_message = 'Authentication failed.';
                return false;
            case RADIUS_ACCESS_CHALLENGE:
                 $this->has_error = true;
                 $this->error_message = 'Challenge requried.';
                return false;
        }

        $this->has_error = true;
        $this->error_message = 'Unknown radius error.';
        return false;
    }
}
