<?php

namespace Drupal\aquila;

use \GuzzleHttp\Client;
use \GuzzleHttp\Exception\ClientException;
/**
 * Oauth service.
 */
class Oauth {

  /**
   * Method description.
   */
  public function refresh_token($network, $uid = '') {
    $aquila_settings = \Drupal::service('config.factory')->get('aquila.settings');
    $verifier = $aquila_settings->get('verifier');
    if($uid != ''){
      $user = \Drupal\user\Entity\User::load($uid);
    }else{
      $user =  \Drupal::currentUser();
    }

    switch ($network) {
      case 'etsy':
        $api_url = 'https://api.etsy.com/v3/public/oauth/token';
        $api_key = $aquila_settings->get('etsy_api_key');


        $client = new Client([
          'api_url' => $api_url,
          'headers' => [
            'x-api-key'=> $api_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
          ]
        ]);
        $network_id = \Drupal::entityQuery('taxonomy_term')
          ->condition('name', $network)
          ->execute();

        $network_connect_id = \Drupal::entityQuery('network_connect')
          ->condition('field_user', $user->id())
          ->condition('field_network', $network_id)
          ->execute();

        $refresh_token = \Drupal::entityTypeManager()->getStorage('network_connect')->load(reset($network_connect_id));
        // dd($refresh_token);

        $post_body = [
          'grant_type' => 'refresh_token',
          'client_id' => $api_key,
          'refresh_token' => $refresh_token->get('field_refresh_token')->value
        ];
        
        
        // dd($user->name->value);
        try{
          $response = $client->request('POST', $api_url, ['form_params' => $post_body]);
          $data = json_decode($response->getBody(), TRUE);        
        }catch(ClientException $error) {
          
          // Get the original response
          $response = $error->getResponse();
          
          // Get the info returned from the remote server.
          $response_info = $response->getBody()->getContents();
          $data = json_decode($response_info, true);
          $refresh_token->set('field_expired' , true);
          $refresh_token->save();
          \Drupal::logger('Squarespace Error')->debug(
                  "<pre>Error " . print_r($data, true). "</pre>");
          break;
        }

        $refresh_token->set('field_expired' , false);
        $refresh_token->set('field_access_token' , $data['access_token']);
        $refresh_token->set('field_refresh_token' , $data['refresh_token']);
        $refresh_token->save();

        break;

      case 'squarespace':
        $api_url = 'https://login.squarespace.com/api/1/login/oauth/provider/tokens';
        $api_key = $aquila_settings->get('squarespace_api_key');
        $api_secret = $aquila_settings->get('squarespace_api_secret');
        $authorization_key = base64_encode($api_key.':'.$api_secret);


        $client = new Client([
          'api_url' => $api_url,
          'headers' => [
            'Authorization'=> 'Basic '.$authorization_key,
            'Content-Type' => 'application/json'
          ]
        ]);
        $network_id = \Drupal::entityQuery('taxonomy_term')
          ->condition('name', $network)
          ->execute();

        $network_connect_id = \Drupal::entityQuery('network_connect')
          ->condition('field_user', $user->id())
          ->condition('field_network', $network_id)
          ->execute();
        
        $refresh_token = \Drupal::entityTypeManager()->getStorage('network_connect')->load(reset($network_connect_id));
         //dd($refresh_token->get('field_refresh_token')->value);
        $post_body = [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token->get('field_refresh_token')->value
        ];

        // dd($user->name->value);
        try{
          $response = $client->request('POST', $api_url, ['form_params' => $post_body]);
          $data = json_decode($response->getBody(), TRUE);        
        }catch(ClientException $error) {
          
          // Get the original response
          $response = $error->getResponse();
          
          // Get the info returned from the remote server.
          $response_info = $response->getBody()->getContents();
          $data = json_decode($response_info, true);
          $refresh_token->set('field_expired' , true);
          $refresh_token->save();
          \Drupal::logger('Squarespace Error')->debug(
                  "<pre>Error " . print_r($data, true). "</pre>");
          break;
        }

        $refresh_token->set('field_expired' , false);
        $refresh_token->set('field_access_token' , $data['access_token']);
        $refresh_token->set('field_refresh_token' , $data['refresh_token']);
        $refresh_token->save();

        break;

      default:
        throw new Exception("Missing Network in request", 500);
      break;

    }
  }

}
