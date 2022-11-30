<?php
namespace Drupal\aquila\Controller;

use Drupal\Aquila\Oauth;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\rest_toolkit\RestToolkitEndpointTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use \GuzzleHttp\Client;
use \GuzzleHttp\Exception\ClientException;

// use Guzzle\Plugin\Oauth\OauthPlugin;
// use Hybridauth\HttpClient\Util;
// use Hybridauth\HttpClient;
// use Hybridauth\Adapter\Oauth2;

/**
 * Returns responses for aquila routes.
 */
class AquilaAuthController extends ControllerBase {
	use RestToolkitEndpointTrait;

	public function __construct() {

		global $base_url;
		$frontend_url = 'https://app.mycanapy.com/';
		switch ($base_url) {
		case 'https://api.mycanapy.com.ddev.site':$frontend_url = 'http://localhost:8080/';
			break;
		case 'https://stage-api.mycanapy.com':$frontend_url = 'https://stage-app.mycanapy.com/';
			break;
		case 'https://api.mycanapy.com':$frontend_url = 'https://app.mycanapy.com/';
			break;
		default:$frontend_url = 'https://app.mycanapy.com/';
			break;
		};
		define('AQUILA_FRONTEND_URL', $frontend_url);
		$this->user = \Drupal::currentUser();

		// Get the custom site notification email to use as the from email address
		// if it has been set.
		$this->site_mail = \Drupal::config('system.site')->get('mail_notification');
		// If the custom site notification email has not been set, we use the site
		// default for this.
		if (empty($this->site_mail)) {
			$this->site_mail = \Drupal::config('system.site')->get('mail');
		}
		if (empty($this->site_mail)) {
			$this->site_mail = ini_get('sendmail_from');
		}

	}

	/**
	 * Send mail to admin for shopify.
	 */

	function notify(Request $request) {
		\Drupal::logger('aquila-notify')->debug("<pre>A User is requesting a link for shopify</pre>");
		$response = new Response();
		$postData = json_decode($request->getContent(), true);
		//dd($postData);
		$subject = $postData['field_first_name'] . ": is requesting access to Shopify";
		$message = $postData['field_first_name'] . " " . $postData['field_last_name'] . " (uid: " . $postData['uid'] . ") is requesting access to their shopfify store (" . $postData['field_shopify_url'] . ")<br/> You can mail the link to " . $postData['name'];
		$params = array('body' => $message,
			'subject' => $subject,
			'headers' => array(
				'Bcc' => 'marco@useaquila.dev',
				'Cc' => 'marco@useaquila.dev',
			));
		\Drupal::logger('aquila-notify')->debug("<pre>Post: " . print_r($postData, TRUE) . "</pre>");
		$mail_store = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $this->site_mail, 'en', $params, $this->site_mail);

		$response->setStatusCode(Response::HTTP_OK);
		$response->setContent(json_encode($params));

		//For test
		\Drupal::logger('aquila-notify')->debug("<pre>" . print_r($params, TRUE) . "</pre>");
		$mail_dev = \Drupal::service('plugin.manager.mail')
			->mail('aquila', 'aquila_notifications', 'marco@useaquila.dev', 'en', ['body' => $message, 'subject' => $subject], $this->site_mail);
		return $response;
	}

	/**
	 * OAuth for apps.
	 */

	function oauth(Request $request, $network) {
		//Determine if the user is coming from shopify
		\Drupal::logger('aquila-oath')->debug("<pre>Network " . print_r($network, TRUE) . "</pre>");
		// $isShopify = $network == 'shopify' ? true : false;
		global $base_root;
		$request_url = [];
		$base_url = '';
		$aquila_settings = \Drupal::service('config.factory')->get('aquila.settings');
		$verifier = $aquila_settings->get('verifier');

		// \Drupal::logger('aquila-oath')->debug("<pre>User Attempting Connection</pre>");

		// if ($isShopify) {
		// 	$shopify_api_key = $aquila_settings->get('shopify_api_key');

		// 	$user = \Drupal::entityQuery('user')
		// 		->condition('field_shopify_url', $request->query->get('shop'))->execute();
		// 	$uid = reset($user);
		// 	\Drupal::logger('aquila-oath')->debug("<pre>User  Attempting Shopify Connection</pre>");

		// 	if (!$uid) {
		// 		$error = "We can't a user with your store in our records";
		// 		$headers = ['error' => $error];
		// 		\Drupal::logger('aquila-oath')->debug("<pre>User | Error " . print_r(['error' => 'Social connect canceled.', 'message' => $error], TRUE) . "</pre>");
		// 		$request_url = AQUILA_FRONTEND_URL . "user/settings";
		// 		$redirect_response = new TrustedRedirectResponse($request_url, 302, $headers);

		// 	} else {
		// 		$headers = [];

		// 		$request_url = 'https://' . $request->query->get('shop') . '/admin/oauth/authorize';
		// 		\Drupal::logger('aquila-oath')->debug("<pre>User $uid | loggin link " . print_r($request_url, TRUE) . "</pre>");
		// 		$request_url .= '?client_id=' . $shopify_api_key;
		// 		$request_url .= '&scope=read_orders';
		// 		$request_url .= '&redirect_uri=' . $base_root . '/api/v1/auth/shopify/callback';
		// 		$request_url .= '&state=' . $aquila_settings->get('verifier') . '_yellowjacketsurvivor_' . $uid;

		// 		$redirect_response = new TrustedRedirectResponse($request_url, 302, $headers);
		// 	}
		// 	// dd($redirect_response);
		// 	sleep(2);
		// 	return $redirect_response;

		// } else {

		$user = \Drupal::currentUser();
		$uid = $user->id();
		\Drupal::logger('aquila-oath')->debug("<pre>User: " . print_r($uid, TRUE) . " requesting oath urls</pre>");
		// $networks = ['etsy', 'squarespace'];
		$networks = ['etsy', 'squarespace'];
		if (!$uid) {
			\Drupal::logger('aquila-oath')->debug("<pre>User with no ID requesting oath URLs</pre>");
			return $this->send400('Please sign in or create an account to use this feature.');
		}
		foreach ($networks as $key => $network) {
			$callback = $base_root . '/api/v1/auth/' . $network . '/callback';

			switch ($network) {
			case 'etsy':
				$etsy_api_key = $aquila_settings->get('etsy_api_key');
				$request_url[$network] = 'https://www.etsy.com/oauth/connect';
				$scope = 'transactions_r profile_r listings_r email_r';
				$response_type = 'code';
				$state = $verifier . '_yellowjacketsurvivor_' . $uid;
				$challenge_bytes = hash("sha256", $verifier, true);
				$code_challenge = rtrim(strtr(base64_encode($challenge_bytes), "+/", "-_"), "=");
				//$code_challenge = strtr(trim(base64_encode($verifier), '='), '+/', '-_');
				//$code_challenge = base64url_encode(pack('H*',hash('sha256',$verifier)));
				$code_challenge_method = 'S256';
				$request_url[$network] .= '?response_type=code&redirect_uri=' . $callback .
					'&scope=' . $scope .
					'&client_id=' . $etsy_api_key .
					'&state=' . $state .
					'&code_challenge=' . $code_challenge .
					'&code_challenge_method=' . $code_challenge_method;
				break;
			case 'squarespace':

				$squarespace_api_key = $aquila_settings->get('squarespace_api_key');
				$request_url[$network] = 'https://login.squarespace.com/api/1/login/oauth/provider/authorize';
				$scope = 'website.orders';
				$state = $aquila_settings->get('verifier') . '_yellowjacketsurvivor_' . $uid;
				$request_url[$network] .= '?client_id=' . $squarespace_api_key .
				'&redirect_uri=' . urlencode($callback) .
				'&scope=' . urlencode($scope) .
				'&state=' . urlencode($state) .
					'&access_type=offline';
				break;
			// case 'shopify':
			// 	$shopify_api_key = $aquila_settings->get('shopify_api_key');
			// 	$request_url[$network] = '.myshopify.com/admin/oauth/authorize';
			// 	$scope = 'read_orders';
			// 	$state = $aquila_settings->get('verifier') . '_yellowjacketsurvivor_' . $uid;
			// 	$request_url[$network] .= '?client_id=' . $shopify_api_key .
			// 		'&response_type=code' .
			// 		'&redirect_uri=' . $callback .
			// 		'&scope=' . $scope .
			// 		'&state=' . $state;

			// 	break;
			default:
				throw new Exception("Missing Network in request", 500);
				break;
			}
		}

		$response = $request_url;
		\Drupal::logger('aquila-oath-debug')->debug("<pre>" . print_r($response, true) . "</pre>");
		// //$response = $client->get($request_url);
		return new Response(json_encode($response));

	}

	function oauth_callback(Request $request, $network) {
		\Drupal::logger('aquila-oath-callback')->debug("<pre>User has landed from " . print_r($network, TRUE) . " callback</pre>");
		global $base_root;
		$aquila_service = \Drupal::service('aquila.oauth');
		$request_url = [];
		$aquila_settings = \Drupal::service('config.factory')->get('aquila.settings');
		$verifier = $aquila_settings->get('verifier');
		$headers = [];
		$aquila_settings = \Drupal::service('config.factory')->get('aquila.settings');
		$verifier = $aquila_settings->get('verifier');
		// $uid = substr($state, strpos($state, '_yellowjacketsurvivor_') + 22);
		// If the user canceled, callback is still hit, but with other params.
		// These could be:
		// error: "user_cancelled_login"
		// error_description: "The+user+cancelled+LinkedIn+login"
		if (!empty($request->query->get('error'))) {
			// dd($request);
			$headers = ['error' => 'Social connect canceled.', 'message' => $request->query->get('error')];
			\Drupal::logger('aquila-oath')->debug("<pre>User  Attempting " . $network . " Connection</pre>");
			\Drupal::logger('aquila-oath')->debug("<pre>User $uid  | Error " . print_r(['error' => 'Social connect canceled.', 'message' => $request->query->get('error')], TRUE) . "</pre>");
			$redirect_response = new TrustedRedirectResponse(AQUILA_FRONTEND_URL . "user/settings", 302, $headers);
		}
		// if (!$uid) {
		//   return $this->send400('Please sign in or create an account to use this feature.');
		// }
		switch ($network) {
		case 'etsy':
			$code = $request->query->get('code');
			$state = $request->query->get('state');
			// dd($request);
			$api_url = 'https://api.etsy.com/v3/public/oauth/token';
			$api_key = 'qg386ktvvbssvqopi6j7uqio';
			$secret = '3gq8t4twur';
			$callback = $base_root . '/api/v1/auth/' . $network . '/callback';
			//$uid = $this->user->id();
			$uid = substr($state, strpos($state, '_yellowjacketsurvivor_') + 22);
			// Load the current user.
			$user = \Drupal\user\Entity\User::load($uid);

			\Drupal::logger('aquila-oath')->debug("<pre>User " . $user->id() . " Attempting " . $network . " Connection</pre>");
			$client = new Client([
				'api_url' => $api_url,
				'headers' => ['x-api-key' => $api_key,
					'Content-Type' => 'application/x-www-form-urlencoded'],
			]);
			//dd($client);
			$post_body = [
				'grant_type' => 'authorization_code',
				'client_id' => $api_key,
				'redirect_uri' => $callback,
				'code' => $code,
				'code_verifier' => $verifier,
			];
			// dd($post_body);
			$network_id = \Drupal::entityQuery('taxonomy_term')
				->condition('name', $network)->execute();
			$network_connect_id = \Drupal::entityQuery('network_connect')
				->condition('field_user', $user->id())
				->condition('field_network', $network_id)->execute();

			\Drupal::logger('aquila-oath-etsy')->debug("<pre>Network ID " . print_r($network_connect_id, true) . "</pre>");
			// dd($network_connect_id);
			if ($network_connect_id) {
				$aquila_service->refresh_token('etsy', $uid);
			} else {
				try
				{

					// dd($user->name->value);
					$response = $client->request('POST', $api_url, ['form_params' => $post_body]);
					$data = json_decode($response->getBody(), true);

					$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();

					$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();
					//dd(reset($network_id));// = $network_id->id();
					$entityManager = \Drupal::entityTypeManager()->getStorage('network_connect');
					$networkConnection = $entityManager->create([
						'title' => $user->name->value . ' ' . $network . ' Connection',
						'type' => 'user2network',
						'field_user' => $user->id(),
						'field_access_token' => $data['access_token'],
						'field_network' => reset($network_id),
						'field_token_expiration' => date('d/m/Y'),
						'field_refresh_token' => $data['refresh_token'],
						'field_network_id' => strtok($data['access_token'], '.')]);
					$networkConnection->save();

					\Drupal::logger('aquila-oath')->debug("Created Etsy Connection");
					$headers = ['success' => $network . ' has been authenticated'];
					// dd($networkConnection);
					// return $this->sendResponse($data);

				} catch (ClientException $error) {

					// Get the original response
					$response = $error->getResponse();
					// Get the info returned from the remote server.
					$response_info = $response->getBody()->getContents();
					// watchdog_exception('Remote API Connection', $error);
					$data = json_decode($response_info, true);

					\Drupal::logger('aquila-oath')->debug("<pre>ClientException Error" . print_r($data, true) . "</pre>");

					//dd($data['error']);
					if ($data['error'] == 'invalid_token') {

						$aquila_service->refresh_token('etsy');

						$network_connection = \Drupal::entityTypeManager()->getStorage('network_connect')
							->load(reset($network_connect_id));
						$token = $network_connection->get('field_access_token')->value;
						$shop_id = $network_connection->get('field_shop_id')->value;

						$shops = $client->request('GET', 'https://openapi.etsy.com/v3/application/shops/' . $shop_id . '/receipts');
						$response = json_decode($shops->getBody(), true);
					} else {
						$headers = ['error' => $error];
					}
				} catch (Exception $e) {

					$data = json_decode($e->getBody(), true);
					\Drupal::logger('aquila-oath')->debug("<pre>Exeption Error" . print_r($data, true) . "</pre>");
					// dd($data['error']);
					if ($data['error'] == 'invalid_token') {
						$aquila_service->refresh_token('etsy');
						$network_connection = \Drupal::entityTypeManager()->getStorage('network_connect')->load(reset($network_connect_id));
						$token = $network_connection->get('field_access_token')->value;
						$shop_id = $network_connection->get('field_shop_id')->value;

						$shops = $client->request('GET', 'https://openapi.etsy.com/v3/application/shops/' . $shop_id . '/receipts');
						$response = json_decode($shops->getBody(), true);
					} else {
						$headers = ['error' => $data];
					}
					// return $this->sendResponse($data);

				}
			};

			// dd($headers, array_search('success', $headers), array_key_exists('success', $headers));
			// return $this->sendResponse($data);
			// \Drupal::logger('aquila-oath')->debug("<pre>" . print_r($headers, true) . "</pre>");
			if (array_key_exists('success', $headers)) {

				$shops = $client->request('GET', 'https://openapi.etsy.com/v3/application/users/' . strtok($data['access_token'], '.') . '/shops');
				$shops = json_decode($shops->getBody(), true);

				$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();

				$network_connect_id = \Drupal::entityQuery('network_connect')->condition('field_user', $user->id())
					->condition('field_network', $network_id)->execute();

				$network_connection = \Drupal::entityTypeManager()->getStorage('network_connect')
					->load(reset($network_connect_id));
				$network_connection->set('field_shop_id', $shops['shop_id']);
				$network_connection->set('field_shop_title', $shops['shop_name']);
				$network_connection->save();

				\Drupal::logger('aquila-oath')->debug("<pre>" . print_r($shops, true) . "</pre>");
				// return $this->sendResponse($data);

			}

			$redirect_response = new TrustedRedirectResponse(AQUILA_FRONTEND_URL . "user/settings", 302, $headers);
			break;
		case 'squarespace':

			$create = true;
			$code = $request->query->get('code');
			$state = $request->query->get('state');
			$api_url = 'https://login.squarespace.com/api/1/login/oauth/provider/tokens';
			$callback = $base_root . '/api/v1/auth/' . $network . '/callback';
			//$uid = $this->user->id();
			$uid = substr($state, strpos($state, '_yellowjacketsurvivor_') + 22);
			// Load the current user.
			$user = \Drupal\user\Entity\User::load($uid);
			\Drupal::logger('aquila-oath')->debug("<pre>User " . $user->id() . " Attempting " . $network . " Connection</pre>");

			$api_key = $aquila_settings->get('squarespace_api_key');
			$api_secret = $aquila_settings->get('squarespace_api_secret');
			$authorization_key = base64_encode($api_key . ':' . $api_secret);

			$client = new Client([
				'api_url' => $api_url,
				'headers' => [
					'Authorization' => 'Basic ' . $authorization_key,
					'Content-Type' => 'application/json'],
			]);
			//dd($client);
			$post_body = ['grant_type' => 'authorization_code', 'redirect_uri' => $callback, 'code' => $code];
			// dd($post_body);
			$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();
			$network_connect_id = \Drupal::entityQuery('network_connect')
				->condition('field_user', $user->id())
				->condition('field_network', $network_id)->execute();

			\Drupal::logger('aquila-oath')->debug("<pre>" . print_r($network_connect_id, true) . "</pre>");
			// If the user already has a network Connection
			if ($network_connect_id) {
				$load_networks = \Drupal::entityTypeManager()->getStorage('network_connect')->loadMultiple($network_connect_id);
				$userNetwork = reset($load_networks);
				// dd(var_dump($userNetwork->field_expired->value));
				// If the connection exists and has no error, simply refresh
				$create = false;
				if ($userNetwork->field_expired->value !== "1") {
					$aquila_service->refresh_token('squarespace');
				}
			}

			if ($create == true) {
				try {
					// dd($user->name->value);
					$response = $client->request('POST', $api_url, ['form_params' => $post_body]);
					$data = json_decode($response->getBody(), true);
					$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();
					//dd(reset($network_id));// = $network_id->id();
					$entityManager = \Drupal::entityTypeManager()->getStorage('network_connect');
					$networkConnection = $entityManager->create([
						'title' => $user->name->value . ' ' . $network . ' Connection',
						'type' => 'user2network',
						'field_user' => $user->id(),
						'field_access_token' => $data['access_token'],
						'field_network' => reset($network_id),
						'field_token_expiration' => date('d/m/Y', $data['refresh_token_expires_at']),
						'field_refresh_token' => $data['refresh_token'],
						'field_network_id' => strtok($data['access_token'], '.')]);
					$networkConnection->save();

					\Drupal::logger('aquila-oath')->debug("Created Squarespace Connection");
					$headers = ['success' => $network . ' has been authenticated'];
					// dd($networkConnection);
					// return $this->sendResponse($data);
				} catch (ClientException $error) {
					// Get the original response
					$response = $error->getResponse();
					// Get the info returned from the remote server.
					$response_info = $response->getBody()->getContents();
					// watchdog_exception('Remote API Connection', $error);
					$data = json_decode($response_info, true);

					\Drupal::logger('aquila-oath')->debug("<pre>ClientException Error" . print_r($data, true) . "</pre>");

					//dd($data['error']);
					$headers = ['error' => $error];
				} catch (Exception $e) {
					$data = json_decode($e->getBody(), true);
					\Drupal::logger('aquila-oath')->debug("<pre>Exeption Error" . print_r($data, true) . "</pre>");
					$headers = ['error' => $data];
				}

				//Reauthentication process
			} else {

				try {
					// dd($user->name->value);
					$response = $client->request('POST', $api_url, ['form_params' => $post_body]);
					$data = json_decode($response->getBody(), true);
					$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();
					//dd(reset($network_id));// = $network_id->id();
					$entityManager = \Drupal::entityTypeManager()->getStorage('network_connect');
					$userNetwork->set('field_access_token', $data['access_token']);
					$userNetwork->set('field_token_expiration', date('d/m/Y', $data['refresh_token_expires_at']));
					$userNetwork->set('field_refresh_token', $data['refresh_token']);
					$userNetwork->set('field_expired', false);
					$userNetwork->save();

					\Drupal::logger('aquila-oath')->debug("Created Squarespace Connection");
					$headers = ['success' => $network . ' has been re-authenticated'];
					// dd($networkConnection);
					// return $this->sendResponse($data);
				} catch (ClientException $error) {
					// Get the original response
					$response = $error->getResponse();
					// Get the info returned from the remote server.
					$response_info = $response->getBody()->getContents();
					// watchdog_exception('Remote API Connection', $error);
					$data = json_decode($response_info, true);

					\Drupal::logger('aquila-oath')->debug("<pre>ClientException Error" . print_r($data, true) . "</pre>");

					//dd($data['error']);
					$headers = ['error' => $error];
				}
			};
			$redirect_response = new TrustedRedirectResponse(AQUILA_FRONTEND_URL . "user/settings", 302, $headers);
			break;

		default:
			throw new Exception("Missing Network in request", 500);
			$headers = ['error' => 'Missing Network in request'];
			$redirect_response = new TrustedRedirectResponse(AQUILA_FRONTEND_URL . "user/settings", 302, $headers);
		}

		return $redirect_response;
		// case 'shopify':
		// 	$create = true;
		// 	$code = $request->query->get('code');
		// 	$hmac = $request->query->get('hmac');
		// 	$shop = $request->query->get('shop');
		// 	$state = $request->query->get('state');
		// 	$timestamp = $request->query->get('timestamp');

		// 	$api_url = 'https://' . $shop . '/admin/oauth/access_token';
		// 	$callback = $base_root . '/api/v1/auth/' . $network . '/callback';
		// 	//$uid = $this->user->id();
		// 	$uid = substr($state, strpos($state, '_yellowjacketsurvivor_') + 22);
		// 	// Load the current user.
		// 	$user = \Drupal\user\Entity\User::load($uid);
		// 	\Drupal::logger('aquila-oath')->debug("<pre>User " . $user->id() . " Attempting " . $network . " Connection</pre>");

		// 	$api_key = $aquila_settings->get('shopify_api_key');
		// 	$api_secret = $aquila_settings->get('shopify_api_secret');
		// 	$authorization_key = base64_encode($api_key . ':' . $api_secret);
		// 	// $message = 'code='.$code.'&shop='.$shop.'&state='.$state.'&timestamp='.$timestamp;
		// 	// dd($message,hash_hmac('sha256', $message, $api_secret), $hmac);
		// 	// if(hash_hmac('sha256', $message, $api_secret) == $hmac){
		// 	//
		// 	// }
		// 	$client = new Client();
		// 	// dd($client);
		// 	$post_body = ['client_id' => $api_key, 'client_secret' => $api_secret, 'code' => $code];
		// 	// dd($post_body);
		// 	$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();
		// 	$network_connect_id = \Drupal::entityQuery('network_connect')
		// 		->condition('field_user', $user->id())
		// 		->condition('field_network', $network_id)->execute();

		// 	\Drupal::logger('aquila-shopify')->debug("<pre> Network ID " . print_r($network_connect_id) . "</pre>");
		// 	// If the user already has a network Connection
		// 	if ($network_connect_id) {
		// 		$create = false;
		// 	}

		// 	try {
		// 		$response = $client->request('POST', $api_url, ['form_params' => $post_body]);
		// 		// dd($response);
		// 		$data = json_decode($response->getBody(), true);
		// 		// dd($data);
		// 		$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();
		// 		// dd(reset($network_id));// = $network_id->id();
		// 		$entityManager = \Drupal::entityTypeManager()->getStorage('network_connect');
		// 		if ($create == true) {
		// 			\Drupal::logger('aquila-oath')->debug("<pre>Create new " . $network . " Connection</pre>");
		// 			$networkConnection = $entityManager->create([
		// 				'title' => $user->name->value . ' ' . $network . ' Connection',
		// 				'type' => 'user2network',
		// 				'field_user' => $user->id(),
		// 				'field_access_token' => $data['access_token'],
		// 				'field_network' => reset($network_id),
		// 				'field_shop_id' => $shop,
		// 				'field_shop_title' => $shop,
		// 			]);
		// 			$networkConnection->save();
		// 		} else {
		// 			\Drupal::logger('aquila-oath')->debug("<pre>Update " . $network . " Connection</pre>");
		// 			$networkConnection = $entityManager->load(reset($network_connect_id));
		// 			$networkConnection->set('field_access_token', $data['access_token']);
		// 			$networkConnection->set('field_shop_id', $shop);
		// 			$networkConnection->set('field_shop_title', $shop);
		// 			$networkConnection->save();
		// 		}

		// 		\Drupal::logger('aquila-oath-shopify')->debug("Created shopify Connection");
		// 		$headers = ['success' => $network . ' has been authenticated'];
		// 		// dd($networkConnection);
		// 		// return $this->sendResponse($data);
		// 	} catch (ClientException $error) {
		// 		// dd($error);
		// 		// Get the original response
		// 		$response = $error->getResponse();
		// 		// Get the info returned from the remote server.
		// 		$response_info = $response->getBody()->getContents();
		// 		// watchdog_exception('Remote API Connection', $error);
		// 		$data = json_decode($response_info, true);

		// 		\Drupal::logger('aquila-oath-shopify')->debug("<pre>ClientException Error " . print_r($data, true) . "</pre>");

		// 		//dd($data['error']);
		// 		$headers = ['error' => $error];
		// 	} catch (Exception $e) {
		// 		$data = json_decode($e->getBody(), true);
		// 		\Drupal::logger('aquila-oath-shopify')->debug("<pre>Exeption Error" . print_r($data, true) . "</pre>");
		// 		$headers = ['error' => $data];
		// 	}
		// 	$redirect_response = new TrustedRedirectResponse(AQUILA_FRONTEND_URL . "user/settings", 302, $headers);
		// 	break;
	}

	function oauth_shopify(Request $request) {
		$network = 'Shopify';
		$user = \Drupal::currentUser();
		$uid = $user->id();
		$credentials = json_decode($request->getContent(), true);
		$create = true;

		$user = \Drupal\user\Entity\User::load($uid);
		\Drupal::logger('aquila-oath')->debug("<pre>User " . $user->id() . " Attempting " . $network . " Connection</pre>");

		$shop = $credentials['store'];
		$api_token = $credentials['token'];
		$api_key = $credentials['key'];
		$api_secret = $credentials['secret'];

//        dd($post_body);
		$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();
		$network_connect_id = \Drupal::entityQuery('network_connect')
			->condition('field_user', $user->id())
			->condition('field_network', $network_id)->execute();

		\Drupal::logger('aquila-shopify')->debug("<pre> Network ID " . print_r($network_connect_id) . "</pre>");
		// If the user already has a network Connection
		if ($network_connect_id) {
			$create = false;
		}
		// dd($data);
		$network_id = \Drupal::entityQuery('taxonomy_term')->condition('name', $network)->execute();
		// dd(reset($network_id));// = $network_id->id();
		$entityManager = \Drupal::entityTypeManager()->getStorage('network_connect');
		if ($create == true) {
			\Drupal::logger('aquila-oath')->debug("<pre>Create new " . $network . " Connection</pre>");
			$networkConnection = $entityManager->create([
				'title' => $user->name->value . ' ' . $network . ' Connection',
				'type' => 'user2network',
				'field_user' => $user->id(),
				'field_access_token' => $api_token,
				'field_network' => reset($network_id),
				'field_shop_id' => $shop,
				'field_shop_title' => $shop,
			]);
			$networkConnection->save();
		} else {
			\Drupal::logger('aquila-oath')->debug("<pre>Update " . $network . " Connection</pre>");
			$networkConnection = $entityManager->load(reset($network_connect_id));
			$networkConnection->set('field_access_token', $api_token);
			$networkConnection->set('field_shop_id', $shop);
			$networkConnection->set('field_shop_title', $shop);
			$networkConnection->save();
		}

		\Drupal::logger('aquila-oath-shopify')->debug("Created shopify Connection");
		// dd($networkConnection);
		// return $this->sendResponse($data);

		return new Response(json_encode($networkConnection));
	}

}
