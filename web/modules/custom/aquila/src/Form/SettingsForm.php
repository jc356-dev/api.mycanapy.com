<?php

namespace Drupal\aquila\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure aquila settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'aquila_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['aquila.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['api_verifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Verifier'),
      '#description' => $this->config('aquila.settings')->get('verifier'),
      '#default_value' => $this->config('aquila.settings')->get('verifier'),
    ];

    $form['etsy_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etsy API Key'),
      '#description' => $this->t('You can find your key at https://www.etsy.com/developers/your-apps under API Key details'),
      '#default_value' => $this->config('aquila.settings')->get('etsy_api_key'),
    ];

    $form['etsy_api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etsy API Secret'),
      '#description' => $this->t('You can find your secret at https://www.etsy.com/developers/your-apps under API Key details'),
      '#default_value' => $this->config('aquila.settings')->get('etsy_api_secret'),
    ];

    $form['squarespace_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SquareSpace API Key'),
      '#description' => $this->t('Requested at https://partner.squarespace.com/oauth-form'),
      '#default_value' => $this->config('aquila.settings')->get('squarespace_api_key'),
    ];

    $form['squarespace_api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SquareSpace API Secret'),
      '#default_value' => $this->config('aquila.settings')->get('squarespace_api_secret'),
    ];

    $form['shopify_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shopify API Key'),
      '#description' => $this->t('Requested at https://partner.shopify.com/oauth-form'),
      '#default_value' => $this->config('aquila.settings')->get('shopify_api_key'),
    ];

    $form['shopify_api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shopify API Secret'),
      '#default_value' => $this->config('aquila.settings')->get('shopify_api_secret'),
    ];

    $form['ac_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AC API Key'),
      '#description' => 'Active Campaign API Key',//$this->config('aquila.settings')->get('ac_api_key'),
      '#default_value' => $this->config('aquila.settings')->get('ac_api_key')
    ];

    $form['ac_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AC Base URL'),
      '#description' => 'Active Campaign base URL, i.e https://example.api-us1.com',//$this->config('aquila.settings')->get('ac_api_key'),
      '#default_value' => $this->config('aquila.settings')->get('ac_url')
    ];


    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // dd($form_state);
    if (strlen($form_state->getValue('api_verifier')) < 43){
      $form_state->setErrorByName('api_verifier', $this->t('Please use a string between 43 and 128 characters.'));
    } elseif (strlen($form_state->getValue('api_verifier')) > 128) {
      $form_state->setErrorByName('api_verifier', $this->t('Please use a string between 43 and 128 characters.'));
    }
    if (strlen($form_state->getValue('etsy_api_key')) > 25){
      $form_state->setErrorByName('etsy_api_key', $this->t('Your Etsy API Key should be 24 characters.'));
    }
    if (strlen($form_state->getValue('etsy_api_secret')) > 10){
      $form_state->setErrorByName('etsy_api_secret', $this->t('Your Etsy API Secret should be 10 characters.'));
    }
    if (strlen($form_state->getValue('squarespace_api_key')) > 64){
      $form_state->setErrorByName('squarespace_api_key', $this->t('Your SquareSpace API Key should be 44 characters.'));
    }
    if (strlen($form_state->getValue('squarespace_api_secret')) > 64){
      $form_state->setErrorByName('squarespace_api_secret', $this->t('Your SquareSpace API Secret should be 32 characters.'));
    }
    if (strlen($form_state->getValue('shopify_api_key')) > 32){
      $form_state->setErrorByName('shopify_api_key', $this->t('Your Shopify API Key should be 32 characters.'));
    }
    if (strlen($form_state->getValue('shopify_api_secret')) > 38){
      $form_state->setErrorByName('shopify_api_secret', $this->t('Your Shopify API Secret should be 32 characters.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = \Drupal::service('config.factory')->getEditable('aquila.settings');
    // $this->config('aquila.settings')
    //   ->set('example', $form_state->getValue('example'))
    //   ->save();

    //dd($form_state);
    // Set and save new message value.
    $config->set('verifier', $form_state->getValue('api_verifier'))
          ->save();

    $config->set('etsy_api_key', $form_state->getValue('etsy_api_key'))
      ->save();

    $config->set('etsy_api_secret', $form_state->getValue('etsy_api_secret'))
      ->save();

    $config->set('squarespace_api_key', $form_state->getValue('squarespace_api_key'))
      ->save();

    $config->set('squarespace_api_secret', $form_state->getValue('squarespace_api_secret'))
      ->save();


    $config->set('shopify_api_key', $form_state->getValue('shopify_api_key'))
      ->save();

    $config->set('shopify_api_secret', $form_state->getValue('shopify_api_secret'))
      ->save();

    $config->set('ac_url', $form_state->getValue('ac_url'))
      ->save();

    $config->set('ac_api_key', $form_state->getValue('ac_api_key'))
      ->save();
    //dd($this->config('aquila.settings'));
    // Now will print 'Hi'.
    print $config->get('verifier');
        parent::submitForm($form, $form_state);
    }

}
