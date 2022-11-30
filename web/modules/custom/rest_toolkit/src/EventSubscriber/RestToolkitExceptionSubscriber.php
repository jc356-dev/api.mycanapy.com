<?php

namespace Drupal\rest_toolkit\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class RestToolkitExceptionSubscriber.
 */
class RestToolkitExceptionSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new RestToolkitExceptionSubscriber object.
   */
  public function __construct() {

  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::EXCEPTION] = ['handleException'];

    return $events;
  }

  /**
   * This method is called when the KernelEvents::EXCEPTION is dispatched.
   *
   * @param \Symfony\Component\EventDispatcher\Event $event
   *   The dispatched event.
   */
  public function handleException(Event $event) {
    // Add header required for Axios to be able to intercept and provide
    // useful error messages.
    // Only needed for local ddev because FF servers add this per origin.
    // header('Access-Control-Allow-Origin: *');
    // \Drupal::messenger()->addMessage('Event KernelEvents::EXCEPTION thrown by Subscriber in module rest_toolkit.', 'status', TRUE);
  }

}
