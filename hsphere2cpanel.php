#!/usr/bin/php
<?php

/**
 * Filename: hsphere2cpanel.php
 * Description: H-sphere to Cpanel/WHMCS conversion script
 * Author: Ron Lockard
 * Date:  28 Aug 2008
 * Version: 0.1
 *
 * vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4:
 **/

require 'config.php';
require 'cpanel-xmlapi.php';

$HOSTING = 9;
$MAIL = 1000;
$HOSTING_ALIAS = 6410;
$MAIL_ALIAS = 6412;
$SUBDOMAIN = 31;

$card_type['3'] = 'American Express';
$card_type['4'] = 'Visa';
$card_type['5'] = 'MasterCard';
$card_type['6'] = 'Discover';

if ( count($argv) < 2 ) {
    echo "Usage: php $argv[0] domain\n";
    exit;
}

function json_post($url, $params) {
    global $debug_json;

    $query_string = '';
    foreach ($params AS $k=>$v) $query_string .= "$k=".urlencode($v).'&';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $query_string);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $jsondata = curl_exec($ch);
    if (curl_error($ch)) die('Connection Error: '.curl_errno($ch).' - '.curl_error($ch));
    curl_close($ch);

    $arr = json_decode($jsondata, true);
    if ($debug_json) print_r($arr);
    return($arr);
}

$domainname = $argv[1];

if ($domainname == 'prep') {
    echo "Copying key to $cpanel_ip\n";
    `ssh-copy-id -i ~/.ssh/id_rsa.pub root@$cpanel_ip`;
    echo "Copying key to $hsphere_db_host\n";
    `ssh-copy-id -i ~/.ssh/id_rsa.pub root@$hsphere_db_host`;
    foreach ($hsphere_webs as $webserver) {
        echo "Copying key to $webserver\n";
        `ssh-copy-id -i ~/.ssh/id_rsa.pub root@$webserver`;
        `ssh root@$cpanel_ip "ssh-copy-id -i ~/.ssh/id_rsa.pub root@$webserver"`;
    }
}


passthru("whois $domainname");
passthru("dig $domainname a");
passthru("dig www.$domainname a");
passthru("dig $domainname mx");
// Wait while reviewed
echo "Info for $domainname\n";
echo 'Enter Y to continue: ';
$handle = fopen ('php://stdin', 'r');
$line = fgets($handle);
if(trim($line) != 'Y'){
    echo "ABORTING!\n";
    exit;
}
echo "\n"; 
echo "Thank you, continuing...\n";

$dbconn = pg_connect("host=$hsphere_db_host dbname=$hsphere_db_name user=$hsphere_db_user password=$hsphere_db_pass") or die('Could not connect: ' . pg_last_error());

$query = "select p.account_id,p.child_id,u.*,uu.login,uu.password as unixpass,uu.dir,uu.hostid from users u, user_account a, domains d, parent_child p, unix_user uu where d.id=p.child_id and u.id=a.user_id and p.parent_id=uu.id and a.account_id=p.account_id and d.name='$domainname'";
$result = pg_query($query) or die('Query failed: ' . pg_last_error());

while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    $account_id = $line['account_id'];
    $child_id = $line['child_id'];
    $user_id = $line['id'];
    $username = $line['username'];
    $unixuser = $line['login'];
    $unixpass = $line['unixpass'];
    $cp_username = substr(str_replace('-', '', strtolower($unixuser)), 0, 8);
    $password = $line['password'];
    $user_dir = $line['dir'];
    $host_id = $line['hostid'];
    $reseller_id = $line['reseller_id'];

    echo "Account ID: $account_id\n";
    echo "User ID: $user_id\n";
    echo "Username: $username\n";
    echo "Password: $password\n";
    echo "Unix user: $unixuser\n";
    echo "Unix pass: $unixpass\n";
    echo "Web Host ID: $host_id\n";
    echo "Reseller ID: $reseller_id\n\n";

    // get web shared IP
    $query = "select ip from l_server_ips where l_server_id='$host_id'";
    $result_tmp = pg_query($query) or die('Query failed: ' . pg_last_error());
    $shared_ip = pg_fetch_result($result_tmp, 'ip');

    $query = "select * from contact_info, accounts where contact_info.id = accounts.ci_id and accounts.id = '$account_id'";
    $result_tmp = pg_query($query) or die('Query failed: ' . pg_last_error());
    $contact_info = pg_fetch_array($result_tmp, null, PGSQL_ASSOC);
    if ($debug) print_r($contact_info);
    $hosting_renew_date = $contact_info['p_end'];
    $billing_id = $contact_info['bi_id'];
    list($hosting_created_date, $dummy) = explode(' ', $contact_info['created']);

    $billing_id = $contact_info['bi_id'];
    $query = "select c.*,bi.* from users u, user_billing_infos u_b, credit_card c, user_account a, billing_info bi where u.id=u_b.user_id and c.id=u_b.billing_info_id and c.id=bi.id and u.id=a.user_id and a.account_id='$account_id' and bi.id='$billing_id'";
    $result_tmp = pg_query($query) or die('Query failed: ' . pg_last_error());
    $billing_info = pg_fetch_array($result_tmp, null, PGSQL_ASSOC);
    if ($debug) print_r($billing_info);

    if ($contact_info['email'] != $billing_info['email'] ) {
        echo "Contact email: $contact_info[email]\n";
        echo "Billing email: $billing_info[email]\n";
        echo "Enter Y to continue: ";
        $handle = fopen ('php://stdin', 'r');
        $line = fgets($handle);
        if(trim($line) != 'Y'){
            echo "ABORTING!\n";
            exit;
        }
        echo "\n"; 
    }

    if ($billing_info['name'] == '') {
        $billing_info['name'] = $contact_info['name'];
        $billing_info['last_name'] = $contact_info['last_name'];
        $billing_info['company'] = $contact_info['company'];
        $billing_info['email'] = $contact_info['email'];
        $billing_info['address1'] = $contact_info['address1'];
        $billing_info['address2'] = $contact_info['address2'];
        $billing_info['city'] = $contact_info['city'];
        $billing_info['state'] = $contact_info['state'];
        $billing_info['postal_code'] = $contact_info['postal_code'];
        $billing_info['country'] = $contact_info['country'];
        $billing_info['phone'] = $contact_info['phone'];
    }

    if ($contact_info['email'] != $billing_info['email'] ) {
        echo "Contact email: $contact_info[email]\n";
        echo "Billing email: $billing_info[email]\n";
        echo 'Enter Y to continue: ';
        $handle = fopen ('php://stdin', 'r');
        $line = fgets($handle);
        if(trim($line) != 'Y'){
            echo "ABORTING!\n";
            exit;
        }
        echo "\n"; 
    }

    echo '*** Creating WHMCS account: ';
    $postfields = array();
    $postfields['username'] = $whmcs_api_username;
    $postfields['password'] = md5($whmcs_api_password);
    $postfields['action'] = 'addclient';
    $postfields['responsetype'] = 'json';
    $postfields['firstname'] = $billing_info['name'];
    $postfields['lastname'] = $billing_info['last_name'];
    $postfields['companyname'] = $billing_info['company'];
    $postfields['email'] = $billing_info['email'];
    $postfields['address1'] = $billing_info['address1'];
    $postfields['address2'] = $billing_info['address2'];
    $postfields['city'] = $billing_info['city'];
    $postfields['state'] = $billing_info['state'];
    $postfields['postcode'] = $billing_info['postal_code'];
    $postfields['country'] = $billing_info['country'];
    $postfields['phonenumber'] = $billing_info['phone'];
    $postfields['password2'] = $password;
    $postfields['currency'] = 1;
    $postfields['noemail'] = $noemail_account;

    $arr = json_post($whmcs_url, $postfields);
    unset($postfields);

    if ($arr['result'] == 'success') {
        $client_id = $arr['clientid'];
        echo "Success - Client ID = $client_id\n\n";
    } else {
        echo "***** failed! ***** $arr[message]\n\n";
        exit;
    }


    $query = "select p.id,p.billing,a.period_id,a.p_end from plans p, accounts a where p.id=a.plan_id and a.id='$account_id'";
    $result_tmp = pg_query($query) or die('Query failed: ' . pg_last_error());
    $plan = pg_fetch_array($result_tmp, null, PGSQL_ASSOC);
    if ($plan['billing'] == 1) {
        $bill_cycle_type = '_PERIOD_TYPE_'.$plan['period_id'];
        $bill_cycle_size = '_PERIOD_SIZE_'.$plan['period_id'];
        $query = "select value from plan_value where plan_id='$plan[id]' and name='$bill_cycle_type'";
        $result_b = pg_query($query) or die('Query failed: ' . pg_last_error());
        $bill_type = pg_fetch_result($result_b, "value");

        $query = "select value from plan_value where plan_id='$plan[id]' and name='$bill_cycle_size'";
        $result_b = pg_query($query) or die('Query failed: ' . pg_last_error());
        $bill_size = pg_fetch_result($result_b, "value");

        if (($bill_type == 'YEAR') || ($bill_type == 'year')) {
            if ($bill_size == 3) $bill_cycle = 'triennially';
            else if ($bill_size == 2) $bill_cycle = 'biennially';
            else $bill_cycle = 'annually';
        } else if (($bill_type == 'MONTH') || ($bill_type == 'month')) {
            if ($bill_size == 24) $bill_cycle = 'biennially';
            else if ($bill_size == 12) $bill_cycle = 'annually';
            else if ($bill_size == 6) $bill_cycle = 'semiannually';
            else if ($bill_size == 3) $bill_cycle = 'quarterly';
            else $bill_cycle = 'monthly';
        } else {
            echo "********** Billing cycle not found **********\n";
            $bill_cycle = 'onetime';
        }
    } else {
        $bill_cycle = 'FREE';
    }

    // These plan IDs and names need to be modified to match those in your H-sphere system.
    switch ($plan['id']) {
    case 12: #Economy
        $product_id = 1;
        $cp_package = $cpanel_api_username.'_Economy';
        break;
    case 9:  #Silver
    case 25:
        $product_id = 2;
        $cp_package = $cpanel_api_username.'_Silver';
        break;
    case 10: #Gold
    case 26:
        $product_id = 3;
        $cp_package = $cpanel_api_username.'_Gold';
        break;
    case 11: #Platinum
    case 27:
    case 53:
        $product_id = 4;
        $cp_package = $cpanel_api_username.'_Platinum';
        break;
    case 23: #Titanium
    case 24:
        $product_id = 5;
        $cp_package = $cpanel_api_username.'_Titanium';
        break;
    case 37: #Tungsten
    case 65:
        $product_id = 6;
        $cp_package = $cpanel_api_username.'_Tungsten';
        break;
    case 22: #COMP
    case 46:
        $product_id = 8;
        $cp_package = $cpanel_api_username.'_Comp';
        break;
    default:
        echo "Plan not found - setting to Silver default\n";
        $product_id = 2;
        $cp_package = $cpanel_api_username.'_Silver';
    }
    echo "Cpanel package: $cp_package\n\n";


    echo "***** Hosting/Domains\n";
    $dom_info=array();
    $query = "select d.name,pc.parent_id,pc.child_id,pc.child_type from parent_child pc left join domains d on d.id=pc.parent_id where pc.child_type in ($HOSTING,$MAIL,$HOSTING_ALIAS,$MAIL_ALIAS,$SUBDOMAIN) and pc.account_id='$account_id' order by pc.parent_id";
    $result_d = pg_query($query) or die('Query failed: ' . pg_last_error());
    while ($domains = pg_fetch_array($result_d, null, PGSQL_ASSOC)) {
        $cur_name = $domains['name'];
        $dom_info["$cur_name"]['name'] = $cur_name;
        $dom_info["$cur_name"]['parent_id'] = $domains['parent_id'];
        $child_type = $domains['child_type'];
        $dom_info["$cur_name"]["$child_type"] = $domains['child_id'];
    }
    unset($domains);

    if (!(isset($dom_info["$domainname"]["$HOSTING"])) && !(isset($dom_info["$domainname"]["$MAIL"]))) {
        echo "**** $cur_name is an alias domain ****\n";
        exit;
    }


    // add domains to WHMCS if needed - create main order
    foreach ($dom_info as $domain) {
        $cur_name = $domain['name'];
        echo "Domain: $cur_name";
        $register = '';
        $expire_date = '';
        $due_date = '';
        $inv_date = '';
        $query = "select o.* from opensrs o, parent_child pc where o.id = pc.child_id and pc.parent_id='$domain[parent_id]' and pc.account_id='$account_id'";
        $result_o = pg_query($query) or die('Query failed: ' . pg_last_error());
        while ($opensrs = pg_fetch_array($result_o, null, PGSQL_ASSOC)) {
            $register = 'register';
            echo " - REGISTERED\n";

            echo "Domain Registration Info\n";
            $query = "select dci.* from domain_contact_info dci left join parent_child pc on (pc.child_id=dci.id) where pc.child_type=4 and pc.parent_id='$domain[parent_id]' and pc.account_id='$account_id'";
            $result_reg = pg_query($query) or die('Query failed: ' . pg_last_error());
            $reg_contact = pg_fetch_array($result_reg, null, PGSQL_ASSOC);
            if ($debug) print_r($reg_contact);
            $query = "select dci.* from domain_contact_info dci left join parent_child pc on (pc.child_id=dci.id) where pc.child_type=5 and pc.parent_id='$domain[parent_id]' and pc.account_id='$account_id'";
            $result_reg = pg_query($query) or die('Query failed: ' . pg_last_error());
            $reg_billing = pg_fetch_array($result_reg, null, PGSQL_ASSOC);
            if ($debug) print_r($reg_billing);

            $renew = date_create($opensrs['last_payed']);
            $renew_period = '+'.$opensrs['period'].' years';
            $exp_date = date_modify($renew, $renew_period); 
            $expire_date = date_format($exp_date, 'Ymd');
            $due_date = date_format(date_modify($exp_date, '-2 day'), 'Ymd');
            $inv_date = date_format(date_modify($exp_date, '-15 day'), 'Ymd');
        }
        echo "\n";

        // find if domain is on dedicated IP
        $dedicated_ip = '';
        $query = "select ip from l_server_ips, domains, parent_child where domains.id = parent_child.parent_id and parent_child.child_id = l_server_ips.r_id and domains.name ='$cur_name'";
        $result_tmp = pg_query($query) or die('Query failed: ' . pg_last_error());
        $dedicated_ip = pg_fetch_result($result_tmp, 'ip');

        if ($dedicated_ip != '') {
            $domain_ip = $dedicated_ip;
            $dedicated = true;
        } else {
            $domain_ip = $shared_ip;
            $dedicated = false;
        }

        $dom_info["$cur_name"]['register'] = $register;
        if ($register == 'register') {
            $dom_info["$cur_name"]['contact'] = $reg_contact;
            $dom_info["$cur_name"]['billing'] = $reg_billing;
            $dom_info["$cur_name"]['expire'] = $expire_date;
            $dom_info["$cur_name"]['duedate'] = $due_date;
            $dom_info["$cur_name"]['invdate'] = $inv_date;
        }
        $dom_info["$cur_name"]['ip'] = $domain_ip;
        $dom_info["$cur_name"]['dedicated'] = $dedicated;

        if ($cur_name == $domainname) {
            echo '*** Creating WHMCS main hosting order: ';
            $postfields = array();
            $postfields['username'] = $whmcs_api_username;
            $postfields['password'] = md5($whmcs_api_password);
            $postfields['action'] = 'addorder';
            $postfields['responsetype'] = 'json';
            $postfields['clientid'] = "$client_id";
            $postfields['pid'] = "$product_id";
            $postfields['billingcycle'] = "$bill_cycle";
            $postfields['domain'] = "$domainname";
            $postfields['domaintype'] = "$register";
            $postfields['regperiod'] = "1";
            $postfields['paymentmethod'] = "mailin";
            $postfields['dnsmanagement'] = $dnsmanagement;
            $postfields['idprotection'] = $idprotection;
            $postfields['emailforwarding'] = $emailforwarding;
            $postfields['nameserver1'] = $ns1_server;
            $postfields['nameserver2'] = $ns2_server;
            $postfields['noinvoice'] = $noinvoice;
            $postfields['noinvoiceemail'] = $noinvoiceemail;
            $postfields['noemail'] = $noemail_order;

            $arr = json_post($whmcs_url, $postfields);
            unset($postfields);

            if ($arr['result'] == 'success') {
                echo "Success\n";
                $primary_order_id = $arr['orderid'];
                $primary_product_id = $arr['productids'];
                $primary_domain_id = $arr['domainids'];
                echo "WHMCS Order ID: $primary_order_id\n";
                echo "WHMCS Service ID: $primary_product_id\n";
                echo "WHMCS Domain ID: $primary_domain_id\n";

                echo 'Accepting WHMCS order: ';
                $postfields = array();
                $postfields['username'] = $whmcs_api_username;
                $postfields['password'] = md5($whmcs_api_password);
                $postfields['action'] = "acceptorder";
                $postfields['responsetype'] = "json";
                $postfields['orderid'] = "$primary_order_id";
                $postfields['autosetup'] = $autosetup;
                $postfields['sendregistrar'] = $sendregistrar;
                $postfields['serviceusername'] = $cp_username;
                $postfields['servicepassword'] = "$password";
                $postfields['sendemail'] = $sendemail_hosting;

                $arr = json_post($whmcs_url, $postfields);
                unset($postfields);

                if ($arr['result'] == 'success') {
                    echo "Success\n";

                    // add symlink for main domain
                    `ssh root@$cpanel_ip "ln -s public_html /home/$cp_username/$cur_name; chown -h $cp_username:nobody /home/$cp_username/$cur_name"`;
                    // `ssh root@$cpanel_ip "echo \"*@$cur_name\" >> /usr/local/assp/files/blockreportlist.txt"`;

                    echo 'Updating main order: ';
                    $postfields = array();
                    $postfields['username'] = $whmcs_api_username;
                    $postfields['password'] = md5($whmcs_api_password);
                    $postfields['action'] = 'updateclientproduct';
                    $postfields['responsetype'] = 'json';
                    $postfields['serviceid'] = "$primary_product_id";
                    $postfields['nextduedate'] = "$hosting_renew_date";
                    $postfields['regdate'] = "$hosting_created_date";

                    $arr = json_post($whmcs_url, $postfields);
                    unset($postfields);

                    if ($arr['result'] == 'success') {
                        echo "Success\n";
                    } else {
                        echo "*** update product failed! ***\n";
                        exit;
                    }

                    if ($register == 'register') {
                        echo 'Updating main domain: ';
                        $postfields = array();
                        $postfields['username'] = $whmcs_api_username;
                        $postfields['password'] = md5($whmcs_api_password);
                        $postfields['action'] = 'updateclientdomain';
                        $postfields['responsetype'] = 'json';
                        $postfields['domainid'] = "$primary_domain_id";
                        $postfields['registrar'] = 'enom';
                        $postfields['expirydate'] = "$expire_date";
                        $postfields['nextduedate'] = "$due_date";
                        $postfields['nextinvoicedate'] = "$inv_date";
                        $postfields['regperiod'] = '1';

                        $arr = json_post($whmcs_url, $postfields);
                        unset($postfields);

                        if ($arr['result'] == 'success') {
                            echo "Success\n";
                        } else {
                            echo "*** update domain failed! ***\n";
                            exit;
                        }
                    }

                } else {
                    echo "*** accept failed! ***\n";
                    exit;
                }
            } else {
                echo "*** order failed! ***\n";
                exit;
            }



        } else {
            if ($register == 'register') {
                echo "Adding $cur_name to WHMCS: ";
                $postfields = array();
                $postfields['username'] = $whmcs_api_username;
                $postfields['password'] = md5($whmcs_api_password);
                $postfields['action'] = 'addorder';
                $postfields['responsetype'] = 'json';
                $postfields['clientid'] = "$client_id";
                $postfields['domain'] = "$cur_name";
                $postfields['domaintype'] = "$register";
                $postfields['regperiod'] = '1';
                $postfields['dnsmanagement'] = $dnsmanagement;
                $postfields['idprotection'] = $idprotection;
                $postfields['emailforwarding'] = $emailforwarding;
                $postfields['paymentmethod'] = 'mailin';
                $postfields['nameserver1'] = $ns1_server;
                $postfields['nameserver2'] = $ns2_server;
                $postfields['noinvoice'] = $noinvoice;
                $postfields['noinvoiceemail'] = $noinvoiceemail;
                $postfields['noemail'] = $noemail_order;

                $arr = json_post($whmcs_url, $postfields);
                unset($postfields);

                if ($arr['result'] == 'success') {
                    echo "Success\n";
                    $add_whmcs_order_id = $arr['orderid'];
                    $add_whmcs_domain_id = $arr['domainids'];

                    echo 'Accepting WHMCS order: ';
                    $postfields = array();
                    $postfields['username'] = $whmcs_api_username;
                    $postfields['password'] = md5($whmcs_api_password);
                    $postfields['action'] = 'acceptorder';
                    $postfields['responsetype'] = 'json';
                    $postfields['orderid'] = "$add_whmcs_order_id";
                    $postfields['sendregistrar'] = $sendregistrar;
                    $postfields['sendemail'] = $sendemail_domain;

                    $arr = json_post($whmcs_url, $postfields);
                    unset($postfields);

                    if ($arr['result'] == 'success') {
                        echo "Success\n";

                        echo "Updating WHMCS for $cur_name: ";
                        $postfields = array();
                        $postfields['username'] = $whmcs_api_username;
                        $postfields['password'] = md5($whmcs_api_password);
                        $postfields['action'] = 'updateclientdomain';
                        $postfields['responsetype'] = 'json';
                        $postfields['domainid'] = "$add_whmcs_domain_id";
                        $postfields['registrar'] = 'enom';
                        $postfields['expirydate'] = "$expire_date";
                        $postfields['nextduedate'] = "$due_date";
                        $postfields['nextinvoicedate'] = "$inv_date";
                        $postfields['regperiod'] = '1';

                        $arr = json_post($whmcs_url, $postfields);
                        unset($postfields);

                        if ($arr['result'] == 'success') {
                            echo "Success\n";
                        } else {
                            echo "*** update domain failed! ***\n";
                            exit;
                        }
                    } else {
                        echo "*** accept domain failed! ***\n";
                        exit;
                    }
                } else {
                    echo "*** adding domain failed! ***\n";
                    exit;
                }
            }
        }
    }

    // Update account with credit card if exists
    if (isset($billing_info['cc_number'])) {
        echo 'Adding credit card to account - ';
        // $cctype = ucfirst(strtolower($billing_info['type']));
        $postfields = array();
        $postfields['username'] = $whmcs_api_username;
        $postfields['password'] = md5($whmcs_api_password);
        $postfields['action'] = 'updateclient';
        $postfields['responsetype'] = 'json';
        $postfields['clientid'] = "$client_id";
        $cc_num = $billing_info['cc_number'];
        $cctype = $card_type["$cc_num[0]"];
        $postfields['cardtype'] = "$cctype";
        $postfields['cardnum'] = "$cc_num";
        $year = substr($billing_info['exp_year'], -2);
        $card_exp_year = str_pad($year, 2, '0', STR_PAD_LEFT);
        $month = $billing_info['exp_month'];
        $card_exp_month = str_pad($month, 2, '0', STR_PAD_LEFT);
        $expdate = $card_exp_month.$card_exp_year;
        $postfields['expdate'] = "$expdate";
        $postfields['status'] = 'Active';

        $arr = json_post($whmcs_url, $postfields);
        unset($postfields);

        if ($arr['result'] == 'success') {
            echo "Success\n";
        } else {
            echo "***** update failed! *****\n\n";
        }
    }


    // create connection to cpanel
    $xmlapi = new xmlapi($cpanel_ip);
    $xmlapi->set_output('json');
    $xmlapi->password_auth($cpanel_api_username, $cpanel_api_password);
    if ($debug) $xmlapi->set_debug($debug_cpanel);


    foreach($dom_info as $domain) {
        $cur_name = $domain['name'];
        unset($rootdomain);
        if (isset($domain["$HOSTING"]) || isset($domain["$MAIL"])) {
            if ($cur_name != $domainname) {
                $site_path = "/home/$cp_username/$cur_name";
                if (substr_count($cur_name, '.') >= 2) {
                    list ($subdomain, $rootdomain) = explode($cur_name, '.', 2);
                }
                if (isset($rootdomain) && ($dom_info["$rootdomain"]["$SUBDOMAIN"] == $domain['parent_id'])) {
                    // add subdomain to cpanel account
                    echo "*** Creating CP subdomain $cur_name : ";
                    $jsondata = $xmlapi->api2_query($cp_username, 'SubDomain', 'addsubdomain', array('domain'=>"$subdomain", 'rootdomain'=>"$rootdomain", 'dir'=>"$site_path") );
                    $arr = json_decode($jsondata, true);
                    if ($arr['cpanelresult']['data'][0]['result'] == 1) {
                        echo "Success\n";
                        $cp_add = 1;
                    } else {
                        echo "*** Failed *** - $arr[cpanelresult][data][0][reason]\n";
                    }
                } else {
                    // add extra domain to cpanel account
                    echo "*** Creating CP addon domain $cur_name : ";
                    $subdomain = explode('.', $domain['name']);
                    // $subname = str_replace('-', '', $subdomain[0]);
                    $jsondata = $xmlapi->api2_query($cp_username, 'AddonDomain', 'addaddondomain', array('newdomain'=>"$cur_name", 'dir'=>"$cur_name", 'subdomain'=>"$subdomain[0]", 'pass'=>"$password") );
                    $arr = json_decode($jsondata, true);
                    if ($arr['cpanelresult']['data'][0]['result'] == 1) {
                        echo "Success\n";
                        $cp_add = 1;
                    } else {
                        echo "*** Failed *** - $arr[cpanelresult][data][0][reason]\n";
                    }
                }

            } else {
                $site_path = "/home/$cp_username/public_html";
                $cp_add = 1;
            }

            if ($cp_add) {
                if (isset($domain["$HOSTING"])) {
                    // transfer website content
                    echo "Tar'ing domain content for domain $cur_name\n";
                    `ssh root@$shared_ip "tar -czf /hsphere/transfer/$cur_name.tgz -C $user_dir $cur_name"`;
                    echo "Transferring domain content for $cur_name - ";
                    `ssh root@$cpanel_ip "scp root@$shared_ip:/hsphere/transfer/$cur_name.tgz /root/transfer; tar -xzf /root/transfer/$cur_name.tgz --strip-components=1 -C $site_path; chown -R $cp_username: $site_path/*; chown -R $cp_username: $site_path/.ht*; chown $cp_username:nobody $site_path; chmod 750 $site_path"`;
                    // `ssh root@$cpanel_ip "tar -xzf /tmp/$cur_name.tgz --strip-components=1 -C $site_path"`;
                    // `ssh root@$cpanel_ip "chown -R $cp_username: /home/$cp_username/$cur_name/*"`;
                    echo "done\n";
                }
            } else {
                echo "Can't transfer domain, CP add failed\n";
            }
        }


        if (isset($domain["$MAIL"]) && ($cp_add)) {
            $child_id = $domain["$MAIL"];
            // `ssh root@$cpanel_ip "echo \"*@$cur_name\" >> /usr/local/assp/files/blockreportlist.txt"`;
            // find mail domain ID and then query for mail resources
            $query = "select pc.child_id from parent_child pc where pc.child_type=1001 and pc.parent_id='$child_id' and pc.account_id='$account_id'";
            $result_m = pg_query($query) or die('Query failed: ' . pg_last_error());
            while ($maildomain = pg_fetch_array($result_m, null, PGSQL_ASSOC)) {
                echo "*** Creating mailboxes - mail domain ID: $maildomain[child_id]\n";
                // find postmaster mailbox (matches maildomain ID - not in normal search)
                $query = "select m.* from mailboxes m where m.discard_mail<>1 and m.id='$maildomain[child_id]'";
                $result_mbox = pg_query($query) or die('Query failed: ' . pg_last_error());
                while ($mailbox = pg_fetch_array($result_mbox, null, PGSQL_ASSOC)) {
                    $size_mb = 10;
                    $email_user = $mailbox['full_email'];
                    $email_pass = $mailbox['password'];
                    echo "Creating mailbox: $mailbox[full_email]  $size_mb  $mailbox[email]  $mailbox[password] - ";
                    $jsondata = $xmlapi->api2_query($cp_username, 'Email', 'addpop', array('domain'=>"$domain[name]", 'email'=>"$mailbox[email]", 'password'=>"$email_pass", 'quota'=>"$size_mb") );
                    $arr = json_decode($jsondata, true);
                    if ($arr['cpanelresult']['data'][0]['result'] == 1) {
                        echo "Success\n";
                        // echo "Migrating mailbox content\n";
                        // `$imapsync $imapsync_options --host1 $hsphere_mail --user1 $email_user --password1 $email_pass --host2 $cpanel_ip --user2 $email_user --password2 $email_pass`;
                    } else {
                        echo "*** Failed ***\n";
                    }
                }
                // find other mailboxes
                $query = "select pc.child_id from parent_child pc where pc.child_type=1002 and pc.parent_id='$maildomain[child_id]' and pc.account_id='$account_id'";
                $result_box = pg_query($query) or die('Query failed: ' . pg_last_error());
                while ($box_num = pg_fetch_array($result_box, null, PGSQL_ASSOC)) {
                    $query = "select m.*,q.size_mb from mailboxes m left join parent_child pc on pc.parent_id=m.id left join quotas q on pc.child_id=q.id where pc.child_type=1008 and m.discard_mail<>1 and m.id='$box_num[child_id]'";
                    $result_mbox = pg_query($query) or die('Query failed: ' . pg_last_error());
                    while ($mailbox = pg_fetch_array($result_mbox, null, PGSQL_ASSOC)) {
                        $email_user = $mailbox['full_email'];
                        $email_pass = $mailbox['password'];
                        echo "Creating mailbox: $mailbox[full_email]  $mailbox[size_mb]  $mailbox[email]  $mailbox[password]  $box_num[child_id] - ";
                        $jsondata = $xmlapi->api2_query($cp_username, 'Email', 'addpop', array('domain'=>"$domain[name]", 'email'=>"$mailbox[email]", 'password'=>"$mailbox[password]", 'quota'=>"$mailbox[size_mb]") );
                        $arr = json_decode($jsondata, true);
                        if ($arr['cpanelresult']['data'][0]['result'] == 1) {
                            echo "Success\n";
                            // echo "Migrating mailbox content\n";
                            // `$imapsync $imapsync_options --host1 $hsphere_mail --user1 $email_user --password1 $email_pass --host2 $cpanel_ip --user2 $email_user --password2 $email_pass`;
                        } else {
                            echo "*** Failed ***\n";
                        }
                    }
                }

                echo "*** Creating Mail Forwards\n";
                $query = "select pc.child_id from parent_child pc where pc.child_type=1004 and pc.parent_id='$maildomain[child_id]' and pc.account_id='$account_id'";
                $result_box = pg_query($query) or die('Query failed: ' . pg_last_error());
                while ($box_num = pg_fetch_array($result_box, null, PGSQL_ASSOC)) {
                    $query = "select * from mail_forwards where id='$box_num[child_id]'";
                    $result_mbox = pg_query($query) or die('Query failed: ' . pg_last_error());
                    while ($mailbox = pg_fetch_array($result_mbox, null, PGSQL_ASSOC)) {
                        echo "Creating forward: $mailbox[email_local]  =>  $mailbox[email_foreign]\n";
                        $recipients = explode(';', $mailbox['email_foreign']);
                        foreach ($recipients as $recipient) {
                            $recipient = trim($recipient);
                            if ($recipient == "") continue;
                            echo "Creating: $mailbox[email_local]  =>  $recipient - ";
                            $jsondata = $xmlapi->api2_query($cp_username, 'Email', 'addforward', array('domain'=>"$domain[name]", 'email'=>"$mailbox[email_local]", 'fwdopt'=>'fwd', 'fwdemail'=>"$recipient") );
                            $arr = json_decode($jsondata, true);
                            if ($arr['cpanelresult']['data'][0]['forward'] == $recipient) {
                                echo "Success\n";
                            } else {
                                echo "*** Failed ***\n";
                            }
                        }
                    }
                }

                echo "*** Creating Mail Aliases\n";
                $query = "select pc.child_id from parent_child pc where pc.child_type=1006 and pc.parent_id='$maildomain[child_id]' and pc.account_id='$account_id'";
                $result_box = pg_query($query) or die('Query failed: ' . pg_last_error());
                while ($box_num = pg_fetch_array($result_box, null, PGSQL_ASSOC)) {
                    $query = "select * from mail_aliases where id='$box_num[child_id]'";
                    $result_mbox = pg_query($query) or die('Query failed: ' . pg_last_error());
                    while ($mailbox = pg_fetch_array($result_mbox, null, PGSQL_ASSOC)) {
                        echo "Creating forward (alias): $mailbox[email_local]  =>  $mailbox[email_foreign]\n";
                        $recipients = explode(';', $mailbox['email_foreign']);
                        foreach ($recipients as $recipient) {
                            $recipient = trim($recipient);
                            if ($recipient == '') continue;
                            echo "Creating (alias): $mailbox[email_local]  =>  $recipient - ";
                            $fwdemail = "{$recipient}@{$domain['name']}";
                            $jsondata = $xmlapi->api2_query($cp_username, 'Email', 'addforward', array('domain'=>"$domain[name]", 'email'=>"$mailbox[email_local]", 'fwdopt'=>'fwd', 'fwdemail'=>"$fwdemail") );
                            $arr = json_decode($jsondata, true);
                            if ($arr['cpanelresult']['data'][0]['forward'] == $fwdemail) {
                                echo "Success\n";
                            } else {
                                echo "*** Failed ***\n";
                            }
                        }
                    }
                }

                echo "*** Creating Mail Responders\n";
                $query = "select pc.child_id from parent_child pc where pc.child_type=1005 and pc.parent_id='$maildomain[child_id]' and pc.account_id='$account_id'";
                $result_box = pg_query($query) or die('Query failed: ' . pg_last_error());
                while ($box_num = pg_fetch_array($result_box, null, PGSQL_ASSOC)) {
                    $query = "select * from responders where id='$box_num[child_id]'";
                    $result_mbox = pg_query($query) or die('Query failed: ' . pg_last_error());
                    while ($mailbox = pg_fetch_array($result_mbox, null, PGSQL_ASSOC)) {
                        echo "$mailbox[local_email]\n$mailbox[foreign_email]\n$mailbox[subject]\n$mailbox[message]\n$mailbox[include_incoming]\n$mailbox[sender_filter]\n\n";
                        echo "Creating Autoresponders: $mailbox[local_email] - ";
                        $startTS = time();
                        $endTS = $startTS + (365*7*24*60*60*100);
                        $from_email = $mailbox['local_email'].'@'.$domain['name'];
                        $params = array($mailbox['local_email'], $from_email, $mailbox['subject'], $mailbox['message'], $domain['name'], false, 'utf-8', 8, $startTS, $endTS);
                        $jsondata = $xmlapi->api1_query($cp_username, 'Email', 'addautoresponder', $params);
                        $arr = json_decode($jsondata, true);
                        if ($arr['event']['result'] == 1) {
                            echo "Success\n";
                        } else {
                            echo "*** Failed ***\n";
                        }
                    }
                }

                echo "*** Creating Mail Lists\n";
                $query = "select pc.child_id from parent_child pc where pc.child_type=1003 and pc.parent_id='$maildomain[child_id]' and pc.account_id='$account_id'";
                $result_box = pg_query($query) or die('Query failed: ' . pg_last_error());
                while ($box_num = pg_fetch_array($result_box, null, PGSQL_ASSOC)) {
                    $query = "select * from mailing_lists where id='$box_num[child_id]'";
                    $result_mbox = pg_query($query) or die('Query failed: ' . pg_last_error());
                    while ($mailbox = pg_fetch_array($result_mbox, null, PGSQL_ASSOC)) {
                        echo "\t\t\t$mailbox[email]\t$mailbox[trailer]\t$mailbox[archive_web_access]\n";
                    }
                }
            }
        }
    }

    foreach($dom_info as $domain) {
        $cur_name = $domain['name'];
        if (isset($domain["$HOSTING_ALIAS"])) {
            $child_id = $domain["$HOSTING_ALIAS"];
            $query = "select * from domain_resource_alias where id='$child_id' and actual_resource_type='hosting'";
            $result_a = pg_query($query) or die('Query failed: ' . pg_last_error());
            while ($alias = pg_fetch_array($result_a, null, PGSQL_ASSOC)) {
                // park domain hosting
                echo "*** Creating CP parking domain $cur_name -> $alias[actual_domain_name] : ";
                if ($alias['actual_domain_name'] == $domainname) {
                    $target_dom = '';
                } else {
                    $target_dom = $alias['actual_domain_name'];
                }
                $jsondata = $xmlapi->api2_query($cp_username, 'Park', 'park', array('domain'=>"$cur_name", 'topdomain'=>"$target_dom") );
                $arr = json_decode($jsondata, true);
                if ($debug_json) print_r($arr);
                if ($arr['cpanelresult']['data'][0]['result'] == 1) {
                    echo "Success\n";
                } else {
                    echo "*** Failed *** - $arr[cpanelresult][error]\n";
                }
            }
        }
        if (isset($domain["$MAIL_ALIAS"])) {
            $child_id = $domain["$MAIL_ALIAS"];
            $query = "select * from domain_resource_alias where id='$child_id' and actual_resource_type='mail_service'";
            $result_a = pg_query($query) or die('Query failed: ' . pg_last_error());
            while ($alias = pg_fetch_array($result_a, null, PGSQL_ASSOC)) {
                // create email domain alias
                echo "*** Creating CP domain forward $cur_name -> $alias[actual_domain_name] : ";
                // if ($alias['actual_domain_name'] == $domainname) {
                //     $target_dom = '';
                // } else {
                //     $target_dom = $alias['actual_domain_name'];
                // }
                $params = array($cur_name, $alias['actual_domain_name']);
                $jsondata = $xmlapi->api1_query($cp_username, 'Email', 'adddforward', $params);
                $arr = json_decode($jsondata, true);
                if ($debug_json) print_r($arr);
                if ($arr['error'] == '') {
                    echo "Success\n";
                } else {
                    echo "*** Failed *** - $arr[error]\n";
                }
            }
        }
    }


    // FTP subusers
    $query = "select u.login,u.password,u.dir from parent_child p, unix_user u where p.child_type=2010 and p.child_id=u.id and p.account_id='$account_id'";;
    $result_tmp = pg_query($query) or die('Query failed: ' . pg_last_error());
    while ($user_ftp = pg_fetch_array($result_tmp, null, PGSQL_ASSOC)) {
        $homedir = str_replace("/hsphere/local/home/$unixuser/", '', $user_ftp['dir']);
        $jsondata = $xmlapi->api2_query($cp_username, 'Ftp', 'addftp', array('user'=>"$user_ftp[login]", 'pass'=>"$user_ftp[password]", 'quota'=>'0', 'homedir'=>"$homedir") );
        $arr = json_decode($jsondata, true);
        if ($arr['cpanelresult']['data'][0]['result'] == 1) {
            echo "Success\n";


        } else {
            echo "*** Failed *** - $arr[cpanelresult][data][0][reason]\n";
        }
    }



    // Wait while DNS is updated
    echo 'Update DNS and enter Y to continue: ';
    $handle = fopen ('php://stdin', 'r');
    $line = fgets($handle);
    if(trim($line) != 'Y'){
        echo "ABORTING!\n";
        exit;
    }
    echo "\n"; 
    echo "Thank you, continuing...\n";

    echo "Transferring Databases\n";
    $db_cnt = 0;
    $query = "select m.id,m.db_name,m.db_description,mr.mysql_host_id from mysqldb m, parent_child pc, mysqlres mr where m.id=pc.child_id and pc.child_type=6001 and mr.id=pc.parent_id and pc.account_id='$account_id'";
    $result_db = pg_query($query) or die('Query failed: ' . pg_last_error());
    while ($databases = pg_fetch_array($result_db, null, PGSQL_ASSOC)) {
        echo "\t$databases[mysql_host_id]\t$databases[db_name]\t$databases[db_description]\n";
        if (!($db_cnt)) $mysql_host_id = $databases['mysql_host_id'];
        list($prefix, $subname) = explode('_', $databases['db_name'], 2);
        $subname = str_replace('\\', '', $subname);
        // $subname = str_replace('_', '', $subname);
        echo "$subname\n";
        $db["$subname"] = $databases['db_description'];
        $db_cnt++;
    }

    if ($db_cnt) {
        $query = "select name from l_server where id='$mysql_host_id'";
        $result_db = pg_query($query) or die('Query failed: ' . pg_last_error());
        $mysql_host = pg_fetch_result($result_db, 'name');

        $query = "select ip from l_server_ips where l_server_id='$mysql_host_id'";
        $result_db = pg_query($query) or die('Query failed: ' . pg_last_error());
        $mysql_ip = pg_fetch_result($result_db, 'ip');

        echo "Updating db host in files\n";
        `ssh root@$cpanel_ip "grep -rl $mysql_host /home/$cp_username/* | xargs sed -i s@$mysql_host@localhost@g; grep -rl $mysql_ip /home/$cp_username/* | xargs sed -i s@$mysql_ip@localhost@g"`;

        $mysql_pass = `ssh root@$mysql_ip 'grep password /var/lib/mysql/.my.cnf | cut -f2 -d='`;
        $mysql_pass = trim($mysql_pass);
        $mysql_link=mysqli_connect("$mysql_ip", 'root', "$mysql_pass") or die('Error ' . mysqli_error($mysql_link));

        echo "*** Database Users\n";
        $query = "select mu.login,mu.password,mu.old_password from mysql_users mu, parent_child pc where mu.id=pc.child_id and pc.child_type=6002 and pc.parent_id=mu.parent_id and pc.account_id='$account_id'";
        $result_db = pg_query($query) or die('Query failed: ' . pg_last_error());
        while ($db_user = pg_fetch_array($result_db, null, PGSQL_ASSOC)) {
            echo "\t$db_user[login]\t$db_user[password]\t$db_user[old_password]\n";
            $mquery = "select * from mysql.db where User='$db_user[login]'";
            $db_result = mysqli_query($mysql_link, $mquery);
            while ($mysql_user = mysqli_fetch_array($db_result)) {
                list($prefix, $subname) = explode('_', $mysql_user['Db'], 2);
                $subname = str_replace('\\', '', $subname);
                // $subname = str_replace('_', '', $subname);
                $new_db = $cp_username.'_'.$subname;
                if (!isset($db_done["$new_db"])) {
                    echo "Creating database $new_db : ";
                    $params = array("$subname");
                    $jsondata = $xmlapi->api1_query($cp_username, 'Mysql', 'adddb', $params);
                    $arr = json_decode($jsondata, true);
                    if ($arr['event']['result'] == 1) {
                        echo "Success\n";
                        $old_db = $mysql_user['Db'];
                        echo "Updating db name in files\n";
                        `ssh root@$cpanel_ip "grep -rl $old_db /home/$cp_username | xargs sed -i s@$old_db@$new_db@g"`;
                        echo "Transferring database $new_db content\n";
                        `ssh root@$mysql_ip "mysqldump --opt -p$mysql_pass $old_db > /root/transfer/$old_db.sql; gzip /root/transfer/$old_db.sql"`;
                        `ssh root@$cpanel_ip "scp root@$mysql_ip:/root/transfer/$old_db.sql.gz /root/transfer/; gunzip /root/transfer/$old_db.sql.gz; mysql -p$cpanel_db_pass $new_db < /root/transfer/$old_db.sql"`;
                        // `ssh root@$cpanel_ip "ssh root@$mysql_ip \"mysqldump --opt -uroot -p$mysql_pass $old_db\" | mysql -uroot -p$cpanel_db_pass $new_db"`;
                        // `mysqldump –add-drop-table –extended-insert –force -h$mysql_ip -uroot -p$mysql_pass $old_db | mysql -uroot -p$cpanel_db_pass $new_db`;
                        $db_done["$new_db"] = 1;
                    } else {
                        echo "*** Failed ***\n";
                    }
                }

                list($prefix, $subname) = explode('_', $mysql_user['User'], 2);
                $subname = str_replace('\\', '', $subname);
                // $subname = str_replace('_', '', $subname);
                $new_user = $cp_username."_".$subname;
                if (!isset($user_done["$new_db"])) {
                    echo "Creating db user $new_user : ";
                    $params = array($subname, $db_user['password']);
                    $jsondata = $xmlapi->api1_query($cp_username, 'Mysql', 'adduser', $params);
                    $arr = json_decode($jsondata, true);
                    if ($arr['event']['result'] == 1) {
                        echo "Success\n";
                        $old_user = $mysql_user['User'];
                        echo "Updating db user in files\n";
                        `ssh root@$cpanel_ip "grep -rl $old_user /home/$cp_username/* | xargs sed -i s@$old_user@$new_user@g"`;
                        $user_done["$new_user"] = 1;
                    } else {
                        echo "*** Failed ***\n";
                    }
                }

                echo "Adding user $new_user to db $new_db : ";
                $perm_string = '';
                if ($mysql_user['Alter_priv'] == 'Y') $perm_string .= 'alter ';
                if ($mysql_user['Create_tmp_table_priv'] == 'Y') $perm_string .= 'temporary ';
                if ($mysql_user['Create_routine_priv'] == 'Y') $perm_string .= 'routine ';
                if ($mysql_user['Create_priv'] == 'Y') $perm_string .= 'create ';
                if ($mysql_user['Delete_priv'] == 'Y') $perm_string .= 'delete ';
                if ($mysql_user['Drop_priv'] == 'Y') $perm_string .= 'drop ';
                if ($mysql_user['Select_priv'] == 'Y') $perm_string .= 'select ';
                if ($mysql_user['Insert_priv'] == 'Y') $perm_string .= 'insert ';
                if ($mysql_user['Update_priv'] == 'Y') $perm_string .= 'update ';
                if ($mysql_user['References_priv'] == 'Y') $perm_string .= 'references ';
                if ($mysql_user['Index_priv'] == 'Y') $perm_string .= 'index ';
                if ($mysql_user['Lock_tables_priv'] == 'Y') $perm_string .= 'lock ';
                $params = array($new_db, $new_user, "$perm_string");
                $jsondata = $xmlapi->api1_query($cp_username, 'Mysql', 'adduserdb', $params);
                $arr = json_decode($jsondata, true);
                if ($arr['event']['result'] == 1) {
                    echo "Success\n";
                } else {
                    echo "*** Failed ***\n";
                }
            }

            echo "Updating db perms\n";
            $params = array();
            $jsondata = $xmlapi->api1_query($cp_username, 'Mysql', 'updateprivs', $params);
            $arr = json_decode($jsondata, true);
        }
    }

    echo "Transferring crons\n";
    $query = "select * from crontab where login='$unixuser'";
    $result_cr = pg_query($query) or die('Query failed: ' . pg_last_error());
    while ($user_cron = pg_fetch_array($result_cr, null, PGSQL_ASSOC)) {
        $mailto=trim($user_cron['mailto']);
        echo "Setting cron mailto $mailto - ";
        $mailto=trim($user_cron['mailto']);
        $jsondata = $xmlapi->api2_query($cp_username, 'Cron', 'set_email', array('email'=>"$mailto") );
        $arr = json_decode($jsondata, true);
        if ($arr['cpanelresult']['data'][0]['status'] == 1) {
            echo "Success\n";
        } else {
            echo "*** Failed *** - $arr[cpanelresult][data][0][statusmsg]\n";
        }

        $cron_path = "/var/spool/cron/$user_cron[login]";
        $crontab = `ssh root@$shared_ip "grep -v MAILTO $cron_path"`;
        // remove crontab on Hsphere
        `ssh root@$shared_ip "crontab -u $user_cron[login] -r"`;
        // change /hsphere/shared/php5/bin/php-cli -> /usr/bin/php-cli
        $crontab = str_replace('/hsphere/shared/php5/bin/php-cli', '/usr/bin/php-cli', $crontab);
        $crontab = str_replace("$user_dir", "/home/$cp_username", $crontab);
        $crontab = str_replace("/$domainname/", '/public_html/', $crontab);
        $crons = explode("\n", $crontab);
        foreach($crons as $cron) {
            if (!empty($cron)) {
                echo "Creating cron: $cron\n";
                list($minute, $hour, $day, $month, $weekday, $cmd) = explode(' ', $cron, 6);

                // call Cpanel API to add cron entry
                $jsondata = $xmlapi->api2_query($cp_username, 'Cron', 'add_line', array('minute'=>"$minute", 'hour'=>"$hour", 'day'=>"$day", 'month'=>"$month", 'weekday'=>"$weekday", 'command'=>"$cmd") );
                $arr = json_decode($jsondata, true);
                if ($arr['cpanelresult']['data'][0]['status'] == 1) {
                    echo "Success\n";
                } else {
                    echo "*** Failed *** - $arr[cpanelresult][data][0][statusmsg]\n";
                }
            }
        }
    }


    // Transfer mailbox contents
    foreach($dom_info as $domain) {
        if (isset($domain["$MAIL"])) {
            $cur_name = $domain['name'];
            // `ssh root@$cpanel_ip "echo \"*@$cur_name\" >> /usr/local/assp/files/blockreportlist.txt"`;
            $child_id = $domain["$MAIL"];
            // find mail domain ID and then query for mail resources
            $query = "select pc.child_id from parent_child pc where pc.child_type=1001 and pc.parent_id='$child_id' and pc.account_id='$account_id'";
            $result_m = pg_query($query) or die('Query failed: ' . pg_last_error());
            while ($maildomain = pg_fetch_array($result_m, null, PGSQL_ASSOC)) {
                // find postmaster mailbox (matches maildomain ID - not in normal search)
                $query = "select m.* from mailboxes m where m.discard_mail<>1 and m.id='$maildomain[child_id]'";
                $result_mbox = pg_query($query) or die('Query failed: ' . pg_last_error());
                while ($mailbox = pg_fetch_array($result_mbox, null, PGSQL_ASSOC)) {
                    $email_user = $mailbox['full_email'];
                    $email_pass = $mailbox['password'];
                    echo "Migrating mailbox content - $email_user\n";
                    `$imapsync $imapsync_options --host1 $hsphere_mail --user1 $email_user --password1 "$email_pass" --host2 $cpanel_ip --user2 $email_user --password2 "$email_pass"`;
                }
                // find other mailboxes
                $query = "select pc.child_id from parent_child pc where pc.child_type=1002 and pc.parent_id='$maildomain[child_id]' and pc.account_id='$account_id'";
                $result_box = pg_query($query) or die('Query failed: ' . pg_last_error());
                while ($box_num = pg_fetch_array($result_box, null, PGSQL_ASSOC)) {
                    $query = "select m.*,q.size_mb from mailboxes m left join parent_child pc on pc.parent_id=m.id left join quotas q on pc.child_id=q.id where pc.child_type=1008 and m.discard_mail<>1 and m.id='$box_num[child_id]'";
                    $result_mbox = pg_query($query) or die('Query failed: ' . pg_last_error());
                    while ($mailbox = pg_fetch_array($result_mbox, null, PGSQL_ASSOC)) {
                        $email_user = $mailbox['full_email'];
                        $email_pass = $mailbox['password'];
                        echo "Migrating mailbox content - $email_user\n";
                        `$imapsync $imapsync_options --host1 $hsphere_mail --user1 $email_user --password1 "$email_pass" --host2 $cpanel_ip --user2 $email_user --password2 "$email_pass"`;
                    }
                }
            }
        }
    }

}

// Wait while reviewed
echo "Send emails?\n";
echo 'Enter Y to continue: ';
$handle = fopen ('php://stdin', 'r');
$line = fgets($handle);
if(trim($line) != 'Y'){
    echo "ABORTING!\n";
    exit;
}
echo "\n"; 

echo "*** Sending emails: \n";
$postfields = array();
$postfields['username'] = $whmcs_api_username;
$postfields['password'] = md5($whmcs_api_password);
$postfields['action'] = 'sendemail';
$postfields['responsetype'] = 'json';
$postfields['messagename'] = 'Account Migrated';
$postfields['id'] = "$client_id";

echo 'Welcome email - ';
$arr = json_post($whmcs_url, $postfields);
unset($postfields);

if ($arr['result'] == 'success') {
    echo "Sent\n";
} else {
    echo "***** failed! *****\n";
}

$postfields = array();
$postfields['username'] = $whmcs_api_username;
$postfields['password'] = md5($whmcs_api_password);
$postfields['action'] = 'sendemail';
$postfields['responsetype'] = 'json';
$postfields['messagename'] = 'Hosting Migration Email';
$postfields['id'] = "$primary_product_id";

echo 'Hosting email - ';
$arr = json_post($whmcs_url, $postfields);
unset($postfields);

if ($arr['result'] == 'success') {
    echo "Sent\n";
} else {
    echo "***** failed! *****\n";
}

/*
select * from parent_child where parent_type=2 and child_type=8 and account_id=$account_id
select * from parent_child where child_type=14 and parent_id=
 */

// Free resultset
pg_free_result($result);
// Closing connection
pg_close($dbconn);

?>
