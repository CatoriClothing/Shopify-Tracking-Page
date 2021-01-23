<?php

// Credit to Tomoli75 for the session based rate limiting https://gist.github.com/Tomoli75/394a47e391b966f5061dfa37b8633e44
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

// Forward correct cloudflare IP's
if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
    $rateLimiter = new RateLimiter($_SERVER["HTTP_CF_CONNECTING_IP"]);
  }
  else{
    $rateLimiter = new RateLimiter($_SERVER["REMOTE_ADDR"]);
  }

$limit = 3;				//	number of connections to limit user to per $minutes
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
if(isset($_POST['order']) && isset($_POST['email'])) {
$orderid = $_POST['order'];
$email = $_POST['email'];
$orderid = substr($orderid, 0, 15); // Change to 6
$email = substr($email, 0, 254);
$orderid = preg_replace( '/[^0-9]/', '', $orderid );

if(!filter_var($orderid, FILTER_VALIDATE_INT)){
echo 'No Order Found';
die;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "No Order Found";
    die;
  }

// SHOPIFY TRACKING INFORMATION
$shipurlpost =  curl_init("https://.myshopify.com/admin/api/2020-04/orders.json?name=".$orderid."&status=any");
curl_setopt($shipurlpost, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($shipurlpost, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($shipurlpost, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($shipurlpost, CURLOPT_TIMEOUT, 30);
curl_setopt($shipurlpost, CURLOPT_POST, false);
curl_setopt($shipurlpost, CURLOPT_CUSTOMREQUEST, "GET");
curl_setopt($shipurlpost, CURLOPT_RETURNTRANSFER, true);
curl_setopt($shipurlpost, CURLOPT_HTTPHEADER, array("authorization: Basic ",
"cache-control: no-cache", "Content-Type: application/json"));
$getShopifyOrder = curl_exec($shipurlpost);
$getShopifyOrders = json_decode($getShopifyOrder,true);
$tracking = $getShopifyOrders['orders'][0]['fulfillments'][0]['tracking_number'];
$orderemail = $getShopifyOrders['orders'][0]['email'];

if ($orderemail !== $email) {	
	echo 'No Order Found';
	die;
}

if(empty($getShopifyOrders['orders'])){	  
echo 'No Order Found';
die;
}


// USPS INFORMATION
	$shippost = '';
echo '<br><br>';
	if(empty($getShopifyOrders['orders'][0]['fulfillments'][0]['tracking_number'])){
		echo '<div class="catship_tracking_result"><div class="progress-bar-mobile-content"><b><div style="clear: both;"></div></div>
		<div class="track_left""><h1>Status : IN PRODUCTION</h1></b><p>Your order has now been sent to production. Have any questions? Contact us via email</p>';
		die;
	}



if(substr($tracking, 0, 2 ) === "GM"){
	echo '
	<div class="track_left""><h1>Status: Unable to Track</h1>

<ul class="catship_tracking_result_parent catship_timeline"><div class="timeline-item"><div class="timeline-badge"><img class="timeline-badge-userpic" src=""></div><div class="timeline-body"><div class="timeline-body-arrow"></div><div class="timeline-body-head">
<div class="timeline-body-head-caption"><span>Last Event: Unknown </span></div><div class="timeline-body-head-actions"></div></div><div class="timeline-body-content"><span class="font-grey-cascade"><h2>Unable to track this order. Please visit <a href="https://webtrack.dhlglobalmail.com/?trackingnumber='.$tracking.'"> https://webtrack.dhlglobalmail.com/?trackingnumber='.$tracking.'</a></h2</span></div></div></div>

</div>

<div class="track_right" style="float: left; width: 30%;"><ul class="catship_tracking_info_parent"><div class="catship_tracking_info_title"><span>Carrier</span></div><div class="catship_tracking_info"><div class="catship_tracking_carrier_img" style="cursor: pointer;" >
	<svg xmlns="http://www.w3.org/2000/svg" width="100" viewBox="0 0 512 512"><path fill="#FC0" d="M512 472c0 22.1-17.9 40-40 40H40c-22.1 0-40-17.9-40-40V40C0 17.9 17.9 0 40 0h432c22.1 0 40 17.9 40 40v432z"/><path fill="#FFF" d="M0 275.2h512V347H0z"/><path fill="#FFC200" d="M0 293.9h512v2H0zM0 310.2h512v2H0zM0 328.6h512v2H0z"/><path fill="none" d="M0 323.7h512v2H0z"/><text transform="translate(126.453 328.673)" fill="#D40511" font-family="Arial-ItalicMT" font-size="37.5">eCOMMERCE</text><g fill="#D40511"><path d="M198.9 224.5c4.5-9.1 8.3-17 10-20.4 8.1-16.3-.3-39.1-28.3-39.1h-92l-15.9 32.3h85.8c6.7 0 9.1 1.4 7.1 5.6-2 4.1-6.2 12.7-7.4 15.1-1.4 2.9-2.1 6.5 2.4 6.5h38.3z"/><path d="M141.6 227.5c-4.5 0-4.9-3-3.8-5.3.9-1.9 6.9-14.1 8.1-16.4 1.4-2.9 1-5.9-5.9-5.9h-38.9l-29.3 59.6H149c21.1 0 36.4-7.6 46.9-28.9.5-1 1-2.1 1.5-3.1h-55.8zM206.3 227.5l-15.7 32.1h47.9l15.8-32.1h-48zM273.7 227.5L258 259.6h47.9l15.8-32.1h-48zM323.2 224.5l29.2-59.5h-47.9l-15.8 32.3h-19.6L285 165h-48l-29.1 59.5h115.3zM364.6 165h48l-29.3 59.5h-47.9l29.2-59.5zM333.9 227.5h107.3l-15.7 32h-79.1c-16.5 0-21.8-12.6-15.9-24.8.5-1.3 3.4-7.2 3.4-7.2zM80 227.5l-4.6 9.5H0v-9.5h80zM74.5 238.8l-4.7 9.5H0v-9.5h74.5zM68.9 250.1l-4.7 9.5H0v-9.5h68.9zM512 237h-68.8l4.6-9.5H512zM512 248.3h-74.4l4.6-9.5H512zM512 259.6h-80l4.7-9.5H512z"/></g></svg></div><div class="catship_tracking_carrier_info"><div class="catship_tracking_carrier_top"><span>DHL E-Commerce</span></div>
	<div class="catship_tracking_carrier_bottom"><span><a href="tel:+1-317-554-5191">+1 317-554-5191</a></span></div></div></div><br>
	<div class="catship_tracking_info_title"><span>Tracking Number</span></div>
	
	<div class="catship_tracking_info"><span><a href="https://webtrack.dhlglobalmail.com/?trackingnumber='.$tracking.'">'.$tracking.'</a></span></div>';
	
	
	die;
}

if(substr($tracking, 0, 2 ) === "UM"){
	echo '
	<div class="track_left""><h1>Status: Unable to Track</h1>

<ul class="catship_tracking_result_parent catship_timeline"><div class="timeline-item"><div class="timeline-badge"><img class="timeline-badge-userpic" src=""></div><div class="timeline-body"><div class="timeline-body-arrow"></div><div class="timeline-body-head">
<div class="timeline-body-head-caption"><span>Last Event: Unknown </span></div><div class="timeline-body-head-actions"></div></div><div class="timeline-body-content"><span class="font-grey-cascade"><h2>Unable to track this order. Please visit <a href="https://a1.asendiausa.com/tracking/?trackingnumber='.$tracking.'"> https://a1.asendiausa.com/tracking/?trackingnumber='.$tracking.'</a></h2</span></div></div></div>

</div>

<div class="track_right" style="float: left; width: 30%;"><ul class="catship_tracking_info_parent"><div class="catship_tracking_info_title"><span>Carrier</span></div><div class="catship_tracking_info"><div class="catship_tracking_carrier_img" style="cursor: pointer;" >
	<svg xmlns="http://www.w3.org/2000/svg" width="100" viewBox="0 0 512 512"><path fill="#FFD300" d="M512 472c0 22.1-17.9 40-40 40H40c-22.1 0-40-17.9-40-40V40C0 17.9 17.9 0 40 0h432c22.1 0 40 17.9 40 40v432z"/><path fill-rule="evenodd" clip-rule="evenodd" fill="#FFF" d="M396.2 304.3l5.5-25H31.6l-5.5 25h370.1z"/><path fill-rule="evenodd" clip-rule="evenodd" fill="#EC1C24" d="M421.4 207.7h40.8l-6.5 30.3h30.2l-7.8 37.8h-30.3l-6 28.5h-33.3l2-9.9h23.2l5.8-28.2H470l3.8-18.6h-30.2l6.3-30.3h-20.5l-6.3 30.3h-10.3l8.6-39.9z"/><path fill-rule="evenodd" clip-rule="evenodd" fill="#231F20" d="M145.3 239h-11.1v.2l-16.1 35.1-.2-34.8v-.5H104.2l-.2.2-15.1 35.1-1.3-34.8v-.5H76.8l.2.5 3 47.9v.5h12.6v-.3l16.2-36.5.5 36.3v.5H122.1v-.3l23-47.9.2-.7zM158.9 239H147.9l-.3.5-10.1 47.9v.5h11.1v-.5l10.1-47.9.2-.5zM212.6 238.2c-8.6 0-18.4 3.8-18.4 14.9 0 7.3 5.3 10.1 9.6 12.6 3.8 2 6.8 3.8 6.8 7.6 0 4.8-4.3 6.8-8.6 6.8s-8.1-1.5-11.6-3.3l-.5-.3-.3.8-2.3 8.1-.3.5h.5c4.8 2 9.6 2.8 13.9 2.8 6.8 0 12.4-1.8 15.9-5.3 2.8-2.8 4.3-6.6 4.3-11.1 0-7.8-5.5-11.1-10.1-13.9-3.5-2-6.3-3.8-6.3-6.8 0-1 .5-2 1.3-2.8 1.5-1.5 4.3-2 6.3-2 3 0 6.8.8 9.6 2.3l.8.3v-.5l2.3-7.8.3-.5-.5-.3c-3.6-1.3-7.9-2.1-12.7-2.1zM191.2 240.5l-.5-.3c-3.5-1.3-7.6-2-12.6-2-8.3 0-18.2 3.8-18.2 14.9 0 7.3 5 10.1 9.6 12.6 3.5 2 6.6 3.8 6.6 7.6 0 4.8-4.3 6.8-8.6 6.8-4 0-8.1-1.5-11.3-3.3l-.5-.3-.3.8-2.5 8.1v.5h.3c5 2 9.6 2.8 13.9 2.8 7.1 0 12.6-1.8 16.1-5.3 2.8-2.8 4-6.6 4-11.1 0-7.8-5.5-11.1-10.1-13.9-3.3-2-6.3-3.8-6.3-6.8 0-1 .5-2 1.3-2.8 1.5-1.5 4.3-2 6.3-2 3.3 0 6.8.8 9.8 2.3l.5.3.3-.5 2.3-7.8v-.6h-.1zM62.1 238.2c-8.3 0-18.4 3.8-18.4 14.9 0 7.3 5.3 10.1 9.8 12.6 3.5 2 6.6 3.8 6.6 7.6 0 4.8-4.3 6.8-8.6 6.8s-8.1-1.5-11.6-3.3l-.5-.3v.8l-2.5 8.1-.3.5h.5c4.8 2 9.6 2.8 13.9 2.8 7.1 0 12.6-1.8 15.9-5.3 2.8-2.8 4.3-6.6 4.3-11.1 0-7.8-5.5-11.1-10.1-13.9-3.3-2-6.3-3.8-6.3-6.8 0-1 .5-2 1.3-2.8 1.5-1.5 4.3-2 6.3-2 3.3 0 6.8.8 9.8 2.3l.5.3v-.5L75 241l.3-.5-.5-.3c-3.6-1.2-7.9-2-12.7-2zM256.5 239H247.8v.5l-10.1 47.9v.3l-.3.3H248.1l.3-.5s3.3-14.6 3.3-15.6h5.3c6.3 0 12.4-2.3 16.1-6.1 3-3 4.8-7.3 4.8-12.1 0-3.8-1.3-7.1-3.5-9.3-4.3-4.4-12.1-5.4-17.9-5.4zm.3 8.6h2.8c2 0 4 .8 5.5 2 1.3 1.3 2 3 2 5 0 5.5-5.5 8.6-10.6 8.6h-2.8l3.1-15.6zM321.3 243.8c-3.5-3.5-8.6-5.5-15.1-5.5-8.3 0-14.4 2.8-19.9 9.1-5 5.8-7.8 13.4-7.8 21.2 0 6 2 11.3 5.8 15.1 3.5 3.5 8.1 5.3 13.6 5.3 8.8 0 15.9-2.8 20.7-8.3 5.3-5.8 8.3-13.4 8.3-21.4-.1-6.7-2.1-12-5.6-15.5zm-22.2 36.5c-2.5 0-4.8-.8-6.5-2.5-2-2-3-5-3-8.8 0-6.8 2.8-13.9 6.8-17.9 2.8-2.8 6-4.3 9.6-4.3 2.8 0 5 1 6.8 2.5 2 2 3 5 3 8.8-.1 9.1-5.8 22.2-16.7 22.2zM363.1 240.5c-3.5-1.5-7.8-2.3-12.6-2.3-8.6 0-18.4 4-18.4 15.1 0 7.3 5 10.1 9.6 12.6 3.5 2 6.8 3.8 6.8 7.3 0 4.8-4.3 7.1-8.6 7.1s-8.3-1.8-11.6-3.3l-.5-.3v.3l-.2.3-2.5 8.3v.6h.2c5 1.8 9.6 2.8 13.9 2.8 7.1 0 12.6-1.8 16.1-5.3 2.8-2.8 4-6.6 4-11.3 0-7.8-5.5-11.1-10.1-13.6-3.3-2-6-3.8-6-6.8 0-1 .3-2 1-3 2-1.8 5.5-2 6.6-2 3 0 6.6 1 9.6 2.5l.5.3v-.3l.2-.3 2.3-8.1v-.6h-.3zM404.2 239h-37.9l-.2.5-1.5 7.3v.5l-.3.3H378l-8.6 39.8v.3l-.3.3H380.3v-.5s8.3-38.1 8.8-39.8H402.4v-.3l.3-.3 1.5-7.6v-.5z"/></svg></div><div class="catship_tracking_carrier_info"><div class="catship_tracking_carrier_top"><span>Asendia / Swiss Post</span></div>
	<div class="catship_tracking_carrier_bottom"><span><a href="tel:+41-848-888-888">+41 848-888-888</a></span></div></div></div><br>
	<div class="catship_tracking_info_title"><span>Tracking Number</span></div>
	
	<div class="catship_tracking_info"><span><a href="https://a1.asendiausa.com/tracking/?trackingnumber='.$tracking.'">'.$tracking.'</a></span></div>';
	
	
	die;
}



    $shipurlpost= curl_init("https://secure.shippingapis.com/ShippingAPI.dll?API=TrackV2&XML=%3CTrackFieldRequest%20USERID=%27 //API KEY \\  %27%3E%3CRevision%3E1%3C/Revision%3E%3CClientIp%3E //\\ %3C/ClientIp%3E%3CSourceId%3EJohn%20Doe%3C/SourceId%3E%3CTrackID%20ID=%22$tracking%22%3E%3C/TrackID%3E%3C/TrackFieldRequest%3E");
    curl_setopt($shipurlpost, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($shipurlpost, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($shipurlpost, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($shipurlpost, CURLOPT_TIMEOUT, 30);
    curl_setopt($shipurlpost, CURLOPT_POST, TRUE);
    curl_setopt($shipurlpost, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($shipurlpost, CURLOPT_HTTPHEADER, array("cache-control: no-cache", "Content-Type: application/xml", "Application-Type: application/xml"));
    $getshippinginfo = curl_exec($shipurlpost);
    $xml = simplexml_load_string($getshippinginfo, "SimpleXMLElement", LIBXML_NOCDATA);
    $json = json_encode($xml);


$array = json_decode($json,true);

if(empty($array['TrackInfo']['DestinationCity'])){
	echo '</b></div></div><div style="clear: both;"></div></div>
<div class="track_left""><h1>Status: In Transit to USPS</h1>

<ul class="catship_tracking_result_parent catship_timeline"><div class="timeline-item"><div class="timeline-badge"><img class="timeline-badge-userpic" src=""></div><div class="timeline-body"><div class="timeline-body-arrow"></div><div class="timeline-body-head">
<div class="timeline-body-head-caption"><span>Last Event: Unknown </span></div><div class="timeline-body-head-actions"></div></div><div class="timeline-body-content"><span class="font-grey-cascade">Get Excited! Your package has been shipped and is in transit to USPS. A USPS status update is not yet available for your package. Check back in a few days.</span></div></div></div>

</div>

<div class="track_right" style="float: left; width: 30%;"><ul class="catship_tracking_info_parent"><div class="catship_tracking_info_title"><span>Carrier</span></div><div class="catship_tracking_info"><div class="catship_tracking_carrier_img" style="cursor: pointer;" 
	><svg xmlns="http://www.w3.org/2000/svg" width="100" viewBox="0 0 192.756 192.756"><g fill-rule="evenodd" clip-rule="evenodd"><path fill="#fff" d="M0 0h192.756v192.756H0V0z"/><path d="M48.299 133.01l-3.289 15.601h3.612l3.287-15.601h-3.61zM53.444 133.01l-.58 2.648h4.83l-2.734 12.953h3.611l2.734-12.953h4.739l.709-2.648H53.444zM80.184 152.809l-3.221 15.582h10.599l.51-2.577h-6.918l2.743-13.005h-3.713zM68.146 133.01l-3.288 15.601h11.466l.58-2.74h-7.848l.842-3.988h6.88l.538-2.42h-6.909l.808-3.85h7.865l.644-2.603H68.146zM28.955 148.611h3.352l2.193-10.959 3.946 10.959h4.34l3.288-15.601h-3.243l-2.412 11.152-3.585-11.152h-4.591l-3.288 15.601zM119.908 148.611h3.547l6.805-11.927 1.383 9.23h-4.747l-1.65 2.697h10.346l-2.442-15.601h-4.191l-9.051 15.601zM19.669 133.01h-3.611l-2.146 10.166C12.099 151 26.197 150.42 27.6 144.357l2.386-11.348H26.44l-2.128 9.928c-.601 3.869-8.047 5.473-6.576-.645l1.933-9.282zM81.327 133.01h6.448c2.866 0 4.995.531 6.112 2.059 2.169 2.967.309 8.938-2.41 11.217-2.029 1.703-4.702 2.326-7.056 2.326h-6.318l3.224-15.602z"/><path d="M84.398 135.658l-2.204 10.27h2.515c1.316 0 2.999-.428 4.331-1.652 1.875-1.725 2.629-4.965 1.971-6.756-.57-1.553-2.114-1.861-3.324-1.861h-3.289v-.001z" fill="#fff"/><path d="M19.078 152.795h-7.35l-3.224 15.604h3.546l2.75-13.051s2.581-.254 3.834.318c.754.344 1.077 1.83.304 2.881-1.197 1.627-1.893 1.592-4.668 1.418l.193 2.133s.738.373 1.495.377c8.169.058 9.162-9.68 3.12-9.68zM133.605 152.793l2.426 15.602h4.039l8.946-15.602h-3.6l-6.523 11.963-1.793-11.963h-3.495z"/><path d="M118.316 168.391h3.545l2.785-12.988h2.713c3.641 0 2.25 4.479-2.08 4.479h-.838l2.643 8.51h4.012l-2.381-6.715c5.654-1.189 6.57-8.887-.133-8.887h-7.043l-3.223 15.601zM12.228 150.203l-.129 1.014h168.747l.146-1.014H12.228zM150.996 133.01l-3.289 15.601h11.467l.578-2.74h-7.848l.844-3.988h6.879l.537-2.42h-6.908l.808-3.85h7.864l.644-2.603h-11.576zM136.328 133.01l-.58 2.648h4.83l-2.734 12.953h3.611l2.734-12.953h4.741l.707-2.648h-13.309zM112.727 133.01l-.581 2.648h4.829l-2.733 12.953h3.61l2.734-12.953h4.74l.709-2.648h-13.308zM33.129 152.514c4.553 0 7.822 3.213 6.139 9.438-1.457 5.387-6.855 6.891-9.62 6.834-3.072-.064-8.763-2.318-6.52-9.221 2.117-6.51 7.476-7.051 10.001-7.051z"/><path d="M32.994 155.174c-2.341-.178-4.81.682-6.28 4.648-1.35 3.643.387 6.113 2.935 6.26 1.577.092 4.895-.402 6.137-4.84 1.241-4.439-1.102-5.939-2.792-6.068z" fill="#fff"/><path d="M105.754 155.469l.58-2.68h-4.643c-6.546 0-8.131 4.059-5.163 7.027l3.954 3.416c.83.912.092 2.559-1.295 2.477h-5.776l-.58 2.682h6.153c6.338 0 6.559-4.867 4.635-6.705l-4.184-3.998c-.68-.543-.24-2.219 1.236-2.219h5.083zM51.453 155.469l.58-2.68h-4.642c-6.546 0-8.131 4.059-5.163 7.027l3.953 3.416c.831.912.093 2.559-1.294 2.477h-5.776l-.581 2.682h6.152c6.338 0 6.56-4.867 4.635-6.705l-4.184-3.998c-.679-.543-.238-2.219 1.237-2.219h5.083zM110.957 135.709l.58-2.682h-4.643c-6.547 0-8.131 4.059-5.162 7.027l3.951 3.416c.832.914.094 2.559-1.293 2.477h-5.775l-.58 2.682h6.152c6.338 0 6.559-4.867 4.635-6.705l-4.184-3.996c-.68-.545-.238-2.219 1.236-2.219h5.083zM173.35 135.709l.58-2.682h-4.643c-6.545 0-8.131 4.059-5.162 7.027l3.953 3.416c.83.914.092 2.559-1.295 2.477h-5.775l-.58 2.682h6.152c6.338 0 6.561-4.867 4.635-6.705l-4.184-3.996c-.68-.545-.238-2.219 1.236-2.219h5.083zM53.162 152.791l-.58 2.648h4.83l-2.734 12.954h3.611l2.733-12.954h4.74l.71-2.648h-13.31zM60.364 168.387h3.546l6.804-11.926 1.385 9.228h-4.746l-1.652 2.698h10.346l-2.441-15.602h-4.192l-9.05 15.602zM108.494 152.789l-3.287 15.602h11.465l.58-2.741h-7.848l.842-3.988h6.879l.539-2.42h-6.91l.81-3.851h7.864l.644-2.602h-11.578zM168.822 152.789l-3.289 15.602H177l.58-2.741h-7.85l.844-3.988h6.879l.537-2.42h-6.908l.809-3.851h7.863l.644-2.602h-11.576zM179.039 165.83l-.066.307h.867l-.479 2.256h.346l.481-2.256h.867l.064-.307h-2.08zM183.371 168.393h.336l.545-2.563h-.492l-1.196 2.176-.271-2.176h-.496l-.545 2.563h.336l.453-2.176.268 2.176h.341l1.192-2.176v.002l-.471 2.174zM167.145 153.055c-5.443-1.586-12.381-.068-14.189 7.342-.531 2.18-.557 8.188 7.146 8.316.98 0 3.104.061 4.035-.402l.752-2.797c-1.025.467-2.58.57-3.994.57-1.752 0-3.041-.643-3.766-1.676-.727-1.035-1.281-4.051 1.447-7.439 1.477-1.834 4.393-2.291 8.143-1.287l.426-2.627zM149.879 152.789l-3.289 15.602h3.611l3.287-15.602h-3.609zM117.109 46.547c-11.365-1.48-72.746-.947-81.767-.86l-16.284 75.56c24.929-12.545 47.12-23.373 71.807-35.152 31.386-14.974 52.978-18.812 57.457-19.03 3.014-.146 1.305-1.459-.127-1.775-17.531-3.866-60.051 15.849-60.051 15.849l-9.29-28.186 60.533.057c-3.34-4.946-10.004-4.864-22.278-6.463z"/><path d="M44.553 23.971a206015 206015 0 0 1 83.037 18.242c13.309 2.934 15.01 7.494 15.01 7.494s14.564-1.76 17.236 3.309c3.426 6.494-5.334 18.833-5.334 18.833L28.603 121.246h130.743l21.441-97.275H44.553z"/><path d="M139.932 53.105s-.625 3.505-13.564 4.59c-1.186.099-1.469.857.096.907 1.562.05 23.189-.695 25.598-.459 2.41.236 2.088 1.691 1.779 3.1-.312 1.408-1.932 5.629-2.348 6.62-.414.992.377.969 1.014.322.635-.648 4.521-8.454 4.633-10.3.111-1.846-.549-3.763-3.553-4.425-3.005-.665-13.655-.355-13.655-.355z"/></g></svg></div><div class="catship_tracking_carrier_info"><div class="catship_tracking_carrier_top"><span>USPS</span></div>
	<div class="catship_tracking_carrier_bottom"><span><a href="tel:1-800-275-8777">1-800-275-8777</a></span></div></div></div><br>
	<div class="catship_tracking_info_title"><span>Tracking Number</span></div>
	
	<div class="catship_tracking_info"><span><a href="https://tools.usps.com/go/TrackConfirmAction!input.action?tRef=qt&tLc=0&tLabels='.$tracking.'">'.$tracking.'</a></span></div>';

	die;
}
$shipexpdeliv = '';
$shippreddeliv = '';
// Current Status
$shipclass = $array['TrackInfo']['Class'];
$shipdestcity = $array['TrackInfo']['DestinationCity'];
$shipdeststate = $array['TrackInfo']['DestinationState'];
$shipdestzip = $array['TrackInfo']['DestinationZip'];
$shipstatus = $array['TrackInfo']['Status'];
$shipcat = $array['TrackInfo']['StatusCategory'];
$shipsummary = $array['TrackInfo']['StatusSummary'];

$shipexpdeliv = $array['TrackInfo']['ExpectedDeliveryDate'];


$shippreddeliv = $array['TrackInfo']['PredictedDeliveryDate'];

// Last Update
$lasttime = $array['TrackInfo']['TrackSummary']['EventTime'];
$lastdate = $array['TrackInfo']['TrackSummary']['EventDate'];
$lastevent = $array['TrackInfo']['TrackSummary']['Event'];
$lastcity = $array['TrackInfo']['TrackSummary']['EventCity'];
$laststate = $array['TrackInfo']['TrackSummary']['EventState'];

//// Previous STatus Update
$prevtime = $array['TrackInfo']['TrackDetail'][2]['EventTime'];
$prevdate = $array['TrackInfo']['TrackDetail'][2]['EventDate'];
$prevevent = $array['TrackInfo']['TrackDetail'][2]['Event'];
$prevcity = $array['TrackInfo']['TrackDetail'][2]['EventCity'];
$prevstate = $array['TrackInfo']['TrackDetail'][2]['EventState'];



echo '<br><div class="catship_tracking_result"><div class="progress-bar-mobile-content">';

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



echo '</div></div><div style="clear: both;"></div></div>
<div class="track_left""><h1>Status : '.$shipcat.'</h1>

<ul class="catship_tracking_result_parent catship_timeline"><div class="timeline-item"><div class="timeline-badge"><img class="timeline-badge-userpic" src=""></div><div class="timeline-body"><div class="timeline-body-arrow"></div><div class="timeline-body-head"><div class="timeline-body-head-caption"><span>Latest Event: '.$lastdate.' </span></div><div class="timeline-body-head-actions"></div></div><div class="timeline-body-content"><span class="font-grey-cascade">'.$shipsummary.' </span></div></div></div>
<ul class="catship_tracking_result_parent catship_timeline"><div class="timeline-item"><div class="timeline-badge"><img class="timeline-badge-userpic" src=""></div><div class="timeline-body"><div class="timeline-body-arrow"></div><div class="timeline-body-head"><div class="timeline-body-head-caption"><span>Previous Event: '.$prevdate.' </span></div><div class="timeline-body-head-actions"></div></div><div class="timeline-body-content"><span class="font-grey-cascade">'.$prevevent.'</span></div></div></div>

</div>

<div class="track_right" style="float: left; width: 30%;"><ul class="catship_tracking_info_parent"><div class="catship_tracking_info_title"></div><div class="catship_tracking_info"><div class="catship_tracking_carrier_img" style="cursor: pointer;" 
	><svg xmlns="http://www.w3.org/2000/svg" width="100" viewBox="0 0 192.756 192.756"><g fill-rule="evenodd" clip-rule="evenodd"><path fill="#fff" d="M0 0h192.756v192.756H0V0z"/><path d="M48.299 133.01l-3.289 15.601h3.612l3.287-15.601h-3.61zM53.444 133.01l-.58 2.648h4.83l-2.734 12.953h3.611l2.734-12.953h4.739l.709-2.648H53.444zM80.184 152.809l-3.221 15.582h10.599l.51-2.577h-6.918l2.743-13.005h-3.713zM68.146 133.01l-3.288 15.601h11.466l.58-2.74h-7.848l.842-3.988h6.88l.538-2.42h-6.909l.808-3.85h7.865l.644-2.603H68.146zM28.955 148.611h3.352l2.193-10.959 3.946 10.959h4.34l3.288-15.601h-3.243l-2.412 11.152-3.585-11.152h-4.591l-3.288 15.601zM119.908 148.611h3.547l6.805-11.927 1.383 9.23h-4.747l-1.65 2.697h10.346l-2.442-15.601h-4.191l-9.051 15.601zM19.669 133.01h-3.611l-2.146 10.166C12.099 151 26.197 150.42 27.6 144.357l2.386-11.348H26.44l-2.128 9.928c-.601 3.869-8.047 5.473-6.576-.645l1.933-9.282zM81.327 133.01h6.448c2.866 0 4.995.531 6.112 2.059 2.169 2.967.309 8.938-2.41 11.217-2.029 1.703-4.702 2.326-7.056 2.326h-6.318l3.224-15.602z"/><path d="M84.398 135.658l-2.204 10.27h2.515c1.316 0 2.999-.428 4.331-1.652 1.875-1.725 2.629-4.965 1.971-6.756-.57-1.553-2.114-1.861-3.324-1.861h-3.289v-.001z" fill="#fff"/><path d="M19.078 152.795h-7.35l-3.224 15.604h3.546l2.75-13.051s2.581-.254 3.834.318c.754.344 1.077 1.83.304 2.881-1.197 1.627-1.893 1.592-4.668 1.418l.193 2.133s.738.373 1.495.377c8.169.058 9.162-9.68 3.12-9.68zM133.605 152.793l2.426 15.602h4.039l8.946-15.602h-3.6l-6.523 11.963-1.793-11.963h-3.495z"/><path d="M118.316 168.391h3.545l2.785-12.988h2.713c3.641 0 2.25 4.479-2.08 4.479h-.838l2.643 8.51h4.012l-2.381-6.715c5.654-1.189 6.57-8.887-.133-8.887h-7.043l-3.223 15.601zM12.228 150.203l-.129 1.014h168.747l.146-1.014H12.228zM150.996 133.01l-3.289 15.601h11.467l.578-2.74h-7.848l.844-3.988h6.879l.537-2.42h-6.908l.808-3.85h7.864l.644-2.603h-11.576zM136.328 133.01l-.58 2.648h4.83l-2.734 12.953h3.611l2.734-12.953h4.741l.707-2.648h-13.309zM112.727 133.01l-.581 2.648h4.829l-2.733 12.953h3.61l2.734-12.953h4.74l.709-2.648h-13.308zM33.129 152.514c4.553 0 7.822 3.213 6.139 9.438-1.457 5.387-6.855 6.891-9.62 6.834-3.072-.064-8.763-2.318-6.52-9.221 2.117-6.51 7.476-7.051 10.001-7.051z"/><path d="M32.994 155.174c-2.341-.178-4.81.682-6.28 4.648-1.35 3.643.387 6.113 2.935 6.26 1.577.092 4.895-.402 6.137-4.84 1.241-4.439-1.102-5.939-2.792-6.068z" fill="#fff"/><path d="M105.754 155.469l.58-2.68h-4.643c-6.546 0-8.131 4.059-5.163 7.027l3.954 3.416c.83.912.092 2.559-1.295 2.477h-5.776l-.58 2.682h6.153c6.338 0 6.559-4.867 4.635-6.705l-4.184-3.998c-.68-.543-.24-2.219 1.236-2.219h5.083zM51.453 155.469l.58-2.68h-4.642c-6.546 0-8.131 4.059-5.163 7.027l3.953 3.416c.831.912.093 2.559-1.294 2.477h-5.776l-.581 2.682h6.152c6.338 0 6.56-4.867 4.635-6.705l-4.184-3.998c-.679-.543-.238-2.219 1.237-2.219h5.083zM110.957 135.709l.58-2.682h-4.643c-6.547 0-8.131 4.059-5.162 7.027l3.951 3.416c.832.914.094 2.559-1.293 2.477h-5.775l-.58 2.682h6.152c6.338 0 6.559-4.867 4.635-6.705l-4.184-3.996c-.68-.545-.238-2.219 1.236-2.219h5.083zM173.35 135.709l.58-2.682h-4.643c-6.545 0-8.131 4.059-5.162 7.027l3.953 3.416c.83.914.092 2.559-1.295 2.477h-5.775l-.58 2.682h6.152c6.338 0 6.561-4.867 4.635-6.705l-4.184-3.996c-.68-.545-.238-2.219 1.236-2.219h5.083zM53.162 152.791l-.58 2.648h4.83l-2.734 12.954h3.611l2.733-12.954h4.74l.71-2.648h-13.31zM60.364 168.387h3.546l6.804-11.926 1.385 9.228h-4.746l-1.652 2.698h10.346l-2.441-15.602h-4.192l-9.05 15.602zM108.494 152.789l-3.287 15.602h11.465l.58-2.741h-7.848l.842-3.988h6.879l.539-2.42h-6.91l.81-3.851h7.864l.644-2.602h-11.578zM168.822 152.789l-3.289 15.602H177l.58-2.741h-7.85l.844-3.988h6.879l.537-2.42h-6.908l.809-3.851h7.863l.644-2.602h-11.576zM179.039 165.83l-.066.307h.867l-.479 2.256h.346l.481-2.256h.867l.064-.307h-2.08zM183.371 168.393h.336l.545-2.563h-.492l-1.196 2.176-.271-2.176h-.496l-.545 2.563h.336l.453-2.176.268 2.176h.341l1.192-2.176v.002l-.471 2.174zM167.145 153.055c-5.443-1.586-12.381-.068-14.189 7.342-.531 2.18-.557 8.188 7.146 8.316.98 0 3.104.061 4.035-.402l.752-2.797c-1.025.467-2.58.57-3.994.57-1.752 0-3.041-.643-3.766-1.676-.727-1.035-1.281-4.051 1.447-7.439 1.477-1.834 4.393-2.291 8.143-1.287l.426-2.627zM149.879 152.789l-3.289 15.602h3.611l3.287-15.602h-3.609zM117.109 46.547c-11.365-1.48-72.746-.947-81.767-.86l-16.284 75.56c24.929-12.545 47.12-23.373 71.807-35.152 31.386-14.974 52.978-18.812 57.457-19.03 3.014-.146 1.305-1.459-.127-1.775-17.531-3.866-60.051 15.849-60.051 15.849l-9.29-28.186 60.533.057c-3.34-4.946-10.004-4.864-22.278-6.463z"/><path d="M44.553 23.971a206015 206015 0 0 1 83.037 18.242c13.309 2.934 15.01 7.494 15.01 7.494s14.564-1.76 17.236 3.309c3.426 6.494-5.334 18.833-5.334 18.833L28.603 121.246h130.743l21.441-97.275H44.553z"/><path d="M139.932 53.105s-.625 3.505-13.564 4.59c-1.186.099-1.469.857.096.907 1.562.05 23.189-.695 25.598-.459 2.41.236 2.088 1.691 1.779 3.1-.312 1.408-1.932 5.629-2.348 6.62-.414.992.377.969 1.014.322.635-.648 4.521-8.454 4.633-10.3.111-1.846-.549-3.763-3.553-4.425-3.005-.665-13.655-.355-13.655-.355z"/></g></svg></div><div class="catship_tracking_carrier_info"><div class="catship_tracking_carrier_top"><span>USPS</span></div>
	<div class="catship_tracking_carrier_bottom"><span><a href="tel:1-800-275-8777">1-800-275-8777</a></span></div></div></div><br>
	<div class="catship_tracking_info_title"><span>Tracking Number</span></div>
	<div class="catship_tracking_info"><span><a href="https://tools.usps.com/go/TrackConfirmAction!input.action?tRef=qt&tLc=0&tLabels='.$tracking.'">'.$tracking.'</a></span></div><br>
	<div class="catship_tracking_info_title"><span>Estimated Delivery Date</span></div>
	<div class="catship_tracking_info"><span>'.$shipexpdeliv.' - '.$shippreddeliv.'</span></div><br>
	<div class="catship_tracking_info_title"><span>USPS Shipping Class</span></div>
	<div class="catship_tracking_info"><span>'.$shipclass.'</span></div>';

	die;
}
else{

die('No Order Found');
}

?>