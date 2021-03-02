<?php
header('Access-Control-Allow-Credentials: true'); // Pass through to allow for basic rate limiting
date_default_timezone_set('America/Los_Angeles');

function generateRandomString($length = 3) {
    $characters = '0123456789';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

$dhlrand = generateRandomString();  // Cycle DHL keys to avoid hitting rate limit. Probably better to switch once hitting the rate limit instead of casting all of them
if($dhlrand >= 0  && $dhlrand <= 299 ) {
$dhlapi = ''; // Prod 1
}
elseif ($dhlrand >= 300  && $dhlrand <= 599) {
	$dhlapi = ''; // Prod 2
}
elseif ($dhlrand >= 600  && $dhlrand <= 899) {
	$dhlapi = ''; // Prod 3
}
else {
	$dhlapi = '';  // Prod 4	
}

// Credit to Tomoli75 for the session rate limiting https://gist.github.com/Tomoli75/394a47e391b966f5061dfa37b8633e44
session_start();
class RateExceededException extends Exception {}

class RateLimiter {
	private $prefix;
	public function __construct($token, $prefix = "rate") {
		$this->prefix = md5($prefix . $token);

		if( !isset($_SESSION["cache"]) ){
			$_SESSION["cache"] = array();
		}

		if( !isset($_SESSION["expiries"]) ){
			$_SESSION["expiries"] = array();
		}else{
			$this->expireSessionKeys();
		}
	}

	public function limitRequestsInMinutes($allowedRequests, $minutes) {
		$this->expireSessionKeys();
		$requests = 0;

		foreach ($this->getKeys($minutes) as $key) {
			$requestsInCurrentMinute = $this->getSessionKey($key);
			if (false !== $requestsInCurrentMinute) $requests += $requestsInCurrentMinute;
		}

		if (false === $requestsInCurrentMinute) {
			$this->setSessionKey( $key, 1, ($minutes * 60 + 1) );
		} else {
			$this->increment($key, 1);
		}
		if ($requests > $allowedRequests) throw new RateExceededException;
	}

	private function getKeys($minutes) {
		$keys = array();
		$now = time();
		for ($time = $now - $minutes * 60; $time <= $now; $time += 60) {
			$keys[] = $this->prefix . date("dHi", $time);
		}
		return $keys;
	}

	private function increment( $key, $inc){
		$cnt = 0;
		if( isset($_SESSION['cache'][$key]) ){
			$cnt = $_SESSION['cache'][$key];
		}
		$_SESSION['cache'][$key] = $cnt + $inc;
	}

	private function setSessionKey( $key, $val, $expiry ){
		$_SESSION["expiries"][$key] = time() + $expiry;
		$_SESSION['cache'][$key] = $val;
	}
	
	private function getSessionKey( $key ){
		return isset($_SESSION['cache'][$key]) ? $_SESSION['cache'][$key] : false;
	}

	private function expireSessionKeys() {
		foreach ($_SESSION["expiries"] as $key => $value) {
			if (time() > $value) { 
				unset($_SESSION['cache'][$key]);
				unset($_SESSION["expiries"][$key]);
			}
		}
	}
}


if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
    $rateLimiter = new RateLimiter($_SERVER["HTTP_CF_CONNECTING_IP"]);
  }
  else{
    $rateLimiter = new RateLimiter($_SERVER["REMOTE_ADDR"]);
  }


$limit = 5;				//	number of connections to limit user to per $minutes
$minutes = 1;				//	number of $minutes to check for.
$seconds = floor($minutes * 60);	//	retry after $minutes in seconds.

try {
	$rateLimiter->limitRequestsInMinutes($limit, $minutes);
} catch (RateExceededException $e) {
	header("HTTP/2 429 Too Many Requests");
	header(sprintf("Retry-After: %d", $seconds));
	$data = 'Rate Limit Exceeded ';
	die (json_encode($data));
}
// END RATE LIMITING 

// Begin Order Tracking 
if(isset($_POST['order']) && isset($_POST['email']) || isset($_GET['order']) && isset($_GET['email']) ) {

	if(isset($_GET['order']) && isset($_GET['email'])) {
		$orderid = $_GET['order'];
		$email = $_GET['email'];
		}

if(isset($_POST['order']) && isset($_POST['email'])) {
$orderid = $_POST['order'];
$email = $_POST['email'];
}



$shipexpdeliv = NULL;
$shippreddeliv = NULL;
$timestamps = '';
$description = '';
$currentloc = '';
$dhlstatus = '';
$dhldestaddress = '';
$dhldestpostcode = '';
$dhloriginaddress = '';
$dhloriginpostcode = '';
$dhlclass = '';



$orderid = substr($orderid, 0, 15);
$email = substr($email, 0, 254);
$orderid = preg_replace( '/[^0-9]/', '', $orderid );

if(!filter_var($orderid, FILTER_VALIDATE_INT)){
echo '<h1>No Order Found</h1>';
die;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "<h1>No Order Found</h1>";
    die;
  }

// SHOPIFY TRACKING INFORMATION
$shipurlpost =  curl_init("YOURSTOREURL orders.json?name=".$orderid."&status=any");
curl_setopt($shipurlpost, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($shipurlpost, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($shipurlpost, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($shipurlpost, CURLOPT_TIMEOUT, 30);
curl_setopt($shipurlpost, CURLOPT_POST, false);
curl_setopt($shipurlpost, CURLOPT_CUSTOMREQUEST, "GET");
curl_setopt($shipurlpost, CURLOPT_RETURNTRANSFER, true);
curl_setopt($shipurlpost, CURLOPT_HTTPHEADER, array("authorization: Basic",
"cache-control: no-cache", "Content-Type: application/json"));
$getShopifyOrder = curl_exec($shipurlpost);
$getShopifyOrders = json_decode($getShopifyOrder,true);
$tracking = $getShopifyOrders['orders'][0]['fulfillments'][0]['tracking_number'];
$orderemail = $getShopifyOrders['orders'][0]['email'];

if ($orderemail !== $email) {	
	echo '<h1>No Order Found</h1>';
	die;
}

if(empty($getShopifyOrders['orders'])){	  
echo '<h1>No Order Found</h1>';
die;
}


// Pre-Shipping Status
$shippost = '';
echo '<br><br>';
	if(empty($getShopifyOrders['orders'][0]['fulfillments'][0]['tracking_number'])){
		echo '<div class="catship_tracking_result"><div class="progress-bar-mobile-content"><b><div style="clear: both;"></div></div>
		<div class="track_left""><h1>Status : IN PRODUCTION</h1></b><p>Your order has now been sent to production and our ninja packers will have it shipped out ASAP. Have any questions? Contact us via email</p>';
		die;
	}

// Standard Error when not DHL Tracking
if(substr($tracking, 0, 2 ) === "UM"){
	echo '
	<div class="track_left""><h1>Status: Unable to Track</h1>

<ul class="catship_tracking_result_parent catship_timeline"><div class="timeline-item"><div class="timeline-badge"><img class="timeline-badge-userpic" src=""></div><div class="timeline-body"><div class="timeline-body-arrow"></div><div class="timeline-body-head">
<div class="timeline-body-head-caption"><span>Last Event: Unknown </span></div><div class="timeline-body-head-actions"></div></div><div class="timeline-body-content"><span class="font-grey-cascade"><h2>Unable to track this order via Catori API. Please visit <a href="https://a1.asendiausa.com/tracking/?trackingnumber='.htmlspecialchars($tracking).'"> https://a1.asendiausa.com/tracking/?trackingnumber='.htmlspecialchars($tracking).'</a></h2</span></div></div></div>

</div>

<div class="track_right" style="float: left; width: 30%;"><ul class="catship_tracking_info_parent"><div class="catship_tracking_info_title"><span>Carrier</span></div><div class="catship_tracking_info"><div class="catship_tracking_carrier_img" style="cursor: pointer;" >
	<svg xmlns="http://www.w3.org/2000/svg" width="100" viewBox="0 0 512 512"><path fill="#FFD300" d="M512 472c0 22.1-17.9 40-40 40H40c-22.1 0-40-17.9-40-40V40C0 17.9 17.9 0 40 0h432c22.1 0 40 17.9 40 40v432z"/><path fill-rule="evenodd" clip-rule="evenodd" fill="#FFF" d="M396.2 304.3l5.5-25H31.6l-5.5 25h370.1z"/><path fill-rule="evenodd" clip-rule="evenodd" fill="#EC1C24" d="M421.4 207.7h40.8l-6.5 30.3h30.2l-7.8 37.8h-30.3l-6 28.5h-33.3l2-9.9h23.2l5.8-28.2H470l3.8-18.6h-30.2l6.3-30.3h-20.5l-6.3 30.3h-10.3l8.6-39.9z"/><path fill-rule="evenodd" clip-rule="evenodd" fill="#231F20" d="M145.3 239h-11.1v.2l-16.1 35.1-.2-34.8v-.5H104.2l-.2.2-15.1 35.1-1.3-34.8v-.5H76.8l.2.5 3 47.9v.5h12.6v-.3l16.2-36.5.5 36.3v.5H122.1v-.3l23-47.9.2-.7zM158.9 239H147.9l-.3.5-10.1 47.9v.5h11.1v-.5l10.1-47.9.2-.5zM212.6 238.2c-8.6 0-18.4 3.8-18.4 14.9 0 7.3 5.3 10.1 9.6 12.6 3.8 2 6.8 3.8 6.8 7.6 0 4.8-4.3 6.8-8.6 6.8s-8.1-1.5-11.6-3.3l-.5-.3-.3.8-2.3 8.1-.3.5h.5c4.8 2 9.6 2.8 13.9 2.8 6.8 0 12.4-1.8 15.9-5.3 2.8-2.8 4.3-6.6 4.3-11.1 0-7.8-5.5-11.1-10.1-13.9-3.5-2-6.3-3.8-6.3-6.8 0-1 .5-2 1.3-2.8 1.5-1.5 4.3-2 6.3-2 3 0 6.8.8 9.6 2.3l.8.3v-.5l2.3-7.8.3-.5-.5-.3c-3.6-1.3-7.9-2.1-12.7-2.1zM191.2 240.5l-.5-.3c-3.5-1.3-7.6-2-12.6-2-8.3 0-18.2 3.8-18.2 14.9 0 7.3 5 10.1 9.6 12.6 3.5 2 6.6 3.8 6.6 7.6 0 4.8-4.3 6.8-8.6 6.8-4 0-8.1-1.5-11.3-3.3l-.5-.3-.3.8-2.5 8.1v.5h.3c5 2 9.6 2.8 13.9 2.8 7.1 0 12.6-1.8 16.1-5.3 2.8-2.8 4-6.6 4-11.1 0-7.8-5.5-11.1-10.1-13.9-3.3-2-6.3-3.8-6.3-6.8 0-1 .5-2 1.3-2.8 1.5-1.5 4.3-2 6.3-2 3.3 0 6.8.8 9.8 2.3l.5.3.3-.5 2.3-7.8v-.6h-.1zM62.1 238.2c-8.3 0-18.4 3.8-18.4 14.9 0 7.3 5.3 10.1 9.8 12.6 3.5 2 6.6 3.8 6.6 7.6 0 4.8-4.3 6.8-8.6 6.8s-8.1-1.5-11.6-3.3l-.5-.3v.8l-2.5 8.1-.3.5h.5c4.8 2 9.6 2.8 13.9 2.8 7.1 0 12.6-1.8 15.9-5.3 2.8-2.8 4.3-6.6 4.3-11.1 0-7.8-5.5-11.1-10.1-13.9-3.3-2-6.3-3.8-6.3-6.8 0-1 .5-2 1.3-2.8 1.5-1.5 4.3-2 6.3-2 3.3 0 6.8.8 9.8 2.3l.5.3v-.5L75 241l.3-.5-.5-.3c-3.6-1.2-7.9-2-12.7-2zM256.5 239H247.8v.5l-10.1 47.9v.3l-.3.3H248.1l.3-.5s3.3-14.6 3.3-15.6h5.3c6.3 0 12.4-2.3 16.1-6.1 3-3 4.8-7.3 4.8-12.1 0-3.8-1.3-7.1-3.5-9.3-4.3-4.4-12.1-5.4-17.9-5.4zm.3 8.6h2.8c2 0 4 .8 5.5 2 1.3 1.3 2 3 2 5 0 5.5-5.5 8.6-10.6 8.6h-2.8l3.1-15.6zM321.3 243.8c-3.5-3.5-8.6-5.5-15.1-5.5-8.3 0-14.4 2.8-19.9 9.1-5 5.8-7.8 13.4-7.8 21.2 0 6 2 11.3 5.8 15.1 3.5 3.5 8.1 5.3 13.6 5.3 8.8 0 15.9-2.8 20.7-8.3 5.3-5.8 8.3-13.4 8.3-21.4-.1-6.7-2.1-12-5.6-15.5zm-22.2 36.5c-2.5 0-4.8-.8-6.5-2.5-2-2-3-5-3-8.8 0-6.8 2.8-13.9 6.8-17.9 2.8-2.8 6-4.3 9.6-4.3 2.8 0 5 1 6.8 2.5 2 2 3 5 3 8.8-.1 9.1-5.8 22.2-16.7 22.2zM363.1 240.5c-3.5-1.5-7.8-2.3-12.6-2.3-8.6 0-18.4 4-18.4 15.1 0 7.3 5 10.1 9.6 12.6 3.5 2 6.8 3.8 6.8 7.3 0 4.8-4.3 7.1-8.6 7.1s-8.3-1.8-11.6-3.3l-.5-.3v.3l-.2.3-2.5 8.3v.6h.2c5 1.8 9.6 2.8 13.9 2.8 7.1 0 12.6-1.8 16.1-5.3 2.8-2.8 4-6.6 4-11.3 0-7.8-5.5-11.1-10.1-13.6-3.3-2-6-3.8-6-6.8 0-1 .3-2 1-3 2-1.8 5.5-2 6.6-2 3 0 6.6 1 9.6 2.5l.5.3v-.3l.2-.3 2.3-8.1v-.6h-.3zM404.2 239h-37.9l-.2.5-1.5 7.3v.5l-.3.3H378l-8.6 39.8v.3l-.3.3H380.3v-.5s8.3-38.1 8.8-39.8H402.4v-.3l.3-.3 1.5-7.6v-.5z"/></svg></div><div class="catship_tracking_carrier_info"><div class="catship_tracking_carrier_top"><span>Asendia / Swiss Post</span></div>
	<div class="catship_tracking_carrier_bottom"><span><a href="tel:+41-848-888-888">+41 848-888-888</a></span></div></div></div><br>
	<div class="catship_tracking_info_title"><span>Tracking Number</span></div>
	
	<div class="catship_tracking_info"><span><a href="https://a1.asendiausa.com/tracking/?trackingnumber='.htmlspecialchars($tracking).'">'.htmlspecialchars($tracking).'</a></span></div>';
	
	
	die;
}

// CURL Multi Request for DHL API and USPS API for estimated delivery date
$ch_1 = curl_init("https://secure.shippingapis.com/ShippingAPI.dll?API=TrackV2&XML=%3CTrackFieldRequest%20USERID=Revision%3E1%3C/Revision%3E%3CClientIp%3E%3C/ClientIp%3E%3CSourceId%3EJohn%20Doe%3C/SourceId%3E%3CTrackID%20ID=%22$tracking%22%3E%3C/TrackID%3E%3C/TrackFieldRequest%3E");
$ch_2 = curl_init("https://api-eu.dhl.com/track/shipments?trackingNumber='$tracking'");

curl_setopt($ch_1, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch_2, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch_1, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch_2, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch_1, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch_2, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch_1, CURLOPT_POST, TRUE);
curl_setopt($ch_2, CURLOPT_POST, FALSE);
curl_setopt($ch_1, CURLOPT_ENCODING,  '');
curl_setopt($ch_2, CURLOPT_ENCODING,  '');
curl_setopt($ch_1, CURLOPT_HTTPHEADER, array("cache-control: no-cache", "Content-Type: application/xml", "Application-Type: application/xml"));
curl_setopt($ch_2, CURLOPT_HTTPHEADER, array("DHL-API-Key: $dhlapi", "cache-control: no-cache", "Content-Type: application/json", "Accept: application/json"));
$mh = curl_multi_init();
curl_multi_add_handle($mh, $ch_1);
curl_multi_add_handle($mh, $ch_2);

$running = null;
do {
  curl_multi_exec($mh, $running);
} while ($running);

curl_multi_remove_handle($mh, $ch_1);
curl_multi_remove_handle($mh, $ch_2);
curl_multi_close($mh);
$getshippinginfo = curl_multi_getcontent($ch_1);
$getdhlshippinginfo = curl_multi_getcontent($ch_2);

 // USPS Processing
    $xml = simplexml_load_string($getshippinginfo, "SimpleXMLElement", LIBXML_NOCDATA);
    $json = json_encode($xml);
	$array = json_decode($json,true);

	// DHL Processing
	$dhljson = json_decode($getdhlshippinginfo, true);
	$statuscount = count($dhljson['shipments'][0]['events']);

	if(!empty($dhljson['shipments'][0]['events'][0]['statusCode'])) {$dhlstatus = $dhljson['shipments'][0]['events'][0]['statusCode'];}
	if(!empty($dhljson['shipments'][0]['events'][0]['status'])) {$dhldelivstatus = $dhljson['shipments'][0]['events'][0]['status'];}
	if(!empty($dhljson['shipments'][0]['details']['product']['productName'])) {$dhlclass = $dhljson['shipments'][0]['details']['product']['productName'];}
	if(!empty($dhljson['shipments'][0]['origin']['address']['postalCode'])) {$dhloriginpostcode = $dhljson['shipments'][0]['origin']['address']['postalCode'];}
	if(!empty($dhljson['shipments'][0]['origin']['address']['addressLocality'])) {$dhloriginaddress = $dhljson['shipments'][0]['origin']['address']['addressLocality'];}
	if(!empty($dhljson['shipments'][0]['destination']['address']['postalCode'])) {$dhldestpostcode = $dhljson['shipments'][0]['destination']['address']['postalCode'];}
	if(!empty($dhljson['shipments'][0]['destination']['address']['addressLocality'])) {$dhldestaddress = $dhljson['shipments'][0]['destination']['address']['addressLocality'];}
	if(!empty($array['TrackInfo']['ExpectedDeliveryDate'])) {$shipexpdeliv = $array['TrackInfo']['ExpectedDeliveryDate'];}
	if(!empty($array['TrackInfo']['PredictedDeliveryDate'])) {$shippreddeliv = $array['TrackInfo']['PredictedDeliveryDate'];}
	if(!empty($dhljson['shipments'][0]['events'][$statuscount-1]['timestamp'])) {$timestamps = $dhljson['shipments'][0]['events'][$statuscount-1]['timestamp']; $timestamps = date("F j", strtotime($timestamps));}
	if(!empty($dhljson['shipments'][0]['events'][0]['timestamp'])) {$timedeliv = $dhljson['shipments'][0]['events'][0]['timestamp']; $timedeliv = date("F j", strtotime($timedeliv));}



echo '<br> <div class="catship_tracking_result">';


if ($dhlstatus == 'pre-transit') {
echo '<div class="progress-bar-style"><div><span style="background:#ff2300;width:3%"></span></div>
<span class="progress-bar-node" style="left:0%;font-size:77%;background:#ff2300;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M19.901 4.581c-.004-.009-.002-.019-.006-.028l-2-4A1.001 1.001 0 0 0 17 0H3c-.379 0-.725.214-.895.553l-2 4c-.004.009-.002.019-.006.028A.982.982 0 0 0 0 5v14a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V5a.982.982 0 0 0-.099-.419zM2 18V6h7v1a1 1 0 0 0 2 0V6h7v12H2zM3.618 2H9v2H2.618l1-2zm13.764 2H11V2h5.382l1 2zM9 14H5a1 1 0 0 0 0 2h4a1 1 0 0 0 0-2m-4-2h2a1 1 0 0 0 0-2H5a1 1 0 0 0 0 2"></path></svg>
<span><b>Order&nbsp;Ready</b> <span> '.$timestamps.'</span></span></span>

<span class="progress-bar-node" style="left:33.3%;font-size:77%;background:#C6C6C6;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M17.816 14c-.415-1.162-1.514-2-2.816-2s-2.4.838-2.816 2H12v-4h6v4h-.184zM15 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM5 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM2 4h8v10H7.816C7.4 12.838 6.302 12 5 12s-2.4.838-2.816 2H2V4zm13.434 1l1.8 3H12V5h3.434zm4.424 3.485l-3-5C16.678 3.185 16.35 3 16 3h-4a1 1 0 0 0-1-1H1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h1.185C2.6 17.162 3.698 18 5 18s2.4-.838 2.816-2h4.37c.413 1.162 1.512 2 2.814 2s2.4-.838 2.816-2H19a1 1 0 0 0 1-1V9c0-.18-.05-.36-.142-.515z"></path></svg>
<span><b>In Transit</b> <span> </span></span></span>

<span class="progress-bar-node" style="left:66.66%;font-size:77%;background:#C6C6C6;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 0C5.589 0 2 3.589 2 8c0 7.495 7.197 11.694 7.504 11.869a.996.996 0 0 0 .992 0C10.803 19.694 18 15.495 18 8c0-4.412-3.589-8-8-8m-.001 17.813C8.478 16.782 4 13.296 4 8c0-3.31 2.691-6 6-6s6 2.69 6 6c0 5.276-4.482 8.778-6.001 9.813M10 10c-1.103 0-2-.897-2-2s.897-2 2-2 2 .897 2 2-.897 2-2 2m0-6C7.794 4 6 5.794 6 8s1.794 4 4 4 4-1.794 4-4-1.794-4-4-4"></path></svg>
<span><b>Out of Delivery</b> <span></span></span></span>

<span class="progress-bar-node" style="left:100%;font-size:77%;background:#C6C6C6;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 18c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8-3.589 8-8 8m0-14c-3.309 0-6 2.691-6 6s2.691 6 6 6 6-2.691 6-6-2.691-6-6-6m-1 9a.997.997 0 0 1-.707-.293l-2-2a.999.999 0 1 1 1.414-1.414L9 10.586l3.293-3.293a.999.999 0 1 1 1.414 1.414l-4 4A.997.997 0 0 1 9 13"></path></svg>
<span><b>Delivered</b> <span> </span></span></span></div>

';
}


if($dhlstatus == 'transit' && $dhldelivstatus != 'Out for Delivery'){ 
	echo '<div class="progress-bar-style"><div><span style="background:#ff2300;width:38%"></span></div>

	<span class="progress-bar-node" style="left:0%;font-size:77%;background:#ff2300;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M19.901 4.581c-.004-.009-.002-.019-.006-.028l-2-4A1.001 1.001 0 0 0 17 0H3c-.379 0-.725.214-.895.553l-2 4c-.004.009-.002.019-.006.028A.982.982 0 0 0 0 5v14a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V5a.982.982 0 0 0-.099-.419zM2 18V6h7v1a1 1 0 0 0 2 0V6h7v12H2zM3.618 2H9v2H2.618l1-2zm13.764 2H11V2h5.382l1 2zM9 14H5a1 1 0 0 0 0 2h4a1 1 0 0 0 0-2m-4-2h2a1 1 0 0 0 0-2H5a1 1 0 0 0 0 2"></path></svg>
<span><b>Order&nbsp;Ready</b> <span> '.$timestamps.'</span></span></span>


	<span class="progress-bar-node" style="left:33.3%;font-size:77%;background:#ff2300;">
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M17.816 14c-.415-1.162-1.514-2-2.816-2s-2.4.838-2.816 2H12v-4h6v4h-.184zM15 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM5 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM2 4h8v10H7.816C7.4 12.838 6.302 12 5 12s-2.4.838-2.816 2H2V4zm13.434 1l1.8 3H12V5h3.434zm4.424 3.485l-3-5C16.678 3.185 16.35 3 16 3h-4a1 1 0 0 0-1-1H1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h1.185C2.6 17.162 3.698 18 5 18s2.4-.838 2.816-2h4.37c.413 1.162 1.512 2 2.814 2s2.4-.838 2.816-2H19a1 1 0 0 0 1-1V9c0-.18-.05-.36-.142-.515z"></path></svg>
	<span><b>In Transit</b> <span> </span></span></span>
	
	<span class="progress-bar-node" style="left:66.66%;font-size:77%;background:#C6C6C6;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 0C5.589 0 2 3.589 2 8c0 7.495 7.197 11.694 7.504 11.869a.996.996 0 0 0 .992 0C10.803 19.694 18 15.495 18 8c0-4.412-3.589-8-8-8m-.001 17.813C8.478 16.782 4 13.296 4 8c0-3.31 2.691-6 6-6s6 2.69 6 6c0 5.276-4.482 8.778-6.001 9.813M10 10c-1.103 0-2-.897-2-2s.897-2 2-2 2 .897 2 2-.897 2-2 2m0-6C7.794 4 6 5.794 6 8s1.794 4 4 4 4-1.794 4-4-1.794-4-4-4"></path></svg>
<span><b>Out of Delivery</b> <span></span></span></span>

<span class="progress-bar-node" style="left:100%;font-size:77%;background:#C6C6C6;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 18c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8-3.589 8-8 8m0-14c-3.309 0-6 2.691-6 6s2.691 6 6 6 6-2.691 6-6-2.691-6-6-6m-1 9a.997.997 0 0 1-.707-.293l-2-2a.999.999 0 1 1 1.414-1.414L9 10.586l3.293-3.293a.999.999 0 1 1 1.414 1.414l-4 4A.997.997 0 0 1 9 13"></path></svg>
<span><b>Delivered</b> <span> </span></span></span></div>
	';

	
}

if($dhlstatus == 'transit' && $dhldelivstatus == 'Out for Delivery' ){ 
	echo '<div class="progress-bar-style"><div><span style="background:#ff2300;width:69.66%"></span></div>

	<span class="progress-bar-node" style="left:0%;font-size:77%;background:#ff2300;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M19.901 4.581c-.004-.009-.002-.019-.006-.028l-2-4A1.001 1.001 0 0 0 17 0H3c-.379 0-.725.214-.895.553l-2 4c-.004.009-.002.019-.006.028A.982.982 0 0 0 0 5v14a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V5a.982.982 0 0 0-.099-.419zM2 18V6h7v1a1 1 0 0 0 2 0V6h7v12H2zM3.618 2H9v2H2.618l1-2zm13.764 2H11V2h5.382l1 2zM9 14H5a1 1 0 0 0 0 2h4a1 1 0 0 0 0-2m-4-2h2a1 1 0 0 0 0-2H5a1 1 0 0 0 0 2"></path></svg>
<span><b>Order&nbsp;Ready</b> <span>'.$timestamps.' </span></span></span>


	<span class="progress-bar-node" style="left:33.33%;font-size:77%;background:#ff2300;">
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M17.816 14c-.415-1.162-1.514-2-2.816-2s-2.4.838-2.816 2H12v-4h6v4h-.184zM15 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM5 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM2 4h8v10H7.816C7.4 12.838 6.302 12 5 12s-2.4.838-2.816 2H2V4zm13.434 1l1.8 3H12V5h3.434zm4.424 3.485l-3-5C16.678 3.185 16.35 3 16 3h-4a1 1 0 0 0-1-1H1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h1.185C2.6 17.162 3.698 18 5 18s2.4-.838 2.816-2h4.37c.413 1.162 1.512 2 2.814 2s2.4-.838 2.816-2H19a1 1 0 0 0 1-1V9c0-.18-.05-.36-.142-.515z"></path></svg>
	<span><b>In Transit</b> <span>  </span></span></span>
	
	<span class="progress-bar-node" style="left:66.66%;font-size:77%;background:#ff2300;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 0C5.589 0 2 3.589 2 8c0 7.495 7.197 11.694 7.504 11.869a.996.996 0 0 0 .992 0C10.803 19.694 18 15.495 18 8c0-4.412-3.589-8-8-8m-.001 17.813C8.478 16.782 4 13.296 4 8c0-3.31 2.691-6 6-6s6 2.69 6 6c0 5.276-4.482 8.778-6.001 9.813M10 10c-1.103 0-2-.897-2-2s.897-2 2-2 2 .897 2 2-.897 2-2 2m0-6C7.794 4 6 5.794 6 8s1.794 4 4 4 4-1.794 4-4-1.794-4-4-4"></path></svg>
<span><b>Out of Delivery</b> <span></span></span></span>

<span class="progress-bar-node" style="left:100%;font-size:77%;background:#C6C6C6;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 18c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8-3.589 8-8 8m0-14c-3.309 0-6 2.691-6 6s2.691 6 6 6 6-2.691 6-6-2.691-6-6-6m-1 9a.997.997 0 0 1-.707-.293l-2-2a.999.999 0 1 1 1.414-1.414L9 10.586l3.293-3.293a.999.999 0 1 1 1.414 1.414l-4 4A.997.997 0 0 1 9 13"></path></svg>
<span><b>Delivered</b> <span> </span></span></span></div>
	
	';
}


if($dhlstatus == 'delivered'){ 

	echo '<div class="progress-bar-style"><div><span style="background:#ff2300;width:100%"></span></div>

	<span class="progress-bar-node" style="left:0%;font-size:77%;background:#ff2300;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M19.901 4.581c-.004-.009-.002-.019-.006-.028l-2-4A1.001 1.001 0 0 0 17 0H3c-.379 0-.725.214-.895.553l-2 4c-.004.009-.002.019-.006.028A.982.982 0 0 0 0 5v14a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V5a.982.982 0 0 0-.099-.419zM2 18V6h7v1a1 1 0 0 0 2 0V6h7v12H2zM3.618 2H9v2H2.618l1-2zm13.764 2H11V2h5.382l1 2zM9 14H5a1 1 0 0 0 0 2h4a1 1 0 0 0 0-2m-4-2h2a1 1 0 0 0 0-2H5a1 1 0 0 0 0 2"></path></svg>
<span><b>Order&nbsp;Ready</b> <span>'.$timestamps.'</span></span></span>


	<span class="progress-bar-node" style="left:33.33%;font-size:77%;background:#ff2300;">
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M17.816 14c-.415-1.162-1.514-2-2.816-2s-2.4.838-2.816 2H12v-4h6v4h-.184zM15 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM5 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM2 4h8v10H7.816C7.4 12.838 6.302 12 5 12s-2.4.838-2.816 2H2V4zm13.434 1l1.8 3H12V5h3.434zm4.424 3.485l-3-5C16.678 3.185 16.35 3 16 3h-4a1 1 0 0 0-1-1H1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h1.185C2.6 17.162 3.698 18 5 18s2.4-.838 2.816-2h4.37c.413 1.162 1.512 2 2.814 2s2.4-.838 2.816-2H19a1 1 0 0 0 1-1V9c0-.18-.05-.36-.142-.515z"></path></svg>	
	<span><b>In Transit</b> <span></span></span></span>
	
	<span class="progress-bar-node" style="left:66.66%;font-size:77%;background:#ff2300;">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 0C5.589 0 2 3.589 2 8c0 7.495 7.197 11.694 7.504 11.869a.996.996 0 0 0 .992 0C10.803 19.694 18 15.495 18 8c0-4.412-3.589-8-8-8m-.001 17.813C8.478 16.782 4 13.296 4 8c0-3.31 2.691-6 6-6s6 2.69 6 6c0 5.276-4.482 8.778-6.001 9.813M10 10c-1.103 0-2-.897-2-2s.897-2 2-2 2 .897 2 2-.897 2-2 2m0-6C7.794 4 6 5.794 6 8s1.794 4 4 4 4-1.794 4-4-1.794-4-4-4"></path></svg>
<span><b>Out for Delivery</b> <span> </span></span></span>


<span class="progress-bar-node" style="left:100%;font-size:77%;background:#ff2300;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 18c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8-3.589 8-8 8m0-14c-3.309 0-6 2.691-6 6s2.691 6 6 6 6-2.691 6-6-2.691-6-6-6m-1 9a.997.997 0 0 1-.707-.293l-2-2a.999.999 0 1 1 1.414-1.414L9 10.586l3.293-3.293a.999.999 0 1 1 1.414 1.414l-4 4A.997.997 0 0 1 9 13"></path></svg>
<span><b>Delivered</b> <span> '.$timedeliv.' </span></span></span></div>';
}



if(!is_null($shipexpdeliv) && is_null($shippreddeliv)) {
	$delivertext = 'Estimated Delivery - ';
	echo '<b>Estimated Delivery Date:</b> '.$shipexpdeliv.''; 
}

	if(is_null($shipexpdeliv) && !is_null($shippreddeliv)) {
		$delivertext = 'Predicted Delivery - ';
		echo '<b>Predicted Delivery Date:</b> '.$shippreddeliv.''; }
	
		if(is_null($shipexpdeliv) && is_null($shippreddeliv))
		{
			$shipexpdeliv = 'N/A';
			$shippreddeliv = '';
		}
		
if ($dhlstatus == 'delivered') {
echo '</div></div><div style="clear: both;"></div></div>
<div class="track_left"><h1>Status : <span style="color: #4BB543;">'.htmlspecialchars($dhlstatus).'</span></h1>'; }
elseif ($dhlstatus == 'failure') {
		echo '</div></div><div style="clear: both;"></div></div>
		<div class="track_left"><h1>Status : <span style="color: red;">'.htmlspecialchars($dhlstatus).'</span></h1>';
}
else {
	echo '</div></div><div style="clear: both;"></div></div>
	<div class="track_left"><h1>Status : '.htmlspecialchars($dhlstatus).'</h1>';
}


for ($i=0;$i<$statuscount;$i++)  {
	$description = NULL;
	$dhlstatus = $dhljson['shipments'][0]['events'][$i]['status'];

if($dhlstatus == 'Processed'){continue;}

if($dhlstatus == 'EN ROUTE TO DHL ECOMMERCE'){
	$dhlstatus = 'Package has left for'
}
if($dhlstatus == 'NOTICE LEFT'){
	$dhlstatus = 'Notice Left. Held at Post Office, At Customer Request'
}

if($dhlstatus == 'TENDERED TO DELIVERY SERVICE PROVIDER'){
	$dhlstatus = 'Package Handed to USPS for Local Delivery';
}

if($dhlstatus == 'DEPARTURE ORIGIN DHL ECOMMERCE FACILITY'){
	$dhlstatus = 'Departed DHL Origin for Destination';
}

if($dhlstatus == 'ARRIVAL DESTINATION DHL ECOMMERCE FACILITY'){
	$dhlstatus = 'Arrived at DHL Destination Facility';
}

	if(!empty($dhljson['shipments'][0]['events'][$i]['timestamp'])) {	$timestamps = $dhljson['shipments'][0]['events'][$i]['timestamp'];
		$timestamps = date("F jS, Y H:i T", strtotime($timestamps)); }

	if(!empty($dhljson['shipments'][0]['events'][$i]['location']['address']['postalCode'])) { $postalCode = $dhljson['shipments'][0]['events'][$i]['location']['address']['postalCode']; }
	if(!empty($dhljson['shipments'][0]['events'][$i]['location']['address']['addressLocality'])) { $currentloc = $dhljson['shipments'][0]['events'][$i]['location']['address']['addressLocality']; }
	if(!empty($dhljson['shipments'][0]['events'][$i]['statusCode'])) { $statusCode = $dhljson['shipments'][0]['events'][$i]['statusCode']; }
	if(!empty($dhljson['shipments'][0]['events'][$i]['description'])) { $description = $dhljson['shipments'][0]['events'][$i]['description'];}
	

echo '<ul class="catship_tracking_result_parent catship_timeline"><div class="timeline-item">
';

if ($dhlstatus == 'Electronic Notification Received: Your order has been processed and tracking will be updated soon') { echo '<div class="timeline-badge timeline-badge-userpic"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M19.901 4.581c-.004-.009-.002-.019-.006-.028l-2-4A1.001 1.001 0 0 0 17 0H3c-.379 0-.725.214-.895.553l-2 4c-.004.009-.002.019-.006.028A.982.982 0 0 0 0 5v14a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V5a.982.982 0 0 0-.099-.419zM2 18V6h7v1a1 1 0 0 0 2 0V6h7v12H2zM3.618 2H9v2H2.618l1-2zm13.764 2H11V2h5.382l1 2zM9 14H5a1 1 0 0 0 0 2h4a1 1 0 0 0 0-2m-4-2h2a1 1 0 0 0 0-2H5a1 1 0 0 0 0 2"></path></svg></div>';}
if ($dhlstatus == 'Out for Delivery') { echo '<div class="timeline-badge timeline-badge-userpic"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 0C5.589 0 2 3.589 2 8c0 7.495 7.197 11.694 7.504 11.869a.996.996 0 0 0 .992 0C10.803 19.694 18 15.495 18 8c0-4.412-3.589-8-8-8m-.001 17.813C8.478 16.782 4 13.296 4 8c0-3.31 2.691-6 6-6s6 2.69 6 6c0 5.276-4.482 8.778-6.001 9.813M10 10c-1.103 0-2-.897-2-2s.897-2 2-2 2 .897 2 2-.897 2-2 2m0-6C7.794 4 6 5.794 6 8s1.794 4 4 4 4-1.794 4-4-1.794-4-4-4"></path></svg></div>';}
if ($dhlstatus == 'Package has left for') { echo '<div class="timeline-badge timeline-badge-userpic"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M17.816 14c-.415-1.162-1.514-2-2.816-2s-2.4.838-2.816 2H12v-4h6v4h-.184zM15 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM5 16c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zM2 4h8v10H7.816C7.4 12.838 6.302 12 5 12s-2.4.838-2.816 2H2V4zm13.434 1l1.8 3H12V5h3.434zm4.424 3.485l-3-5C16.678 3.185 16.35 3 16 3h-4a1 1 0 0 0-1-1H1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h1.185C2.6 17.162 3.698 18 5 18s2.4-.838 2.816-2h4.37c.413 1.162 1.512 2 2.814 2s2.4-.838 2.816-2H19a1 1 0 0 0 1-1V9c0-.18-.05-.36-.142-.515z"></path></svg></div>';}
if ($statusCode == 'delivered') { echo '<div class="timeline-badge timeline-badge-userpic"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 18c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8-3.589 8-8 8m0-14c-3.309 0-6 2.691-6 6s2.691 6 6 6 6-2.691 6-6-2.691-6-6-6m-1 9a.997.997 0 0 1-.707-.293l-2-2a.999.999 0 1 1 1.414-1.414L9 10.586l3.293-3.293a.999.999 0 1 1 1.414 1.414l-4 4A.997.997 0 0 1 9 13"></path></svg></div>';}

	
echo '<div class="timeline-body"><div class="timeline-body-arrow"></div>
<div class="timeline-body-head"><div class="timeline-body-head-caption"><span>'.htmlspecialchars($timestamps).' </span></div><div class="timeline-body-head-actions"></div></div>
<div class="timeline-body-content"><span class="font-grey-cascade">'.htmlspecialchars($dhlstatus).' '.htmlspecialchars($description).' - '.htmlspecialchars($currentloc).' </span></div></div></div>
';
}

echo '

</div><div class="track_right" style="float: left; width: 30%;"><ul class="catship_tracking_info_parent"><div class="catship_tracking_info_title"></div><div class="catship_tracking_info"><div class="catship_tracking_carrier_img" style="cursor: pointer;" 
	>
	
	<svg xmlns="http://www.w3.org/2000/svg" width="100" viewBox="0 0 512 512"><path fill="#FDCA2E" d="M512 472c0 22.1-17.9 40-40 40H40c-22.1 0-40-17.9-40-40V40C0 17.9 17.9 0 40 0h432c22.1 0 40 17.9 40 40v432z"/><path fill="#D0131D" d="M198.9 268.2c4.5-9.1 8.3-17 10-20.4 8.1-16.3-.3-39.1-28.3-39.1h-92L72.7 241h85.8c6.7 0 9.1 1.4 7.1 5.6-2 4.1-6.2 12.7-7.4 15.1-1.4 2.9-2.1 6.5 2.4 6.5h38.3z"/><path fill="#D0131D" d="M141.6 271.3c-4.5 0-4.9-3-3.8-5.3.9-1.9 6.9-14.1 8.1-16.4 1.4-2.9 1-5.9-5.9-5.9h-38.9l-29.3 59.6H149c21.1 0 36.4-7.6 46.9-28.9.5-1 1-2.1 1.5-3.1h-55.8zM206.3 271.3l-15.7 32h47.9l15.8-32h-48zM273.7 271.3l-15.7 32h47.9l15.8-32h-48zM323.2 268.2l29.2-59.5h-47.9L288.7 241h-19.6l15.9-32.3h-48l-29.1 59.5h115.3zM364.6 208.7h48l-29.3 59.5h-47.9l29.2-59.5zM333.9 271.3h107.3l-15.7 32h-79.1c-16.5 0-21.8-12.6-15.9-24.8.5-1.4 3.4-7.2 3.4-7.2zM80 271.3l-4.6 9.4H0v-9.4h80zM74.5 282.5l-4.7 9.5H0v-9.5h74.5zM68.9 293.9l-4.7 9.4H0v-9.4h68.9zM512 280.7h-68.8l4.6-9.4H512zM512 292h-74.4l4.6-9.5H512zM512 303.3h-80l4.7-9.4H512z"/></svg></div>
	<br>
	<div class="catship_tracking_carrier_info"><div class="catship_tracking_carrier_top"><span>DHL E-Commerce</span></div>
	<div class="catship_tracking_carrier_bottom"><span><a href="tel:+1-317-554-5191">+1 317-554-5191</a></span></div></div></div><br>
	<div class="catship_tracking_info_title"><span>Tracking Number</span></div>
	<div class="catship_tracking_info"><span><a href="https://www.dhl.com/us-en/home/tracking.html?tracking-id='.htmlspecialchars($tracking).'">'.htmlspecialchars($tracking).'</a></span></div><br>
	<div class="catship_tracking_info_title"><span>Delivery Location</span></div>
	<div class="catship_tracking_info"><span>'.htmlspecialchars($dhldestaddress).'</span></div><br>
	<div class="catship_tracking_info_title"><span>Estimated Delivery Date</span></div>
	<div class="catship_tracking_info"><span>'.htmlspecialchars($shipexpdeliv).' '.htmlspecialchars($shippreddeliv).'</span></div><br>
	<div class="catship_tracking_info_title"><span>Shipping Class</span></div>
	<div class="catship_tracking_info"><span>'.htmlspecialchars($dhlclass).'</span></div>';




	die;
}
else{

die('<h1>No Order Found</h1>');
}

?>
