<?php

namespace Drupal\ymca_google;

use Drupal\Component\Utility\Timer;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ymca_groupex\DrupalProxy;
use Drupal\ymca_mappings\Entity\Mapping;

/**
 * Class GooglePush.
 *
 * @package Drupal\ymca_google
 */
class GooglePush {

  /**
   * Wrapper to be used.
   *
   * @var GcalGroupexWrapperInterface
   */
  protected $dataWrapper;

  /**
   * Config Factory.
   *
   * @var ConfigFactory
   */
  protected $configFactory;

  /**
   * ID for Google Calendar.
   *
   * @var string
   */
  protected $calendarId;

  /**
   * Google Calendar Service.
   *
   * @var \Google_Service_Calendar
   */
  protected $calService;

  /**
   * Google Calendar Events Service.
   *
   * @var \Google_Service_Calendar_Events_Resource
   */
  protected $calEvents;

  /**
   * Google Client.
   *
   * @var \Google_Client
   */
  protected $googleClient;

  /**
   * Logger channel.
   *
   * @var LoggerChannelInterface
   */
  protected $logger;

  /**
   * Entity type manager.
   *
   * @var EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Proxy.
   *
   * @var DrupalProxy
   */
  protected $proxy;

  /**
   * GooglePush constructor.
   *
   * @param GcalGroupexWrapperInterface $data_wrapper
   *   Data wrapper.
   * @param ConfigFactory $config_factory
   *   Config Factory.
   * @param LoggerChannelFactoryInterface $logger
   *   Logger.
   * @param EntityTypeManager $entity_type_manager
   *   Entity type manager.
   * @param DrupalProxy $proxy
   *   Proxy.
   */
  public function __construct(GcalGroupexWrapperInterface $data_wrapper, ConfigFactory $config_factory, LoggerChannelFactoryInterface $logger, EntityTypeManager $entity_type_manager, DrupalProxy $proxy) {
    $this->dataWrapper = $data_wrapper;
    $this->configFactory = $config_factory;
    $this->logger = $logger->get('gcal_groupex');
    $this->entityTypeManager = $entity_type_manager;
    $this->proxy = $proxy;

    $settings = $this->configFactory->get('ymca_google.settings');
    $this->calendarId = $settings->get('calendar_id');

    // Get the API client and construct the service object.
    $this->googleClient = $this->getClient();
    $this->calService = new \Google_Service_Calendar($this->googleClient);
    $this->calEvents = $this->calService->events;
  }

  /**
   * Clear calendar method. Only primary can be cleared here.
   */
  public function clear() {
    if ($this->calendarId != 'primary') {
      return;
    }
    $this->calService->calendars->clear($this->calendarId);
  }

  /**
   * Proceed all events collected by add methods.
   */
  public function proceed() {
    $data = $this->dataWrapper->getProxyData();

    foreach ($data as $op => $entities) {
      Timer::start($op);
      $exceptions[$op] = 0;

      foreach ($entities as $entity) {

        // Refresh the token if it's expired.
        if ($this->googleClient->isAccessTokenExpired()) {
          $this->logger->info('Token is expired. Refreshing...');

          $this->googleClient->refreshToken($this->googleClient->getRefreshToken());
          $editable = $this->configFactory->getEditable('ymca_google.token');
          $editable->set('credentials', json_decode($this->googleClient->getAccessToken(), TRUE));
          $editable->save();
        }

        switch ($op) {
          case 'update':
            $event = $this->drupalEntityToGcalEvent($entity);
            if (!$event) {
              break;
            }

            try {
              $this->calEvents->update(
                $this->calendarId,
                $entity->field_gcal_id->value,
                $event
              );

              $this->logger->info(
                'Groupex event (%id) has been updated.',
                ['%id' => $entity->field_groupex_class_id->value]
              );
            }
            catch (\Exception $e) {
              $exceptions[$op]++;
              $msg = '%type : Error while updating event for entity [%id]: %msg';
              $this->logger->error($msg, [
                '%type' => get_class($e),
                '%id' => $entity->id(),
                '%msg' => $e->getMessage(),
              ]);
            }

            break;

          case 'delete':
            try {
              $this->calEvents->delete(
                $this->calendarId,
                $entity->field_gcal_id->value
              );

              $groupex_id = $entity->field_groupex_class_id->value;
              $storage = $this->entityTypeManager->getStorage('mapping');
              $storage->delete([$entity]);

              $this->logger->info(
                'Groupex event (%id) has been deleted.', ['%id' => $groupex_id]
              );
            }
            catch (\Exception $e) {
              $exceptions[$op]++;
              $msg = 'Error while deleting event for entity [%id]: %msg';
              $this->logger->error($msg, [
                '%id' => $entity->id(),
                '%msg' => $e->getMessage(),
              ]);
            }

            break;

          case 'insert':
            $event = $this->drupalEntityToGcalEvent($entity);
            if (!$event) {
              break;
            }

            try {
              $event = $this->calEvents->insert($this->calendarId, $event);

              $entity->set('field_gcal_id', $event->getId());
              $entity->save();
            }
            catch (\Exception $e) {
              $exceptions[$op]++;
              $msg = 'Error while inserting event for entity [%id]: %msg';
              $this->logger->error($msg, [
                '%id' => $entity->id(),
                '%msg' => $e->getMessage(),
              ]);
            }

            break;
        }

      }

      // Log performance.
      $schedule = $this->dataWrapper->getSchedule();
      $timeZone = new \DateTimeZone('UTC');
      $current = $schedule['current'];

      $startDateTime = DrupalDateTime::createFromTimestamp($schedule['steps'][$current]['start'], $timeZone);
      $startDate = $startDateTime->format('c');

      $endDateTime = DrupalDateTime::createFromTimestamp($schedule['steps'][$current]['end'], $timeZone);
      $endDate = $endDateTime->format('c');

      $message = 'Stats: operation - %op, items - %items, exceptions - %exceptions, time - %time. Time frame: %start - %end. Source data: %source. Processed - %processed.';
      $this->logger->info(
        $message,
        [
          '%op' => $op,
          '%items' => count($data[$op]),
          '%time' => Timer::read($op),
          '%start' => $startDate,
          '%end' => $endDate,
          '%source' => count($this->dataWrapper->getSourceData()),
          '%processed' => count($data['delete']) + count($data['update']) + count($data['insert']),
          '%exceptions' => $exceptions[$op]
        ]
      );
      Timer::stop($op);
    }

    // Mark this step as done in the schedule.
    $this->dataWrapper->next();

  }

  /**
   * Convert mapping entity to an event.
   *
   * @param Mapping $entity
   *   Mapping entity.
   *
   * @return \Google_Service_Calendar_Event
   *   Event.
   */
  private function drupalEntityToGcalEvent(Mapping $entity) {
    $groupex_id = $entity->field_groupex_class_id->value;

    $field_date = $entity->get('field_groupex_date');
    $list_date = $field_date->getValue();

    $description = '';
    $instructor = '';
    $default = trim($entity->field_groupex_instructor->value);
    if (empty($default)) {
      $sub_instructor = trim($entity->field_groupex_sub_instructor->value);
      if (empty($sub_instructor)) {
        $original_instructor = trim($entity->field_groupex_orig_instructor->value);
        if (!empty($original_instructor)) {
          $instructor = $original_instructor;
        }
      }
      else {
        $instructor = $sub_instructor;
      }
    }
    else {
      $instructor = $default;
    }

    if (!empty($instructor)) {
      $description = 'Instructor: ' . $instructor . "\n\n";
    }

    $description .= strip_tags(trim(html_entity_decode($entity->field_groupex_description->value)));
    $location = trim($entity->field_groupex_location->value);
    $summary = trim($entity->field_groupex_title->value);
    $start = $entity->field_timestamp_start->value;
    $end = $entity->field_timestamp_end->value;

    $timezone = new \DateTimeZone('UTC');
    $startDateTime = DrupalDateTime::createFromTimestamp($start, $timezone);
    $endDateTime = DrupalDateTime::createFromTimestamp($end, $timezone);

    $event = new \Google_Service_Calendar_Event([
      'summary' => $summary,
      'location' => $location,
      'description' => $description,
      'start' => [
        'dateTime' => $startDateTime->format(DATETIME_DATETIME_STORAGE_FORMAT),
        'timeZone' => 'UTC',
      ],
      'end' => [
        'dateTime' => $endDateTime->format(DATETIME_DATETIME_STORAGE_FORMAT),
        'timeZone' => 'UTC',
      ],
    ]);

    // Add logic for recurring events.
    if (count($list_date) > 1) {
      $this->logger->info('Recurring Groupex event [%id]', ['%id' => $groupex_id]);

      $time = $entity->field_groupex_time->value;

      // Get start timestamps of all events.
      $timestamps = [];
      foreach ($list_date as $id => $item) {
        $stamps = $this->proxy->buildTimestamps($item['value'], $time);
        $timestamps[$id] = $stamps['start'];
      }

      sort($timestamps, SORT_NUMERIC);

      // Diff in Weeks.
      $diff = ($timestamps[1] - $timestamps[0]) / 604800;
      if (!is_int($diff)) {
        $this->logger->error('Got invalid interval %int for frequency for Groupex event %id', ['%int' => $diff, ['%id' => $groupex_id]]);
        return FALSE;
      }
      $count = $entity->get('field_groupex_date')->count();
      $this->logger->info('Found frequency %freq with count %count', ['%freq' => $diff, '%count' => $count]);

      $event['recurrence'] = [
        "RRULE:FREQ=WEEKLY;INTERVAL=$diff;COUNT=$count;"
      ];
    }

    return $event;
  }

  /**
   * Returns an authorized API client.
   *
   * @return \Google_Client
   *   The authorized client object
   *
   * @see https://developers.google.com/google-apps/calendar/quickstart/php
   */
  private function getClient() {
    $settings = $this->configFactory->get('ymca_google.settings');
    $token = $this->configFactory->get('ymca_google.token');

    $client = new \Google_Client();
    $client->setApplicationName($settings->get('application_name'));
    $client->setScopes(\Google_Service_Calendar::CALENDAR);
    $client->setAuthConfig(json_encode($settings->get('auth_config')));
    $client->setAccessToken(json_encode($token->get('credentials')));

    $client->setAccessType('offline');

    // Refresh the token if it's expired.
    if ($client->isAccessTokenExpired()) {
      $client->refreshToken($client->getRefreshToken());
      $editable = $this->configFactory->getEditable('ymca_google.token');
      $editable->set('credentials', json_decode($client->getAccessToken(), TRUE));
      $editable->save();
    }

    return $client;
  }

}
