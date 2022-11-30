<?php
namespace Drupal\aquila\Controller;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\Exception;
use Drupal\rest_toolkit\Normalizer\RestTypedDataNormalizer;
use Drupal\rest_toolkit\RestToolkitEndpointTrait;
use Drupal\taxonomy\Entity\Term;
use Stripe\Stripe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;
use \GuzzleHttp\Client;
use \GuzzleHttp\Exception\ClientException;

/**
 * Returns responses for aquila routes.
 */

class AquilaController extends ControllerBase {
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
		}

		define('AQUILA_FRONTEND_URL', $frontend_url);
		$this->user = \Drupal::currentUser();

		$this->format = 'json';
		$encoders = [new JsonEncoder()];
		$normalizers = [new RestTypedDataNormalizer()];
		$this->serializer = new Serializer($normalizers, $encoders);
	}

	/* Function to get order from networks */
	public function getSocialOrders(Request $request) {
		$response = [];
		$uid = $this->user->id();
		$aquila_service = \Drupal::service('aquila.oauth');
		$aquila_settings = \Drupal::service('config.factory')->get('aquila.settings');
		$ALLOWED_ATTEMPTS = 2;
		$networks = [];
		$past_date = strtotime('-3 months');
		$orders = false;
		$connections = \Drupal::entityQuery('network_connect')->condition('field_user', $this->user->id())->execute();
		$connections = \Drupal::entityTypeManager()->getStorage('network_connect')->loadMultiple($connections);

		foreach ($connections as $connection) {
			$network = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($connection->field_network->target_id);
			array_push($networks, $network);
		}

		foreach ($networks as $key => $network) {
			switch ($network->name->value) {
			case 'Etsy':
				$tried_attempts_etsy = 0;
				$api_url = 'https://api.etsy.com/v3/public/oauth/token';
				$etsy_api_key = $aquila_settings->get('etsy_api_key');
				$network_connect_id = \Drupal::entityQuery('network_connect')
					->condition('field_user', $this->user->id())
					->condition('field_network', $network->tid->value)
					->execute();
				if ($network_connect_id == []) {
					break;
				}

				$network_connection = \Drupal::entityTypeManager()->getStorage('network_connect')->load(reset($network_connect_id));
				$etsy_id = $network_connection->get('field_network_id')->value;
				$shop_id = $network_connection->get('field_shop_id')->value;
				$token = $network_connection->get('field_access_token')->value;
				$client = new Client([
					'api_url' => $api_url,
					'headers' => [
						'x-api-key' => $etsy_api_key,
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Authorization' => 'Bearer ' . $token,
					],
				]);

				do {
					try {
						//   For testing purposes
						$orders = $client->request('GET', 'https://openapi.etsy.com/v3/application/shops/' . $shop_id . '/receipts?limit=100');
						// $orders = $client->request('GET', 'https://openapi.etsy.com/v3/application/shops/' . $shop_id . '/receipts?range=' . $past_date);
						$response = json_decode($orders->getBody(), true);

						//  \Drupal::logger('aquila-etsy')->debug("<pre>Attempt: ".$tried_attempts_etsy." Allowed Attemps:".$ALLOWED_ATTEMPTS."</pre>");
						$tried_attempts_etsy++;
					} catch (ClientException $error) {
						// Get the original response
						$response = $error->getResponse();
						// Get the info returned from the remote server.
						$response_info = $response->getBody()->getContents();
						$data = json_decode($response_info, true);
						if ($data['error'] == 'invalid_token') {
							$aquila_service->refresh_token('etsy');
						}
						\Drupal::logger('aquila-etsy')->debug(
							"<pre>Error | Attempt: " . $tried_attempts_etsy .
							" Allowed Attemps:" . $ALLOWED_ATTEMPTS .
							" Error " . print_r($data, true) . "</pre>");
						$tried_attempts_etsy++;
						break;
					} catch (Exception $e) {
						$data = json_decode($e->getBody(), true);
						\Drupal::logger('aquila-etsy')->debug(
							"<pre>Error | Attempt: " . $tried_attempts_etsy .
							" Allowed Attemps:" . $ALLOWED_ATTEMPTS .
							"Error " . print_r($data, true) . "</pre>");

						if ($data['error'] == 'invalid_token') {
							$aquila_service->refresh_token('etsy');
						} else {
							$tried_attempts_etsy++;
							$headers = ['error' => $data];
						}

						$tried_attempts_etsy++;
						break;
					}
				} while ($tried_attempts_etsy < $ALLOWED_ATTEMPTS);

				if ($orders) {
					$order_results = json_decode($orders->getBody(), true);
					$entityManager = \Drupal::entityTypeManager()->getStorage('network_orders');

					// \Drupal::logger('aquila-etsy')->debug("Count <pre>" . count($order_results['results']) . "</pre>");
					foreach ($order_results['results'] as $order) {
						// dd('Order', $order);
						$network_receipt_id = \Drupal::entityQuery('network_orders')
							->condition('type', 'network_order')
							->condition('field_user', $this->user->id())
							->condition('field_external_order_id', $order['receipt_id'])
							->execute();
						// dd('Etsy Order',$order);
						if (!isset($network_receipt_id) || count($network_receipt_id) == 0) {

							// \Drupal::logger('aquila-etsy')->debug("<pre>New Order</pre>");
							$order_message = '';
							$order_message .= isset($order['message_from_payment']) ? $order['message_from_payment'] : '<br/>';
							$order_message .= isset($order['message_from_seller']) ? $order['message_from_seller'] : '<br/>';
							$order_message .= isset($order['message_from_buyer']) ? $order['message_from_buyer'] : '<br/>';
							$networkOrder = $entityManager->create([
								'title' => $this->user->getUsername() . " " . $network->name->value . " Order ID:" . $order['receipt_id'],
								'type' => 'network_order',
								'field_user' => $uid,
								'uid' => $uid,
								'field_external_order_id' => $order['receipt_id'],
								'field_address_city' => $order['city'],
								'field_address_line_1' => $order['first_line'],
								'field_address_line_2' => $order['second_line'],
								'field_address_state' => $order['state'],
								'field_address_zip' => $order['zip'],
								'field_buyer_email' => $order['buyer_email'],
								'field_buyer_name' => $order['name'],
								'field_buyer_phone' => isset($order['phone']) ? $order['phone'] : '',
								'field_date_ship_by' => date('Y-m-d\TH:i:s', $order['transactions'][0]['expected_ship_date']),
								'field_date_created' => date('Y-m-d\TH:i:s', $order['create_timestamp']),
								'field_external_status' => $order['status'],
								'field_total_sub' => ($order['subtotal']['amount'] / $order['subtotal']['divisor']),
								'field_total_shipping' => ($order['total_shipping_cost']['amount'] / $order['total_shipping_cost']['divisor']),
								'field_total_discount' => ($order['discount_amt']['amount'] / $order['discount_amt']['divisor']),
								'field_total_tax' => ($order['total_tax_cost']['amount'] / $order['total_tax_cost']['divisor']),
								'field_total_price' => ($order['total_price']['amount'] / $order['total_price']['divisor']),
								'field_total_other' => ($order['gift_wrap_price']['amount'] / $order['gift_wrap_price']['divisor']),
								'field_total_grand' => ($order['grandtotal']['amount'] / $order['grandtotal']['divisor']),
								'field_external_notes' => $order_message,
								'field_network' => $network->tid->value]);
							$networkOrder->save();
							$networkOrderId = $networkOrder->id();
							$order_items = [];
							$orderItemManager = \Drupal::entityTypeManager()->getStorage('network_orders');

							// \Drupal::logger('aquila-etsy')->debug("order transactions <pre>" . count($order['transactions']) . " | " . print_r($order['transactions'], TRUE) . "</pre>");

							foreach ($order['transactions'] as $order_item) {
								$order_item_id = \Drupal::entityQuery('network_orders')
									->condition('type', 'network_order_item')
									->condition('field_user', $this->user->id())
									->condition('field_product_id', $order_item['product_id'])
									->execute();

								if ($order_item_id) {
									array_push($order_items, reset($order_item_id));
								} else {
									// \Drupal::logger('aquila-etsy')->debug("orderItem <pre>" . print_r($order_item['product_id'], TRUE) . "</pre>");
									// \Drupal::logger('aquila-etsy')->debug("orderItem variations<pre>" . print_r($order_item['variations'], TRUE) . "</pre>");
									$variation_string = '';
									$index = 1;
									foreach ($order_item['variations'] as $variation) {
										$variation_string .= $variation['formatted_name'] . ': ' . $variation['formatted_value'];
										if ($index < count($order_item['variations'])) {
											$variation_string .= " | ";
										}
										$index++;
									}
									// \Drupal::logger('aquila-etsy')->debug("Variation? $variation_string");

									$orderItem = $orderItemManager->create([
										'title' => $order_item['title'],
										'type' => 'network_order_item',
										'field_user' => $uid,
										'uid' => $uid,
										'field_external_order_id' => $order['receipt_id'],
										'field_quantity' => $order_item['quantity'],
										'field_sku' => $order_item['sku'],
										'field_price' => $order_item['price']['amount'],
										'field_product_id' => $order_item['quantity'],
										'field_order' => $networkOrderId,
										'field_variation' => $variation_string,
										'field_product_id' => $order_item['product_id'],
									]);
									$orderItem->save();

									array_push($order_items, $orderItem->id());

									// \Drupal::logger('aquila-etsy')->debug($orderItem->id());
									// \Drupal::logger('aquila-etsy')->debug("order_item Loaded <pre>" . print_r($order_item, TRUE) . "</pre>");
									if ($order_item['listing_id'] != '' && $order_item['listing_image_id'] != '' && !$order_item_id) {
										try {
											$image = $client->request(
												'GET',
												'https://openapi.etsy.com/v3/application/shops/' . $shop_id .
												'/listings/' . $order_item['listing_id'] .
												'/images/' . $order_item['listing_image_id']
											);
											$image_response = json_decode($image->getBody(), true);
											$orderItem->set('field_external_image', $image_response['url_fullxfull']);
										} catch (ClientException $error) {
											// Get the original response
											$response = $error->getResponse();
											$response_info = $response->getBody()->getContents();
											$data = json_decode($response_info, true);
											\Drupal::logger('aquila-etsy')->debug('Image fetch error: <pre><code>' . print_r($data, true) . '</pre></code>');
										}
									}
								}
							}
							if ($order_items !== '') {
								$networkOrder->set('field_order_items', $order_items);
								$networkOrder->save();
							}
						} else {
							\Drupal::logger('aquila-etsy')->debug("Existing Order <pre>" . print_r($network_receipt_id, TRUE) . "</pre>");
							$order_message = '';
							$order_message .= isset($order['message_from_payment']) ? $order['message_from_payment'] : '<br/>';
							$order_message .= isset($order['message_from_seller']) ? $order['message_from_seller'] : '<br/>';
							$order_message .= isset($order['message_from_buyer']) ? $order['message_from_buyer'] : '<br/>';

							$existingNetworkOrder = $entityManager->load(reset($network_receipt_id));

							$existingNetworkOrder->set('uid', $uid);
							$existingNetworkOrder->set('field_address_city', $order['city']);
							$existingNetworkOrder->set('field_address_line_1', $order['first_line']);
							$existingNetworkOrder->set('field_address_line_2', $order['second_line']);
							$existingNetworkOrder->set('field_address_state', $order['state']);
							$existingNetworkOrder->set('field_address_zip', $order['zip']);
							$existingNetworkOrder->set('field_buyer_email', $order['buyer_email']);
							$existingNetworkOrder->set('field_buyer_name', $order['name']);
							$existingNetworkOrder->set('field_buyer_phone', isset($order['phone']) ? $order['phone'] : '');
							$existingNetworkOrder->set('field_date_ship_by', date('Y-m-d\TH:i:s', $order['transactions'][0]['expected_ship_date']));
							$existingNetworkOrder->set('field_date_created', date('Y-m-d\TH:i:s', $order['create_timestamp']));
							$existingNetworkOrder->set('field_external_status', $order['status']);
							$existingNetworkOrder->set('field_total_sub', ($order['subtotal']['amount'] / $order['subtotal']['divisor']));
							$existingNetworkOrder->set('field_total_shipping', ($order['total_shipping_cost']['amount'] / $order['total_shipping_cost']['divisor']));
							$existingNetworkOrder->set('field_total_discount', ($order['discount_amt']['amount'] / $order['discount_amt']['divisor']));
							$existingNetworkOrder->set('field_total_tax', ($order['total_tax_cost']['amount'] / $order['total_tax_cost']['divisor']));
							$existingNetworkOrder->set('field_total_price', ($order['total_price']['amount'] / $order['total_price']['divisor']));
							$existingNetworkOrder->set('field_total_other', ($order['gift_wrap_price']['amount'] / $order['gift_wrap_price']['divisor']));
							$existingNetworkOrder->set('field_total_grand', ($order['grandtotal']['amount'] / $order['grandtotal']['divisor']));
							$existingNetworkOrder->set('field_network', $network->tid->value);
							$existingNetworkOrder->set('field_external_notes', $order_message);
							$existingNetworkOrder->save();
						}
					}
				}
				break;
			case 'Squarespace':
				$tried_attempts_squarespace = 0;
				$api_url = 'https://api.squarespace.com/1.0/commerce/orders';
				$network_connect_id = \Drupal::entityQuery('network_connect')
					->condition('field_user', $this->user->id())
					->condition('field_network', $network->tid->value)
					->execute();

				if ($network_connect_id == []) {
					break;
				}
				$network_connection = \Drupal::entityTypeManager()
					->getStorage('network_connect')
					->load(reset($network_connect_id));
				$shop_id = $network_connection->get('field_shop_id')->value;
				$token = $network_connection->get('field_access_token')->value;

				$client = new Client([
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type' => 'application/x-www-form-urlencoded',
						'User-Agent' => 'TwoCan App, order aggregator.',
					],
				]);

				do {
					try {
						// For testing purposes
						$orders = $client->request('GET', 'https://api.squarespace.com/1.0/commerce/orders');
						$response = json_decode($orders->getBody(), true);
						\Drupal::logger('aquila-squarespace')->debug("<pre>Attempt: " . $tried_attempts_squarespace . " Allowed Attemps:" . $ALLOWED_ATTEMPTS . "</pre>");
						// \Drupal::logger('aquila-squarespace')->debug("<pre>" . print_r($response, TRUE) . "</pre>");
						$tried_attempts_squarespace++;

					} catch (ClientException $error) {
						// Get the original response
						$response = $error->getResponse();
						// Get the info returned from the remote server.
						$response_info = $response->getBody()->getContents();
						// watchdog_exception('Remote API Connection', $error);
						$data = json_decode($response_info, true);
						\Drupal::logger('aquila-squarespace')->debug("<pre>Attempt: " . $tried_attempts_squarespace . " Allowed Attemps:" . $ALLOWED_ATTEMPTS . " | Exeption Error" . print_r($data, true) . "</pre>");

						if ($data['type'] == 'AUTHORIZATION_ERROR') {
							$aquila_service->refresh_token('squarespace');
						}
						$tried_attempts_squarespace++;
						break;
					} catch (Exception $e) {
						$data = json_decode($e->getBody(), true);
						\Drupal::logger('aquila-squarespace')->debug(
							"<pre>Attempt: " . $tried_attempts_squarespace .
							" Allowed Attemps:" . $ALLOWED_ATTEMPTS .
							" | Exeption Error" . print_r($data, true) . "</pre>");
						if ($data['error'] == 'invalid_token') {
							$aquila_service->refresh_token('squarespace');
						} else {
							$tried_attempts_squarespace++;
							$headers = ['error' => $data];
						}
						// return $this->sendResponse($data);

						$tried_attempts_squarespace++;
						break;
					}
				} while ($tried_attempts_squarespace < $ALLOWED_ATTEMPTS);
				if ($orders) {
					$order_results = json_decode($orders->getBody(), true);
					$entityManager = \Drupal::entityTypeManager()->getStorage('network_orders');
					// dd('Orders', $order_results);

					foreach ($order_results['result'] as $order) {

						$network_receipt_id = \Drupal::entityQuery('network_orders')
							->condition('type', 'network_order')
							->condition('field_user', $this->user->id())
							->condition('field_external_order_id', $order['id'])
							->execute();
						// dd('Order', $order);
						$field_date_ship_by = $order['createdOn'];
						if (isset($order['fulfillments'][0]['shipDate'])) {
							$field_date_ship_by = $order['fulfillments'][0]['shipDate'];
						}
						// dd('Order', $order);
						// \Drupal::logger('aquila-squarespace')->debug("<pre>Order receipt: " . print_r($network_receipt_id, TRUE) . "</pre>");

						if (!isset($network_receipt_id) || count($network_receipt_id) == 0) {
							// \Drupal::logger('aquila-squarespace')->debug("<pre>New Order</pre>");
							// if (!$network_receipt_id) {
							// \Drupal::logger('aquila-squarespace')->debug("<pre>Network Id: " . print_r($network->tid->value, TRUE) . "</pre>");
							$order_message = $order['internalNotes'] ?? '';
							$networkOrder = $entityManager->create([
								'title' => $this->user->getUsername() . ' ' . $network->name->value . ' Order ID:' . $order['orderNumber'] . ' (unique ID:' . $order['id'] . ')',
								'type' => 'network_order',
								'field_user' => $uid,
								'uid' => $uid,
								'field_external_order_id' => $order['id'],
								'field_date_created' => date('Y-m-d\TH:i:s', strtotime($order['createdOn'])),
								'field_buyer_email' => $order['customerEmail'],
								'field_address_line_1' => $order['billingAddress']['address1'],
								'field_address_line_2' => $order['billingAddress']['address2'] ?? '',
								'field_address_city' => $order['billingAddress']['city'],
								'field_address_state' => $order['billingAddress']['state'],
								'field_address_zip' => $order['billingAddress']['postalCode'],
								'field_buyer_phone' => $order['billingAddress']['phone'] ?? '',
								'field_buyer_name' => $order['billingAddress']['firstName'] . ' ' . $order['billingAddress']['lastName'],
								'field_external_status' => $order['fulfillmentStatus'],
								'field_external_notes' => $order_message,
								'field_total_sub' => $order['subtotal']['value'],
								'field_total_shipping' => $order['shippingTotal']['value'],
								'field_total_discount' => $order['discountTotal']['value'],
								'field_total_tax' => $order['taxTotal']['value'],
								'field_total_price' => $order['grandTotal']['value'],
								'field_total_other' => $order['refundedTotal']['value'],
								'field_total_grand' => $order['grandTotal']['value'],
								'field_date_ship_by' => date('Y-m-d\TH:i:s', strtotime($field_date_ship_by)),
								'field_network' => $network->tid->value,
							]);
							$networkOrder->save();
							$networkOrderId = $networkOrder->id();
							$order_items = [];
							$orderItemManager = \Drupal::entityTypeManager()->getStorage('network_orders');

							foreach ($order['lineItems'] as $order_item) {

								// \Drupal::logger('aquila-squarespace')->debug("orderItem <pre>".print_r($order_item['product_id'], TRUE)."</pre>");
								$order_item_id = \Drupal::entityQuery('network_orders')
									->condition('type', 'network_order_item')
									->condition('field_user', $this->user->id())
									->condition('field_product_id', $order_item['productId'])
									->execute();

								if ($order_item_id) {
									array_push($order_items, reset($order_item_id));
								} else {

									// \Drupal::logger('aquila-squarespace')->debug("variantOptions isset <pre>" . isset($order_item['variantOptions']) . "</pre>");
									$variation_string = '';
									// Are there variations?
									if (isset($order_item['variantOptions'])) {

										$variantOptions_index = 1;
										foreach ($order_item['variantOptions'] as $variation) {
											$variation_string .= $variation['optionName'] . ': ' . $variation['value'];
											if ($variantOptions_index < count($order_item['variantOptions'])) {
												$variation_string .= " | ";
											}
											$variantOptions_index++;
										}
									}

									// Are there customizations?
									if (isset($order_item['customizations'])) {

										$customizations_index = 1;
										if (count($order_item['customizations']) > 0) {
											$variation_string .= " || Customizations: ";
										}

										foreach ($order_item['customizations'] as $variation) {
											$variation_string .= $variation['label'] . ': ' . $variation['value'];
											if ($customizations_index < count($order_item['customizations'])) {
												$variation_string .= " | ";
											}
											$customizations_index++;
										}
									}

									\Drupal::logger('aquila-squarespace')->debug("orderItem <pre>" . print_r($order_item, TRUE) . "</pre>");
									$orderItem = $orderItemManager->create([
										'title' => $order_item['productName'],
										'type' => 'network_order_item',
										'field_user' => $uid,
										'uid' => $uid,
										'field_external_order_id' => $order['id'],
										'field_quantity' => $order_item['quantity'],
										'field_sku' => $order_item['sku'],
										'field_price' => $order_item['unitPricePaid']['value'],
										'field_product_id' => $order_item['productId'],
										'field_order' => $networkOrderId,
										'field_variation' => $variation_string,
										'field_external_image' => $order_item['imageUrl']]);

									$orderItem->save();
									array_push($order_items, $orderItem->id());
								}
							}
							// \Drupal::logger('aquila-squarespace')->debug('<pre><code>'.print_r($order_items, TRUE).'</pre></code>');
							if ($order_items !== '') {
								$networkOrder->set('field_order_items', $order_items);
								$networkOrder->save();
							}
						} else {
							// \Drupal::logger('aquila-squarespace')->debug("<pre>Existing Order</pre>");

							// \Drupal::logger('aquila-squarespace')->debug("<pre>Network Id: " . print_r($network->tid->value, TRUE) . "</pre>");
							$order_message = $order['internalNotes'] ?? '';
							$existingNetworkOrder = $entityManager->load(reset($network_receipt_id));
							// dd($existingNetworkOrder);
							// \Drupal::logger('aquila-squarespace')->debug('<pre><code>Item already there ' . print_r($existingNetworkOrder, TRUE) . '</pre></code>');
							$existingNetworkOrder->set('uid', $uid);
							$existingNetworkOrder->set('field_address_line_1', $order['billingAddress']['address1']);
							$existingNetworkOrder->set('field_address_line_2', $order['billingAddress']['address2'] ?? '');
							$existingNetworkOrder->set('field_address_city', $order['billingAddress']['city']);
							$existingNetworkOrder->set('field_address_state', $order['billingAddress']['state']);
							$existingNetworkOrder->set('field_address_zip', $order['billingAddress']['postalCode']);
							$existingNetworkOrder->set('field_buyer_email', $order['customerEmail']);
							$existingNetworkOrder->set('field_buyer_name', $order['billingAddress']['firstName'] . ' ' . $order['billingAddress']['lastName']);
							$existingNetworkOrder->set('field_buyer_phone', $order['billingAddress']['phone'] ?? '');
							$existingNetworkOrder->set('field_date_ship_by', date('Y-m-d\TH:i:s', strtotime($field_date_ship_by)));
							$existingNetworkOrder->set('field_date_created', date('Y-m-d\TH:i:s', strtotime($order['createdOn'])));
							$existingNetworkOrder->set('field_external_status', $order['fulfillmentStatus']);
							$existingNetworkOrder->set('field_total_sub', $order['subtotal']['value']);
							$existingNetworkOrder->set('field_total_shipping', $order['shippingTotal']['value']);
							$existingNetworkOrder->set('field_total_discount', $order['discountTotal']['value']);
							$existingNetworkOrder->set('field_total_tax', $order['taxTotal']['value']);
							$existingNetworkOrder->set('field_total_price', $order['grandTotal']['value']);
							$existingNetworkOrder->set('field_total_other', $order['refundedTotal']['value']);
							$existingNetworkOrder->set('field_total_grand', $order['grandTotal']['value']);
							$existingNetworkOrder->set('field_network', $network->tid->value);
							$existingNetworkOrder->set('field_external_notes', $order_message);
							$existingNetworkOrder->save();

						}
					}
				}
				break;
			case 'Shopify':
				$tried_attempts_shopify = 0;
				$network_connect_id = \Drupal::entityQuery('network_connect')
					->condition('field_user', $this->user->id())
					->condition('field_network', $network->tid->value)
					->execute();

				if ($network_connect_id == []) {
					break;
				}
				$network_connection = \Drupal::entityTypeManager()
					->getStorage('network_connect')
					->load(reset($network_connect_id));
				$shop_id = $network_connection->get('field_shop_id')->value;
				$token = $network_connection->get('field_access_token')->value;
				$api_url = 'https://' . $shop_id . '/admin/api/2021-10/orders.json?status=any';

				$client = new Client([
					'api_url' => $api_url,
					'headers' => [
						'X-Shopify-Access-Token' => $token,
					],
				]);

				do {
					try {
						// For testing purposes
						$orders = $client->request('GET', $api_url);
						$response = json_decode($orders->getBody(), true);
						// dd(['Response', $response], ['URL', $api_url]);
						\Drupal::logger('aquila-shopify')->debug("<pre>Attempt: " . $tried_attempts_shopify . " Allowed Attemps:" . $ALLOWED_ATTEMPTS . "</pre>");
						// \Drupal::logger('aquila shopify orders')->debug("<pre>" . print_r($response, TRUE) . "</pre>");
						$tried_attempts_shopify++;

					} catch (ClientException $error) {
						// Get the original response
						$response = $error->getResponse();
						// Get the info returned from the remote server.
						$response_info = $response->getBody()->getContents();
						// watchdog_exception('Remote API Connection', $error);
						$data = json_decode($response_info, true);

						if ($data['type'] == 'AUTHORIZATION_ERROR') {
							$aquila_service->refresh_token('shopify');
						}
						\Drupal::logger('aquila-shopify')->debug("<pre>Attempt: " . $tried_attempts_shopify . " Allowed Attemps:" . $ALLOWED_ATTEMPTS . " | Exeption Error" . print_r($data, true) . "</pre>");
						$tried_attempts_shopify++;
						break;
					} catch (Exception $e) {
						$data = json_decode($e->getBody(), true);
						if ($data['error'] == 'invalid_token') {
							$aquila_service->refresh_token('shopify');
						} else {
							$tried_attempts_shopify++;
							$headers = ['error' => $data];
						}
						// return $this->sendResponse($data);
						\Drupal::logger('aquila-shopify')->debug(
							"<pre>Attempt: " . $tried_attempts_shopify .
							" Allowed Attemps:" . $ALLOWED_ATTEMPTS .
							" | Exeption Error" . print_r($data, true) . "</pre>");

						$tried_attempts_shopify++;
						break;
					}
				} while ($tried_attempts_shopify < $ALLOWED_ATTEMPTS);
				// dd('Orders Result', $orders);
				if ($orders) {
					$order_results = json_decode($orders->getBody(), true);
					$entityManager = \Drupal::entityTypeManager()->getStorage('network_orders');

					//dd('Orders', $order_results);

					foreach ($order_results['orders'] as $order) {
						$network_receipt_id = \Drupal::entityQuery('network_orders')
							->condition('type', 'network_order')
							->condition('field_user', $this->user->id())
							->condition('field_external_order_id', $order['id'])
							->execute();
						// dd('Order', $order);
						// dd('Order', $order);
						\Drupal::logger('aquila-shopify')->debug("<pre>Order receipt: " . print_r($network_receipt_id, TRUE) . "</pre>");

						// dd('Created', ['Original' => $order['created_at']], ['Converted' => date_format(date_create($order['created_at']), 'Y-m-d\TH:i:s')]);
						if (!isset($network_receipt_id) || count($network_receipt_id) == 0) {
							//if (!$network_receipt_id) {
							$order_message = $order['note'] ?? '';
							$buyer_name = $order['shipping_address']['first_name'] ?? '';
							$buyer_name .= $order['shipping_address']['last_name'] ?? ' ';
							$buyer_name .= $order['shipping_address']['last_name'] ?? '';

							$networkOrder = $entityManager->create([
								'title' => $this->user->getUsername() . ' ' . $network->name->value . ' Order ID:' . $order['order_number'] . ' (unique ID:' . $order['id'] . ')',
								'type' => 'network_order',
								'field_user' => $uid,
								'uid' => $uid,
								'field_external_order_id' => $order['id'],
								'field_date_created' => date_format(date_create($order['created_at']), 'Y-m-d\TH:i:s'),
								'field_buyer_email' => $order['email'],
								'field_address_line_1' => $order['shipping_address']['address1'] ?? '',
								'field_address_line_2' => $order['shipping_address']['address2'] ?? '',
								'field_address_city' => $order['shipping_address']['city'] ?? '',
								'field_address_state' => $order['shipping_address']['province'] ?? '',
								'field_address_zip' => $order['shipping_address']['zip'] ?? '',
								'field_buyer_phone' => $order['shipping_address']['phone'] ?? '',
								'field_buyer_name' => $buyer_name,
								'field_external_status' => $order['fulfillment_status'],
								'field_external_notes' => $order_message,
								'field_total_sub' => $order['subtotal_price'],
								'field_total_shipping' => isset($order['shipping_lines']['price']) ? $order['shipping_lines']['price'] : '0.00',
								'field_total_discount' => $order['total_discounts'],
								'field_total_tax' => $order['total_tax'],
								'field_total_price' => $order['total_price'],
								'field_total_other' => $order['total_tip_received'],
								'field_total_grand' => $order['total_price'],
								'field_date_ship_by' => date_format(date_create($order['created_at']), 'Y-m-d\TH:i:s'),
								'field_network' => $network->tid->value,
							]);
							$networkOrder->save();
							$networkOrderId = $networkOrder->id();
							$order_items = [];
							$orderItemManager = \Drupal::entityTypeManager()->getStorage('network_orders');

							foreach ($order['line_items'] as $order_item) {
								\Drupal::logger('aquila-shopify')->debug("OrderItem <pre>" . print_r($order_item['product_id'], TRUE) . "</pre>");
								$order_item_id = \Drupal::entityQuery('network_orders')
									->condition('type', 'network_order_item')
									->condition('field_user', $this->user->id())
									->condition('field_product_id', $order_item['product_id'])
									->execute();

								if ($order_item_id) {
									array_push($order_items, reset($order_item_id));
								} else {
									$orderItem = $orderItemManager->create([
										'title' => $order_item['name'],
										'type' => 'network_order_item',
										'field_user' => $uid,
										'uid' => $uid,
										'field_external_order_id' => $order['id'],
										'field_quantity' => $order_item['quantity'],
										'field_sku' => $order_item['sku'],
										'field_price' => $order_item['price'],
										'field_product_id' => $order_item['product_id'],
										'field_order' => $networkOrderId,
										'field_variation' => json_decode($order_item['variant_title']),
									]);
									$orderItem->save();
									array_push($order_items, $orderItem->id());
								}
							}
							// \Drupal::logger('aquila-shopify')->debug('<pre><code>'.print_r($order_items, TRUE).'</pre></code>');
							if ($order_items !== '') {
								$networkOrder->set('field_order_items', $order_items);
								$networkOrder->save();
							}
						} else {
							$order_message = $order['internalNotes'] ?? '';
							$existingNetworkOrder = $entityManager->load(reset($network_receipt_id));
							// dd($existingNetworkOrder);
							\Drupal::logger('aquila-shopify')->debug('<pre><code> Item already there' . print_r($existingNetworkOrder, TRUE) . '</code></pre>');

							$buyer_name = $order['shipping_address']['first_name'] ?? '';
							$buyer_name .= $order['shipping_address']['last_name'] ?? ' ';
							$buyer_name .= $order['shipping_address']['last_name'] ?? '';

							$existingNetworkOrder->set('uid', $uid);
							$existingNetworkOrder->set('field_address_line_1', $order['shipping_address']['address1'] ?? '');
							$existingNetworkOrder->set('field_address_line_2', $order['shipping_address']['address2'] ?? '');
							$existingNetworkOrder->set('field_address_city', $order['shipping_address']['city'] ?? '');
							$existingNetworkOrder->set('field_address_state', $order['shipping_address']['province'] ?? '');
							$existingNetworkOrder->set('field_address_zip', $order['shipping_address']['zip'] ?? '');
							$existingNetworkOrder->set('field_buyer_email', $order['email']);
							$existingNetworkOrder->set('field_buyer_name', $buyer_name);
							$existingNetworkOrder->set('field_buyer_phone', $order['shipping_address']['phone'] ?? '');
							$existingNetworkOrder->set('field_date_ship_by', date_format(date_create($order['created_at']), 'Y-m-d\TH:i:s'));
							$existingNetworkOrder->set('field_date_created', date_format(date_create($order['created_at']), 'Y-m-d\TH:i:s'));
							$existingNetworkOrder->set('field_external_status', $order['fulfillment_status']);
							$existingNetworkOrder->set('field_total_sub', $order['subtotal_price']);
							$existingNetworkOrder->set('field_total_shipping', isset($order['shipping_lines']['price']) ? $order['shipping_lines']['price'] : '0.00');
							$existingNetworkOrder->set('field_total_discount', $order['total_discounts']);
							$existingNetworkOrder->set('field_total_tax', $order['total_tax']);
							$existingNetworkOrder->set('field_total_price', $order['total_price']);
							$existingNetworkOrder->set('field_total_other', $order['total_tip_received']);
							$existingNetworkOrder->set('field_total_grand', $order['total_price']);
							$existingNetworkOrder->set('field_network', $network->tid->value);
							$existingNetworkOrder->set('field_external_notes', $order_message);
							$existingNetworkOrder->save();

						}
					}
				}
				break;
			}
		}
		// return $this->sendResponse($response);
		return new Response(json_encode($response));
	}

	/**
	 *
	 * Create Manual Orders
	 *
	 * */
	public function createManualOrder(Request $request) {
		$uid = $this->user->id();
		$order = json_decode($request->getContent(), true);

		$order_id = $uid . '-' . date('Ymd-His');
		// dd(date('Y-m-d\TH:i:s', strtotime($order['order']['field_date_ship_by'])));

		$entityManager = \Drupal::entityTypeManager()->getStorage('network_orders');
		$networkOrder = $entityManager->create([
			'title' => $this->user->getUsername() . " Manual Order ID:" . $order_id,
			'type' => 'network_order',
			'field_user' => $uid,
			'uid' => $uid,
			'field_external_order_id' => $order_id,
			'field_address_line_1' => $order['order']['field_address_line_1'],
			'field_address_line_2' => $order['order']['field_address_line_2'],
			'field_address_city' => $order['order']['field_address_city'],
			'field_address_state' => $order['order']['field_address_state'],
			'field_address_zip' => $order['order']['field_address_zip'],
			'field_buyer_email' => $order['order']['field_buyer_email'],
			'field_buyer_name' => $order['order']['field_buyer_name'],
			'field_buyer_phone' => isset($order['order']['field_buyer_phone']) ? $order['order']['field_buyer_phone'] : '',
			'field_date_ship_by' => date('Y-m-d\TH:i:s', strtotime($order['order']['field_date_ship_by'])),
			'field_date_created' => date('Y-m-d\TH:i:s', strtotime($order['order']['field_date_created'])),
			'field_total_sub' => $order['order']['field_total_sub'],
			'field_total_shipping' => $order['order']['field_total_shipping'],
			'field_total_discount' => $order['order']['field_total_discount'],
			'field_total_tax' => $order['order']['field_total_tax'],
			'field_total_price' => $order['order']['field_total_price'],
			'field_total_other' => $order['order']['field_total_other'],
			'field_total_grand' => $order['order']['field_total_grand'],
			'field_external_notes' => $order['order']['field_external_notes'],
		]);
		$networkOrder->save();
		$networkOrderId = $networkOrder->id();
		$order_items = [];
		$orderItemManager = \Drupal::entityTypeManager()->getStorage('network_orders');

		foreach ($order['lineItems'] as $order_item) {
			// \Drupal::logger('aquila')->debug("orderItem <pre>".print_r($order_item['product_id'], TRUE)."</pre>");
			$orderItem = $orderItemManager->create([
				'title' => $order_item['field_product_id'] . ': ' . $order_item['field_variation'],
				'type' => 'network_order_item',
				'field_user' => $uid,
				'uid' => $uid,
				'field_quantity' => $order_item['field_quantity'],
				'field_sku' => $order_item['field_sku'],
				'field_price' => $order_item['field_price'],
				'field_product_id' => $order_item['field_product_id'],
				'field_order' => $networkOrderId,
				'field_variation' => $order_item['field_variation'],
			]);

			$orderItem->save();
			array_push($order_items, $orderItem->id());
		}
		if ($order_items !== '') {
			$networkOrder->set('field_order_items', $order_items);
			$networkOrder->save();
		}
		// dd($networkOrder->id());
		// // $response = json_decode(reset($networkOrder), TRUE);
		// dd($this->sendNormalizedResponse($networkOrder, []));
		// dd($response);
		return new JsonResponse($networkOrder->id());
	}

	/**
	 *
	 * Create Manual Orders
	 *
	 * */
	public function updateManualOrder(Request $request) {
		$uid = $this->user->id();
		$order = json_decode($request->getContent(), true);
		$order_items = $order['lineItems'];
		$order = $order['order'];
		// dd($order, $order_items);
		$entityManager = \Drupal::entityTypeManager()->getStorage('network_orders');
		$networkOrder = $entityManager->load($order['id']);

		$networkOrder->set('title', $this->user->getUsername() . " Manual Order ID:" . $order['field_external_order_id']);
		$networkOrder->set('type', 'network_order');
		$networkOrder->set('field_user', $uid);
		$networkOrder->set('uid', $uid);
		$networkOrder->set('field_external_order_id', $order['field_external_order_id']);
		$networkOrder->set('field_address_line_1', $order['field_address_line_1']);
		$networkOrder->set('field_address_line_2', $order['field_address_line_2']);
		$networkOrder->set('field_address_city', $order['field_address_city']);
		$networkOrder->set('field_address_state', $order['field_address_state']);
		$networkOrder->set('field_address_zip', $order['field_address_zip']);
		$networkOrder->set('field_buyer_email', $order['field_buyer_email']);
		$networkOrder->set('field_buyer_name', $order['field_buyer_name']);
		$networkOrder->set('field_buyer_phone', isset($order['field_buyer_phone']) ? $order['field_buyer_phone'] : '');
		$networkOrder->set('field_date_ship_by', date('Y-m-d\TH:i:s', strtotime($order['field_date_ship_by'])));
		$networkOrder->set('field_date_created', date('Y-m-d\TH:i:s', strtotime($order['field_date_created'])));
		$networkOrder->set('field_total_sub', $order['field_total_sub']);
		$networkOrder->set('field_total_shipping', $order['field_total_shipping']);
		$networkOrder->set('field_total_discount', $order['field_total_discount']);
		$networkOrder->set('field_total_tax', $order['field_total_tax']);
		$networkOrder->set('field_total_price', $order['field_total_price']);
		$networkOrder->set('field_total_other', $order['field_total_other']);
		$networkOrder->set('field_total_grand', $order['field_total_grand']);
		$networkOrder->set('field_external_notes', $order['field_external_notes']);

		$networkOrder->save();
		$networkOrderId = $networkOrder->id();
		$order_items = [];
		$orderItemManager = \Drupal::entityTypeManager()->getStorage('network_orders');

		// Delete existing line items
		if ($networkOrder->field_order_items) {
			foreach ($networkOrder->field_order_items as $old_item) {
				// dd($old_item->entity->id());
				$delete = $orderItemManager->load($old_item->entity->id());
				$delete->delete();
			}
		}
		$networkOrder->set('field_order_items', '');
		// dd($order['field_order_items']);
		foreach ($order['field_order_items'] as $order_item) {
			// \Drupal::logger('aquila')->debug("orderItem <pre>".print_r($order_item['product_id'], TRUE)."</pre>");
			// dd($order_item);
			$orderItem = $orderItemManager->create([
				'title' => $order_item['product_id'] . ': ' . $order_item['variation'],
				'type' => 'network_order_item',
				'field_user' => $uid,
				'uid' => $uid,
				'field_quantity' => $order_item['quantity'],
				'field_sku' => $order_item['sku'],
				'field_price' => $order_item['price'],
				'field_product_id' => $order_item['product_id'],
				'field_order' => $networkOrderId,
				'field_variation' => $order_item['variation'],
			]);

			$orderItem->save();
			array_push($order_items, $orderItem->id());
		}
		if ($order_items !== '') {
			$networkOrder->set('field_order_items', $order_items);
			$networkOrder->save();
		}
		return new Response(json_encode($networkOrder));
	}

	/**
	 *
	 * Create Manual Orders
	 *
	 * */
	public function deleteManualOrder($order_id) {
		$uid = $this->user->id();
		try {
			$entityManager = \Drupal::entityTypeManager()->getStorage('network_orders');
			$networkOrder = $entityManager->load($order_id);
			// Delete existing line items
			if ($networkOrder->field_order_items) {
				foreach ($networkOrder->field_order_items as $old_item) {
					// dd($old_item->entity->id());
					$delete = $entityManager->load($old_item->entity->id());
					$delete->delete();
				}
			}
			$networkOrder->delete();
			$response = "The order has been deleted";
		} catch (Exeption $error) {
			$response = json_decode($error);
		}

		return $this->sendResponse($response);
		// return new Response(json_encode($networkOrder));
	}

	/* Function to check process steps */
	public function postProcessStep(Request $request) {
		$uid = $this->user->id();
		if (!$uid) {
			return $this->send400('Please sign in or create an account to use this feature.');
		}
		$params = json_decode($request->getContent(), true);
		// dd($params['name']);
		$step_query = \Drupal::entityQuery('taxonomy_term');
		$step_query->condition('vid',
			'Status');
		$step_query->condition('name', $params['name']);
		$step = $step_query->execute();

		if (!$step) {
			//Save the taxonomy term.
			$step = Term::create([
				'name' => $params['name'],
				'description' => array(
					'value' => $params['description'],
					'format' => 'basic_html',
				),
				'vid' => 'Status',
			]);
			$step->save();
			$response = $step->id();
		} else {
			$response = reset($step);
		}
		return $this->sendResponse((int) $response);
	}

	/* Function to check process steps */
	public function patchProcessStep(Request $request) {
		$uid = $this->user->id();
		if (!$uid) {
			return $this->send400('Please sign in or create an account to use this feature.');
		}
		$params = json_decode($request->getContent(), true);
		//  dd($request->getContent());
		//  $step_query = \Drupal::entityQuery('taxonomy_term');
		//  $step_query->condition('vid','Status');
		//  $step_query->condition('tid', $params['step_id']);
		//  $step = $step_query->execute();
		$step = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($params['step_id']);
		if ($step) {
			if ($params['new_name'] !== '') {
				$step->name->setValue($params['new_name']);
			}
			$step->set('description', array(
				'value' => $params['description'],
				'format' => 'basic_html',
			));
			$step->save();
			// $response = $this->normalizeEntity($step, true);
			$response = $this->serializer->serialize($step->field_task, $this->format, []);
			$response = json_decode($task, TRUE);
		} else {
			return $this->sendResponse('Something went wrong, we can\'t find that progress step');
		}
		return $this->sendResponse($response);
	}

	/* Function to get user's own tasks */
	public function getUserTasks(Request $request) {
		$uid = $this->user->id();
		$order_tasks_storage = \Drupal::entityTypeManager()->getStorage('order_tasks');
		$response = [];
		if ($request->get('order') == 'all') {
			$order_task_ids = \Drupal::entityQuery('order_tasks')->condition('type',
				'user2task')->condition('field_user', $this->user->id())->sort('field_due_date',
				'ASC')->execute();
		} else {
			$order_task_ids = \Drupal::entityQuery('order_tasks')->condition('type',
				'user2task')->condition('field_user', $this->user->id())->condition('field_order', $request->get('order'))->execute();
		}
		$order_2_tasks = $order_tasks_storage->loadMultiple($order_task_ids);

		foreach ($order_2_tasks as $order_2_task_id => $order_2_task) {
			// \Drupal::logger('aquila')->debug("<pre>".print_r($order_2_task->field_order->entity, TRUE)."</pre>");
			//      dd($order_2_task->field_order->target_id);
			$task = $this->serializer->serialize($order_2_task->field_task, $this->format, []);
			$task = json_decode($task, TRUE);
			// $task_id = $task;
			// dd($order_2_task->field_user->target_id);
			// dd($this->normalizeEntity(task->field_task , TRUE));
			//  dd($order_2_task->field_order->entity->id());
			// $response[$task_id] = reset(task);
			$response[$order_2_task_id]['task'] = $task['title'];
			$response[$order_2_task_id]['task_id'] = $task['id'];
			$response[$order_2_task_id]['due_date'] = $order_2_task->field_due_date->value;
			$response[$order_2_task_id]['assigned_name'] = $order_2_task->field_user->entity->name->value;
			$response[$order_2_task_id]['assigned_id'] = $order_2_task->field_user->target_id;
			$response[$order_2_task_id]['is_done'] = $order_2_task->field_is_done->value > 0 ? true : false;
			$response[$order_2_task_id]['uuid'] = $order_2_task->uuid->value;
			$response[$order_2_task_id]['order_id'] = $order_2_task->field_order->target_id;
			// dd($response);
			//$response[$task_id] = $task;
		}
		return new Response(json_encode($response));
	}

	public function connected_networks(Request $request) {
		$user = \Drupal::currentUser();
		$uid = $user->id();
		$connected_networks = [];
		$load_networks_id = \Drupal::entityQuery('network_connect')->condition('field_user', $uid)->execute();
		$load_networks = \Drupal::entityTypeManager()->getStorage('network_connect')->loadMultiple($load_networks_id);
		$networks = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('tags');
		// dd($networks);

		foreach ($networks as $key => $network) {
			$connected_networks[$key] = [
				'name' => $network->name,
				'connected' => false,
				'expired' => false];

			foreach ($load_networks as $connection) {
				if ($connection->field_network->entity->name->value == $network->name) {
					$connected_networks[$key] = [
						'name' => $network->name,
						'connected' => true,
						'expired' => $connection->field_expired->value == 1 ? true : false];
				}
			}
		}
		// dd($connected_networks);
		$response = $connected_networks;
		return new Response(json_encode($response));
	}

	public function stripe_pay(Request $request) {
		$encoders = [new JsonEncoder()];
		$normalizers = [new GetSetMethodNormalizer()];
		$serializer = new Serializer($normalizers, $encoders);
		$content = $request->getContent();
		// \Drupal::logger('Content')->debug($content);
		// dd($content);
		$postData = $serializer->decode($content,
			'json');
		$postData = (object) $postData;
		// Get the API Key from config
		$payment_gateway = \Drupal\commerce_payment\Entity\PaymentGateway::load('stripe_test');
		$config = $payment_gateway->getPluginConfiguration();
		$api_key = $config['secret_key'];
		$aquila_config = \Drupal::config('aquila.settings');
		$amount = ($aquila_config->get('membership_price') * 100);
		// dd($amount);
		if ($postData->data['coupon']) {
			$entityManager = \Drupal::entityTypeManager()->getStorage('coupon_codes');
			$coupon = $entityManager->getQuery()->condition('field_coupon', $postData->data['coupon'])->execute();
			// dd($coupon);
			if ($coupon) {
				$couponResponse = $entityManager->load(reset($coupon));
				// dd($couponResponse);
				// dd($couponResponse->field_discount->value);
				$amount = intval(floor($amount - ($amount * ($couponResponse->field_discount->value / 100))));
			}
		}
		// Set your secret key. Remember to switch to your live secret key in production.
		// See your keys here: https:// dashboard.stripe.com/account/apikeys
		// dd($postData->data['clienttoken']['id']);
		$stripe = new \Stripe\StripeClient($api_key);
		try {
			$charge = $stripe->charges->create(['amount' => $amount,
				'currency' => 'usd',
				'source' => $postData->data['clienttoken']['id'],
				'description' => 'Membership Charge for Bridal Blueprint']);
			$response = [$charge->status => "Your payment went through"];
		} catch (\Stripe\Exception\CardException $e) {
			// dd($e->getError()->message);
			$response = ['error' => $e->getError()->message];
		}
		return new Response(json_encode($response));
	}

	/**
	 * Get current subscription plan.
	 */
	public function createStripeSession(Request $request) {
		$referral = json_decode($request->getContent(), true);
		// dd($referral);
		// Loading the configuration.
		$config = \Drupal::config('stripe.settings');
		// Setting the secret key.
		$secretKey = $config->get('stripe.use_test') ? $config->get('stripe.sk_test') : $config->get('stripe.sk_live');
		Stripe::setApiKey($secretKey);
		// dd($referral);
		// Create the session on Stripe.
		try {
			$session = \Stripe\Checkout\Session::create(['success_url' => AQUILA_FRONTEND_URL .
				"user/settings",
				'cancel_url' => AQUILA_FRONTEND_URL .
				"user/logout",
				'payment_method_types' => ['card'],
				'mode' => 'subscription',
				'line_items' => $referral['line_items'],
				'customer' => $referral['customer'],
				'client_reference_id' => $referral['client_reference_id']]);
			return new Response(json_encode($session));
		} catch (GuzzleException $error) {
			throw $error->getMessage();
		}
	}

	/**
	 * Get current subscription plan.
	 */
	public function getSubscriptionPlan(Request $request) {
		// dd($request->get(customer_id));
		$customer = $request->get("customer_id");
		// dd($customer);
		// Loading the configuration.
		$config = \Drupal::config('stripe.settings');
		// Setting the secret key.
		$secretKey = $config->get('stripe.use_test') ? $config->get('stripe.sk_test') : $config->get('stripe.sk_live');
		$stripe = new \Stripe\StripeClient($secretKey);
		$response = $stripe->subscriptions->all(['customer' => $customer]);
		return new Response(json_encode($response));
	}

	/**
	 * Get current subscription plan.
	 */
	public function getStripeInvoices(Request $request) {
		// dd($request->get(customer_id));
		$customer = $request->get("customer_id");
		// dd($customer);
		// Loading the configuration.
		$config = \Drupal::config('stripe.settings');
		// Setting the secret key.
		$secretKey = $config->get('stripe.use_test') ? $config->get('stripe.sk_test') : $config->get('stripe.sk_live');
		$stripe = new \Stripe\StripeClient($secretKey);
		$response = $stripe->invoices->all(['customer' => $customer]);
		return new Response(json_encode($response));
	}

	public function create_ac_contact(Request $request) {
		$aquila_config = \Drupal::config('aquila.settings');
		$siteurl = $aquila_config->get('ac_url');
		$sitekey = $aquila_config->get('ac_api_key');
		$postData = $request->getContent();
		$postObject = json_decode($postData);
		$client = \Drupal::httpClient();
		$headers = array('Api-Token' => $sitekey);
		// check if email already exists
		try {
			$exists = $client->get($siteurl .
				'/api/3/contacts?email=' .
				$postObject->contact->email, array('headers' => $headers));
			$existsResponse = json_decode($exists->getBody(), true);
		} catch (GuzzleException $error) {
			// Get the original response
			$response = $error->getResponse();
		}
		try {
			// It exists and there is a tag
			if ($existsResponse['contacts'] && $postObject->contactTag) {
				// dd($existsResponse['contacts'][0]['id']);
				$tagJson = json_encode(array('contactTag' => [
					'contact' => $existsResponse['contacts'][0]['id'],
					'tag' => $postObject->contactTag->id,
				]));
				$tagResponse = $client->post($siteurl . '/api/3/contactTags', array('headers' => $headers, 'body' => $tagJson));
				$data = json_decode($tagResponse->getBody(), true);
				// dd($data);
				//There is a tag but contact doesn't exist
			} elseif ($postObject->contactTag) {
				$contactJson = json_encode(array('contact' => [
					'firstName' => $postObject->contact->firstName,
					'email' => $postObject->contact->email,
				]));
				$response = $client->post($siteurl . '/api/3/contacts', array('headers' => $headers, 'body' => $contactJson));
				$data = json_decode($response->getBody(), true);
				$tagJson = json_encode(array('contactTag' => ['contact' => $data['contact']['id'], 'tag' => $postObject->contactTag->id]));
				$tagResponse = $client->post($siteurl . '/api/3/contactTags', array('headers' => $headers, 'body' => $tagJson));
				// dd($tagResponse);
				$data = json_decode($response->getBody(), true);
				// If there is no tag
			} else {
				$response = $client->post($siteurl . '/api/3/contacts', array('headers' => $headers, 'body' => $postData));
				$data = json_decode($response->getBody(), true);
			}
			return new Response(json_encode($data));
		}
		// First try to catch the GuzzleException. This indicates a failed response from the remote API.
		 catch (GuzzleException $error) {
			// Get the original response
			$response = $error->getResponse();
			// Get the info returned from the remote server.
			$response_info = $response->getBody()->getContents();
			watchdog_exception('Remote API Connection', $response);
			$data = json_decode($response->getBody(), true);
			return $this->sendResponse($data);
		}
	}

	/* Function to get user's unread notifications */
	public function getUserUpdates(Request $request, $update) {
		$uid = $this->user->id();

		$limit = (int) \Drupal::request()->query->get('limit') ? (int) \Drupal::request()->query->get('limit') : (int) 10;
		$page = (int) \Drupal::request()->query->get('page') ? (int) \Drupal::request()->query->get('page') : (int) 0;
		$start = $page * $limit;
		$return_count = \Drupal::request()->query->get('return_count') ? \Drupal::request()->query->get('return_count') : "true";
		$unread_count = 0;

		$notifications_storage = \Drupal::entityTypeManager()->getStorage('notifications');
		$updates_storage = \Drupal::entityTypeManager()->getStorage('node');
		$response = array();

		$update_ids = \Drupal::entityQuery('node')
			->condition('type', 'updates')
			->condition('status', 1)
			->sort('created', 'DESC')
			->range($start, $limit)
			->execute();

		$count = \Drupal::entityQuery('node')
			->condition('type', 'updates')
			->condition('status', 1)
			->sort('created', 'DESC')
			->execute();
		$count = count($count);

		$updates = $updates_storage->loadMultiple($update_ids);

		foreach ($updates as $update) {
			$notification_id = \Drupal::entityQuery('notifications')->condition('type',
				'user2notification')
				->condition('field_user', $this->user->id())
				->condition('field_update', $update->id())
				->sort('created')
				->execute();

			if ($notification_id == []) {
				$unread_count++;
			}

			$update_response = [
				'id' => $update->id(),
				'title' => $update->title->value,
				'body' => $update->body->value,
				'created' => $update->created->value,
				'read' => $notification_id == [] ? false : true,
			];
			array_push($response, $update_response);
		}
		$response = [
			'updates' => $response,
			'pagination' => $count <= ($start + $limit) ? false : true,
			'count' => $unread_count,
		];
		return new Response(json_encode($response));
	}
	/* Function to mark an update as read */
	public function postUserUpdate(Request $request, $update) {
		$limit = json_decode($request->getContent(), true);
		$limit = $limit['limit'];
		try {
			$uid = $this->user->id();

			$notifications_storage = \Drupal::entityTypeManager()->getStorage('notifications');

			$update_ids = \Drupal::entityQuery('node')
				->condition('type', 'updates')
				->condition('status', 1)
				->sort('created', 'DESC')
				->range(0, $limit)
				->execute();

			// \Drupal::logger('aquila-notifications')->debug('<pre>' . print_r($update_ids, TRUE) . '</pre>');
			foreach ($update_ids as $id) {
				// \Drupal::logger('aquila-notifications')->debug('<pre>$id' . print_r($id, TRUE) . '</pre>');
				$notification_id = \Drupal::entityQuery('notifications')->condition('type',
					'user2notification')
					->condition('field_user', $this->user->id())
					->condition('field_update', $id)
					->execute();

				if ($notification_id == []) {
					$relationship = $notifications_storage->create([
						'type' => 'user2notification',
						'title' => $this->user->id() . ' read update: ' . $id,
						'field_user' => $this->user->id(),
						'field_update' => $id,
					]);

					// \Drupal::logger('aquila-notifications')->debug('<pre>' . print_r($relationship, TRUE) . '</pre>');
					$relationship->save();
				}
			}

			$response = ['message' => 'Read Notifications Updated'];
		} catch (Exception $e) {

			// Generic exception handling if something else gets thrown.
			\Drupal::logger('aquila-notifications')->error($e->getMessage());
			$response = ['error' => $e->getMessage()];
		}
		return new Response(json_encode($response));
	}
}
