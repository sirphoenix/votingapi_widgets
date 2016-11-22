<?php

namespace Drupal\votingapi_widgets\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\field\Entity\FieldConfig;

/**
 * Base class for Voting api widget plugins.
 */
abstract class VotingApiWidgetBase extends PluginBase implements VotingApiWidgetInterface {

  /**
   * Return label.
   */
  public function getLabel() {
    return $this->label;
  }

  /**
   * Return minimal value.
   */
  public function getValues() {
    return $this->getPluginDefinition()['values'];
  }

  /**
   * Get results.
   */
  public function getForm($entity_type, $entity_bundle, $entity_id, $vote_type, $field_name, $style, $show_results, $read_only) {
    $vote = $this->getEntityForVoting($entity_type, $entity_bundle, $entity_id, $vote_type, $field_name);
    return \Drupal::service('entity.form_builder')->getForm($vote, 'votingapi_' . $this->getPluginId(), [
      'read_only' => $read_only,
      'options' => $this->getPluginDefinition()['values'],
      'style' => $style,
      'show_results' => $show_results,
      'plugin' => $this,
    ]);
  }

  /**
   * Get results.
   */
  public function canVote($vote, $account = FALSE) {
    if (!$account) {
      $account = \Drupal::currentUser();
    }
    $entity = \Drupal::service('entity.manager')
      ->getStorage($vote->getVotedEntityType())
      ->load($vote->getVotedEntityId());

    if (!$entity) {
      return FALSE;
    }

    $perm = 'vote on ' . $vote->getVotedEntityType() . ':' . $entity->getType() . ':' . $vote->field_name->value;
    if (!$vote->isNew()) {
      $perm = 'edit own vote on ' . $vote->getVotedEntityType() . ':' . $entity->getType() . ':' . $vote->field_name->value;
    }
    return $account->hasPermission($perm);
  }

  /**
   * Get results.
   */
  public function getEntityForVoting($entity_type, $entity_bundle, $entity_id, $vote_type, $field_name) {
    $storage = \Drupal::service('entity.manager')->getStorage('vote');
    $currentUser = \Drupal::currentUser();
    $voteData = [
      'entity_type' => $entity_type,
      'entity_id'   => $entity_id,
      'type'      => $vote_type,
      'field_name'  => $field_name,
      'user_id' => $currentUser->id(),
    ];
    $vote = $storage->create($voteData);

    if ($currentUser->isAnonymous()) {
      $voteData['vote_source'] = \Drupal::service('request_stack')->getCurrentRequest()->getClientIp();
    }

    $query = \Drupal::entityQuery('vote');
    foreach ($voteData as $key => $value) {
      $query->condition($key, $value);
    }

    $timestamp_offset = $this->getWindow('user_window', $entity_type, $entity_bundle, $field_name);
    if (isset($voteData['vote_source'])) {
      // Anonymous vote.
      $timestamp_offset = $this->getWindow('anonymous_window', $entity_type, $entity_bundle, $field_name);
    }

    if ($timestamp_offset == -1) {

    }

    // Check for rollover 'never' setting.
    if (!empty($timestamp_offset) && $timestamp_offset != -1) {
      $query->condition('timestamp', time() - $timestamp_offset, '>=');
    }

    $votes = $query->execute();
    if ($votes && count($votes) > 0) {
      $vote = $storage->load(array_shift($votes));
    }

    return $vote;
  }

  /**
   * Get results.
   */
  public function getResults($entity, $result_function = FALSE, $reset = FALSE) {
    if ($reset) {
      drupal_static_reset(__FUNCTION__);
    }
    $resultCache = &drupal_static(__FUNCTION__);
    if (!$resultCache) {
      $resultCache = \Drupal::service('plugin.manager.votingapi.resultfunction')->getResults($entity->getVotedEntityType(), $entity->getVotedEntityId());
    }

    if ($result_function) {
      if (!$resultCache[$entity->getEntityTypeId()][$entity->getVotedEntityId()][$result_function]) {
        return [];
      }
      return $resultCache[$entity->getEntityTypeId()][$entity->getVotedEntityId()][$result_function];
    }

    if (!$result_function) {
      if (!isset($resultCache[$entity->getEntityTypeId()][$entity->getVotedEntityId()])) {
        return [];
      }
      return $resultCache[$entity->getEntityTypeId()][$entity->getVotedEntityId()];
    }
    return [];
  }

  public function getWindow($window_type, $entity_type_id, $entity_bundle, $field_name) {
    $config = FieldConfig::loadByName($entity_type_id, $entity_bundle, $field_name);

    $window_field_setting = $config->get($window_type);
    $use_site_default = FALSE;

    if ($window_field_setting === NULL || $window_field_setting === -1) {
      $use_site_default = TRUE;
    }

    $window = $window_field_setting;
    if ($use_site_default) {
      /*
       * @var \Drupal\Core\Config\ImmutableConfig $voting_configuration
       */
      $voting_configuration = \Drupal::config('votingapi.settings');
      $window = $voting_configuration->get($window_type);
    }

    return $window;
  }

  /**
   * Generate summary.
   */
  abstract public function getVoteSummary($form, FormStateInterface $form_state, ContentEntityInterface $vote);

}
