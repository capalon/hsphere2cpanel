<?php

/**
 * Filename: config.php
 * Description: config file for H-sphere to Cpanel/WHMCS conversion script
 * Author: Ron Lockard
 * Date:  28 Aug 2008
 *
 * vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4:
 **/


/*
Needed preparations:
1) allow port 3306 and 5432 out of CPanel server
2) put CPanel server public SSH key on Hsphere servers
3) Download the CPanel xmlapi.php file, and rename it to cpanel-xmlapi.php in the same directory
 */

// Change this variable once you have configured the below settings
// You will also need to change the switch..case statement values in the hsphere2cpanel.php file to match your own plans
$configured = false;

if (!$configured) {
    echo "You must configure settings before it will work.\n";
    exit;
}

// WHMCS API info - use of WHMCS for billing is required
$whmcs_url = 'https://www.yourdomain.com/includes/api.php'; # URL to WHMCS API file goes here
$whmcs_api_username = 'whmcsapiuser'; # Admin username goes here
$whmcs_api_password = 'whmcsapipasswd'; # Admin password goes here

// CPanel API info
$cpanel_url = 'https://cp.yourdomain.com:2087/';
$cpanel_ip = '0.0.0.0';
$cpanel_api_username = 'apiuser';
$cpanel_api_password = 'apiuserpasswd';

// CPanel Mysql info
$cpanel_db_pass = 'cpaneldbpasswd';

// hsphere CP database info
$hsphere_db_host = '0.0.0.0';
$hsphere_db_name = 'hsphere';
$hsphere_db_user = 'wwwuser';
$hsphere_db_pass = 'hspeheredbpasswd';

// hsphere servers
$hsphere_webs = array('0.0.0.0');
$hsphere_mail = '0.0.0.0';

// name servers to use in CPanel domain setup
$ns1_server = 'ns1.yourdns.com';
$ns2_server = 'ns2.yourdns.com';

// path to imapsync util - pick options appropriately
$imapsync = '/usr/bin/imapsync';
//$imapsync_options = '--noreleasecheck --noexpunge --nofoldersizes';
//$imapsync_options = '--noreleasecheck --delete --noexpungeaftereach --nofoldersizes';
$imapsync_options = '--noreleasecheck --noexpunge --useuid --usecache --nofoldersizes';


// *** Below here are options ***
// order processing options
$noemail_account = true;
$noemail_order = true;
$noinvoice = true;
$noinvoiceemail = true;
$sendemail_hosting = false;
$sendemail_domain = false;
$autosetup = true;
$sendregistrar = false;

// domain options
$dnsmanagement = false;
$idprotection = false;
$emailforwarding = false;

// debug options
$debug = false;
$debug_cpanel = false;
$debug_json = false;

?>
