<?php
namespace Drupal\aquila\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Renderer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for aquila routes.
 */

class AquilaWebhookController extends ControllerBase {

	/**
	 * The HTTP request object.
	 *
	 * @var \Symfony\Component\HttpFoundation\Request
	 */
	protected $request;

	/**
	 * The renderer.
	 *
	 * @var \Drupal\Core\Render\RendererInterface
	 */
	protected $renderer;
	/**
	 * Constructs a WebhookEntitiesController object.
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 *  The HTTP request object.
	 */
	public function __construct(Request $request, Renderer $renderer) {
		$this->request = $request;
		$this->renderer = $renderer;

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

		$this->network_id = \Drupal::entityQuery('taxonomy_term')
			->condition('name', 'Shopify')->execute();

		global $base_url;
		// \Drupal::logger('aquila-URL')->debug("<pre>" . print_r($base_url, TRUE) . "</pre>");
		$frontend_url = 'https://app.mycanapy.com';
		switch ($base_url == true) {
		case 'https://api.mycanapy.com.ddev.site':
			$frontend_url = 'http://localhost:8080';
			break;
		case 'https://stage-api.mycanapy.com':
			$frontend_url = 'https://stage-app.mycanapy.com';
			break;
		case 'https://api.mycanapy.com':
			$frontend_url = 'https://app.mycanapy.com';
			break;
		default:$frontend_url = 'https://app.mycanapy.com';
			break;
		}
		// \Drupal::logger('aquila-URL')->debug("<pre>" . print_r($frontend_url, TRUE) . "</pre>");
		$this->frontend_url = $frontend_url;

		# The Shopify app's API secret key, viewable from the Partner Dashboard. In a production environment, set the API secret key as an environment variable to prevent exposing it in code.

		$this->aquila_settings = \Drupal::service('config.factory')->get('aquila.settings');
		$this->api_key = $this->aquila_settings->get('shopify_api_key');
		$this->api_secret = $this->aquila_settings->get('shopify_api_secret');
		if (!defined('API_SECRET_KEY')) {
			define('API_SECRET_KEY', $this->api_secret);
		}

		// Webhook Data and Headers sent from shopify
		$this->hmac_header = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'];
		$this->data = file_get_contents('php://input');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container) {
		return new static(
			$container->get('request_stack')->getCurrentRequest(),
			$container->get('renderer')
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function verify_webhook($data, $hmac_header) {
		$calculated_hmac = base64_encode(hash_hmac('sha256', $data, API_SECRET_KEY, true));
		\Drupal::logger('aquila webhook')->debug("<pre>calculated_hmac " . print_r($calculated_hmac, TRUE) . "</pre>");
		return hash_equals($hmac_header, $calculated_hmac);
	}
	/**
	 *  This gets hit when a user wants to get information on their data
	 *
	 * @return  \Symfony\Component\HttpFoundation\Response;
	 *   a 200 response.
	 */

	public function dataRequest(Request $request) {
		\Drupal::logger('aquila webhook')->debug("<pre>dataRequest " . print_r($request, TRUE) . "</pre>");
		$response = new Response();
		$request = json_decode($request->getContent(), true);

		// Get the payload variables to be used in query
		$shop_id = $request['shop_id'];
		$shop_domain = $request['shop_domain'];
		$orders_requested = $request['orders_requested'];
		$customer = $request['customer'];
		$customerEmail = $customer['email'];

		$user_subject = $this->t('Canapy (a Shopify App) is procesing your request for private data');
		$user_message = $this->t('You have requested your private data from Shopify. Canapy is a Shopify public app installed in your shop. We are procesing your request, please allow up to 30 days for the developer to gather your data and reply to you. Thanks in advance for your patience.<br/>');

		$store_subject = $this->t("A user is requesting their private data from Shopify");
		$store_message = $this->t('A user requested their private data from Shopify.<br/>');

		// \Drupal::logger('aquila-webhooks')->debug("<pre>shop_id " . print_r($shop_id, TRUE) . "</pre>");
		// \Drupal::logger('aquila-webhooks')->debug("<pre>shop_domain " . print_r($shop_domain, TRUE) . "</pre>");
		// \Drupal::logger('aquila-webhooks')->debug("<pre>orders_requested " . print_r($orders_requested, TRUE) . "</pre>");
		// \Drupal::logger('aquila-webhooks')->debug("<pre>customer " . print_r($customer, TRUE) . "</pre>");

		//Figure out if the shop existis in the system.
		$shopOwner = \Drupal::entityTypeManager()->getStorage('network_connect');
		$shopOwner = $shopOwner->loadByProperties([
			'type' => 'user2network',
			'field_shop_id' => $shop_domain,
		]);
		$shopOwner = reset($shopOwner);
		if ($shopOwner) {
			// \Drupal::logger('aquila-webhooks')->debug("<pre>shopOwner " . print_r($shopOwner, TRUE) . "</pre>");
			if (count($orders_requested) > 0) {
				$store_message .= $this->t('Please send them the following:  <br/>');
				$order_storage = \Drupal::entityTypeManager()->getStorage('network_orders');
				foreach ($orders_requested as $key => $value) {
					$order = $order_storage->loadByProperties([
						'type' => 'network_order',
						'field_external_order_id' => $value,
					]);
					$order = reset($order);
					if ($order) {
						\Drupal::logger('aquila-webhooks')->debug("<pre>External Order: $value (internal " . print_r($order->id->value, TRUE) . ") was found</pre>");
						$store_message .= $this->t("Order ID: <a href='" . $this->frontend_url . "/user/order/" . $order->id->value . "'>" . $order->id->value . "</a><br/>");
					} else {
						\Drupal::logger('aquila-webhooks')->debug("<pre>External Order " . print_r($value, TRUE) . " was NOT found</pre>");
						$store_message .= $this->t("External Order ID: $value was not found in the system. <br/>");
					}
				}

			} else {
				$store_message .= $this->t("We couldn't find the information they require in the system. Please contact them at " . $customerEmail);
			}

			//Send Email to Store Owner
			//aquila_send_mail($shopOwner->field_user->entity, $store_message, $store_subject, 'notifications', $shopOwner->field_user->entity->mail->value);
			$store_params = array('body' => $store_message,
				'subject' => $store_subject);
			$mail_store = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $this->site_mail, 'en', $store_params, $this->site_mail);

		} else {

			$user_subject = $this->t('Canapy (a Shopify App) has received your request for private data');
			// \Drupal::logger('aquila-webhooks')->debug("<pre>shopOwner " . print_r($shop_domain, TRUE) . " wasn't found</pre>");
			$user_message = $this->t('The store you have requested your information from is not longer in the Canapy system. If you believe this is a mistake please reach out directly to info@mycanapy.com<br/><br/>

      The Canapy Team<br/>');

			// The store doesn't exist so send Email to Canapy Team
			$canapy_message = $this->t("We couldn't find the information for the <pre>" . $shop_domain . "</pre> store in the system. <br/>
                                   Please contact the customer at " . $customerEmail);
			if (count($orders_requested) > 0) {
				$canapy_message .= $this->t(" with the following orders if you have any information:<br/>");
				$canapy_message .= implode(", ", $orders_requested);
			}
			$canapy_params = array('body' => $canapy_message,
				'subject' => $this->t("A user is requesting their private data from Shopify store no longer in the system"));
			$mail_canapy = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $this->site_mail, 'en', $canapy_params, $this->site_mail);

		}

		//Send Email to user
		//              ($user, $message, $subject = '', $key = 'aquila_mail', $emailTo = '', $nl2br = TRUE)
		//aquila_send_mail(null, $user_message, $user_subject, 'notifications', $customerEmail);
		$user_params = array('body' => $user_message,
			'subject' => $user_subject);
		$mail_user = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $customerEmail, 'en', $user_params, $this->site_mail);

		return $response->setStatusCode(Response::HTTP_OK);
	}

	/**
	 * Checks if the shop exists and edits orders accordingly.
	 *
	 * @return  \Symfony\Component\HttpFoundation\Response;
	 *   a 200 response.
	 */
	public function customersRedact(Request $request) {
		\Drupal::logger('aquila webhook')->debug("<pre>customersRedact " . print_r($request, TRUE) . "</pre>");
		$response = new Response();
		$request = json_decode($request->getContent(), true);

		// Get the payload variables to be used in query
		$shop_id = $request['shop_id'];
		$shop_domain = $request['shop_domain'];
		$orders_to_redact = $request['orders_to_redact'];
		$customer = $request['customer'];
		$customerEmail = $customer['email'];

		$user_subject = $this->t('Canapy (a Shopify App) is procesing your request for private data');
		$user_message = $this->t('You have requested your private data be redacted from Shopify. Canapy is a Shopify public app installed in your shop. We are procesing your request, please allow up to 30 days for the developer to gather your data and reply to you. Thanks in advance for your patience.<br/>');

		$store_subject = $this->t("A user is requesting their private data be redacted from Shopify");
		$store_message = $this->t('A user requested their private data from Shopify to be redacted.<br/>');

		// \Drupal::logger('aquila-webhooks')->debug("<pre>shop_id " . print_r($shop_id, TRUE) . "</pre>");
		// \Drupal::logger('aquila-webhooks')->debug("<pre>shop_domain " . print_r($shop_domain, TRUE) . "</pre>");
		// \Drupal::logger('aquila-webhooks')->debug("<pre>orders_requested " . print_r($orders_requested, TRUE) . "</pre>");
		// \Drupal::logger('aquila-webhooks')->debug("<pre>customer " . print_r($customer, TRUE) . "</pre>");

		//Figure out if the shop existis in the system.
		$shopOwner = \Drupal::entityTypeManager()->getStorage('network_connect');
		$shopOwner = $shopOwner->loadByProperties([
			'type' => 'user2network',
			'field_shop_id' => $shop_domain,
		]);
		$shopOwner = reset($shopOwner);

		if ($shopOwner) {
			// \Drupal::logger('aquila-webhooks')->debug("<pre>shopOwner " . print_r($shopOwner, TRUE) . "</pre>");
			if (count($orders_to_redact) > 0) {
				$store_message .= $this->t('The following orders have been redacted:  <br/>');
				$order_storage = \Drupal::entityTypeManager()->getStorage('network_orders');
				foreach ($orders_to_redact as $key => $value) {
					$order = $order_storage->loadByProperties([
						'type' => 'network_order',
						'field_network' => $this->network_id,
						'field_external_order_id' => $value,
					]);

					$order = reset($order);

					if ($order) {
						\Drupal::logger('aquila-webhooks')->debug("<pre>External Order: $value (internal " . print_r($order->id->value, TRUE) . ") has been deleted</pre>");
						$store_message .= $this->t("External Order ID  $value (internal " . print_r($order->id->value, TRUE) . ") has been deleted<br/>");
					} else {
						\Drupal::logger('aquila-webhooks')->debug("<pre>External Order " . print_r($value, TRUE) . " was NOT found</pre>");
						$store_message .= $this->t("External Order ID: $value was not found in the system. <br/>");
					}
				}

			} else {
				$store_message .= $this->t("We couldn't find the information they require in the system. Please contact them at " . $customerEmail);
			}

			//Send Email to Store Owner
			//aquila_send_mail($shopOwner->field_user->entity, $store_message, $store_subject, 'notifications', $shopOwner->field_user->entity->mail->value);
			$store_params = array('body' => $store_message,
				'subject' => $store_subject);
			$mail_store = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $this->site_mail, 'en', $store_params, $this->site_mail);

		} else {

			$user_subject = $this->t('Canapy (a Shopify App) has received your request for private data');
			// \Drupal::logger('aquila-webhooks')->debug("<pre>shopOwner " . print_r($shop_domain, TRUE) . " wasn't found</pre>");
			$user_message = $this->t('The store you have requested your information from is not longer in the Canapy system. If you believe this is a mistake please reach out directly to info@mycanapy.com<br/><br/>

      The Canapy Team<br/>');

			// The store doesn't exist so send Email to Canapy Team
			$canapy_message = $this->t("We couldn't find the information for the <pre>" . $shop_domain . "</pre> store in the system. <br/>
                                   Please contact the customer at " . $customerEmail);
			if (count($orders_to_redact) > 0) {
				$canapy_message .= $this->t(" with the following orders if you have any information:<br/>");
				$canapy_message .= implode(", ", $orders_to_redact);
			}
			$canapy_params = array('body' => $canapy_message,
				'subject' => $this->t("A user is requesting their private data from Shopify store no longer in the system"));
			$mail_canapy = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $this->site_mail, 'en', $canapy_params, $this->site_mail);

		}

		//Send Email to user
		//              ($user, $message, $subject = '', $key = 'aquila_mail', $emailTo = '', $nl2br = TRUE)
		//aquila_send_mail(null, $user_message, $user_subject, 'notifications', $customerEmail);
		$user_params = array('body' => $user_message,
			'subject' => $user_subject);
		$mail_user = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $customerEmail, 'en', $user_params, $this->site_mail);

		return $response->setStatusCode(Response::HTTP_OK);
	}

	/**
	 * Checks if the shop exists and deletes it's connection.
	 *
	 * @return  \Symfony\Component\HttpFoundation\Response;
	 *   a 200 response.
	 */
	public function shopRedact(Request $request) {
		//  \Drupal::logger('aquila webhook')->debug("<pre>shopRedact " . print_r($request, TRUE) . "</pre>");

		$response = new Response();
		$request = json_decode($request->getContent(), true);

		// Get the payload variables to be used in query
		$shop_id = $request['shop_id'];
		$shop_domain = $request['shop_domain'];

		$user_subject = $this->t('Canapy (a Shopify App) is procesing your request to redact your shop\'s data');
		$store_subject = $this->t("A user uninstalled Canapy from Shopify");

		// \Drupal::logger('aquila-webhooks')->debug("<pre>shop_id " . print_r($shop_id, TRUE) . "</pre>");
		// \Drupal::logger('aquila-webhooks')->debug("<pre>shop_domain " . print_r($shop_domain, TRUE) . "</pre>");

		//Figure out if the shop existis in the system.
		$shopOwner_storage = \Drupal::entityTypeManager()->getStorage('network_connect');
		$shopOwner_storage = $shopOwner_storage->loadByProperties([
			'type' => 'user2network',
			'field_shop_id' => $shop_domain,
		]);
		$shopOwner = reset($shopOwner_storage);
		if ($shopOwner) {
			// \Drupal::logger('aquila-webhooks')->debug("<pre>shopOwner " . print_r($shopOwner, TRUE) . "</pre>");

			$shopOrders_storage = \Drupal::entityTypeManager()->getStorage('network_orders');
			$shopOrders = $shopOrders_storage->loadByProperties([
				'type' => 'network_order',
				'field_network' => $this->network_id,
				'field_user' => $shopOwner->field_user->entity->id(),
			]);

			// $shopOrders = reset($shopOrders);
			// \Drupal::logger('aquila-webhooks')->debug("<pre>Orders (" . count($shopOrders) . ") " . print_r($shopOrders, TRUE) . " </pre>");
			if ($shopOrders) {

				foreach ($shopOrders as $order) {
					\Drupal::logger('aquila-webhooks')->debug("<pre>Delete Orders " . print_r($order, TRUE) . "</pre>");
					$order->delete();
					// $delete = $shopOrders_storage->load($order->id());
					// \Drupal::logger('aquila-webhooks')->debug("<pre>Delete this " . print_r($delete, TRUE) . "</pre>");
					//$delete->delete();
				}
			}

			$shopOwner->delete();

			$user_message = $this->t('You have uninstalled the Canapy app on your Shopify store. We are procesing your request and will delete your Shopify connection and Shopify orders from our database, please allow up to 30 days for this change to be reflected in your account.<br/><br/>');

			$canapy_message = $this->t($shop_domain . " has uninstalled Canapy from Shopify, as a result we have removed their Shopify connection and Shopify orders from the system.");

			//Send Email to user
			//              ($user, $message, $subject = '', $key = 'aquila_mail', $emailTo = '', $nl2br = TRUE)
			//aquila_send_mail(null, $user_message, $user_subject, 'notifications', $customerEmail);
			$user_message .= "<br/><br/>The Canapy Team<br/>";
			$user_params = array('body' => $user_message,
				'subject' => $user_subject);
			$mail_user = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $shopOwner->field_user->entity->mail->value, 'en', $user_params, $this->site_mail);
		} else {

			// The store doesn't exist so send Email to Canapy Team
			$canapy_message = $this->t("We couldn't find the information for the <pre>" . $shop_domain . "</pre> store in the system. <br/>
                                   If you believe this is a mistake, please contact the Aquila Development");
			$canapy_params = array('body' => $canapy_message,
				'subject' => $this->t("A user (<pre>" . $shop_domain . "</pre>) is requesting their private data from Shopify store no longer in the system, please ensure that no orders remain in the system related to this store."));
		}

		//Send Email to Canapy Owner
		//aquila_send_mail($shopOwner->field_user->entity, $store_message, $store_subject, 'notifications', $shopOwner->field_user->entity->mail->value);
		$canapy_params = array('body' => $canapy_message,
			'subject' => $store_subject);
		$mail_store = \Drupal::service('plugin.manager.mail')->mail('aquila', 'aquila_notifications', $this->site_mail, 'en', $canapy_params, $this->site_mail);

		return $response->setStatusCode(Response::HTTP_OK);
	}

	/**
	 * Checks access for incoming webhook notifications.
	 *
	 * @return \Drupal\Core\Access\AccessResultInterface
	 *   The access result.
	 */
	public function access() {
		$response = new Response();
		$allowed = false;

		$verified = $this->verify_webhook($this->data, $this->hmac_header);
		// Check error.log to see the result
		// \Drupal::logger('aquila-webhooks')->debug('Webhook verified: ' . print_r($verified, true));
		if ($verified) {
			$allowed = true;
		}
		//  else {
		// 	return $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
		// }

		// Compare the stored token value to the token in each notification.
		// If they match, allow access to the route.
		// \Drupal::logger('aquila')->debug("<pre>Allowed " . print_r($allowed, TRUE) . "</pre>");
		return AccessResult::allowedIf($allowed === true);
	}
}
